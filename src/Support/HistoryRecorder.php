<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\HistoryRepository;

/**
 * Writes the history rows (Support\History): the sink History::record()
 * sends each entry to once boot() has run.
 *
 * It fills in what the caller left out - the person, task and night of an
 * assignment, a credit ticket or a ticket given away; who acted, from the
 * request's context - and turns a status write or a person change into the
 * action it is: a cancellation by someone else is "taken off", a sign-up by
 * someone else "assigned", an organizer flag that flipped "organizer
 * changed". A change that changes nothing is not recorded.
 *
 * It also records which EventCrew settings an organizer saved in wp-admin:
 * the names of the options that changed in one admin-post request, as one
 * row. Never their values, so a token or password is only "changed".
 */
final class HistoryRecorder
{
    /** The most option names kept in one settings row; the rest are counted. */
    private const MAX_OPTIONS = 8;

    /**
     * Options that change on their own (timestamps, queues, caches, state):
     * not settings an organizer saves.
     */
    private const INTERNAL_OPTION = '/(_at|_queue|_purged|_last_run|_cache|_cached|_notice|_notices|_state|_lock'
        . '|_cursor|_since|_version|_hash|_seen|_sent|_log|_jobs|_job|_backup|_bak_.*|_count|_offset)$/';

    /** @var array<string, true> */
    private array $changedOptions = [];

    private bool $watching = false;

    /** @var array<int, bool> */
    private array $organizers = [];

    public function __construct(
        private readonly HistoryRepository $repository = new HistoryRepository()
    ) {
    }

    public function boot(): void
    {
        History::useSink([$this, 'handle']);
        add_action('admin_init', [$this, 'watchSettings']);
    }

    /**
     * Records one entry. Never throws: the history does not break the action.
     *
     * @param array<string, mixed> $entry
     */
    public function handle(array $entry): void
    {
        try {
            foreach ($this->rows($entry) as $row) {
                $this->repository->insert($row);
            }
        } catch (\Throwable) {
            // The history never breaks the action it records.
        }
    }

    /**
     * The rows one entry becomes: none (nothing changed, nothing known), one,
     * or two (a person whose organizer flag and roles both changed).
     *
     * @param array<string, mixed> $entry
     * @return list<array<string, mixed>>
     */
    public function rows(array $entry): array
    {
        $kind = (string) ($entry['kind'] ?? '');
        $row = [
            'created_at' => (string) current_time('mysql'),
            'person_id' => self::id($entry['person_id'] ?? null),
            'other_person_id' => self::id($entry['other_person_id'] ?? null),
            'task_id' => self::id($entry['task_id'] ?? null),
            'event_date' => self::date($entry['event_date'] ?? null),
            'source' => History::SOURCE_LIVE,
        ];
        $details = is_array($entry['details'] ?? null) ? $entry['details'] : [];

        $assignmentId = self::id($entry['assignment_id'] ?? null);

        if (null !== $assignmentId) {
            $assignment = $this->repository->assignment($assignmentId);

            if (null === $assignment) {
                return [];
            }

            $row['person_id'] ??= $assignment['person_id'];
            $row['task_id'] ??= $assignment['task_id'];
            $row['event_date'] ??= $assignment['event_date'];
        }

        $redemptionId = self::id($entry['redemption_id'] ?? null);

        if (null !== $redemptionId) {
            $redemption = $this->repository->redemption($redemptionId);

            if (null !== $redemption) {
                $row['person_id'] ??= $redemption['person_id'];
                $row['event_date'] ??= $redemption['event_date'];
            }
        }

        $transferId = self::id($entry['transfer_id'] ?? null);

        if (null !== $transferId) {
            $transfer = $this->repository->transfer($transferId);

            if (null !== $transfer) {
                $row['person_id'] ??= $transfer['person_id'];
                $row['other_person_id'] ??= $transfer['other_person_id'];
                $row['event_date'] ??= $transfer['event_date'];
            }
        }

        $task = null;

        if (null !== $row['task_id'] && (null === $row['event_date'] || History::TASK_CHANGED === $kind)) {
            $task = $this->repository->task($row['task_id']);
            $row['event_date'] ??= null === $task ? null : self::date($task['task_date'] ?? null);
        }

        $row += $this->actor($entry, $row['person_id']);

        // A person who erased themselves is not named as the one who did it.
        $erased = self::id($entry['erased_person_id'] ?? null);

        if (null !== $erased && $erased === $row['actor_person_id']) {
            $row['actor_kind'] = History::ACTOR_PERSON;
            $row['actor_person_id'] = null;
        }

        $kinds = [];

        switch ($kind) {
            case History::STATUS:
                $status = (string) ($entry['status'] ?? '');
                $byOther = self::byOther($row);
                $kinds[] = match ($status) {
                    AssignmentStatus::CANCELLED => $byOther ? History::TAKEN_OFF : History::CANCELLED,
                    AssignmentStatus::LATE_CANCEL => History::LATE_CANCEL,
                    AssignmentStatus::NO_SHOW => History::NO_SHOW,
                    AssignmentStatus::REPLACED => History::REPLACED,
                    default => History::MARKED,
                };

                if (AssignmentStatus::REPLACED === $status && History::ACTOR_WP_USER !== $row['actor_kind']) {
                    $row['other_person_id'] ??= $row['actor_person_id'];
                }

                if (History::MARKED === $kinds[0]) {
                    $details = ['status' => $status];
                }
                break;

            case History::SIGNED_UP:
                $kinds[] = self::byOther($row) ? History::ASSIGNED : History::SIGNED_UP;
                break;

            case History::TASK_CHANGED:
                $fields = $this->changedFields(
                    $task,
                    is_array($entry['fields'] ?? null) ? $entry['fields'] : []
                );

                if ([] === $fields) {
                    return [];
                }

                $details = ['fields' => $fields];
                $kinds[] = $kind;
                break;

            case History::PERSON_CHANGED:
                return $this->personChanges($row, $entry);

            default:
                if ('' === $kind) {
                    return [];
                }

                $kinds[] = $kind;
        }

        $rows = [];

        foreach ($kinds as $one) {
            $rows[] = ['kind' => $one, 'details' => self::details($details)] + $row;
        }

        return $rows;
    }

    /**
     * Who acted: the person or WordPress user the entry names, else the
     * request's context (the bot's sender, the crew page's person), else the
     * signed-in WordPress user, else the system. The actor kind is 'person'
     * for someone acting for themselves, 'organizer' for a person acting for
     * someone else who is an organizer (anyone else acting for someone, such
     * as the door crew, stays 'person').
     *
     * @param array<string, mixed> $entry
     * @return array{actor_kind: string, actor_person_id: ?int, actor_wp_user_id: ?int, channel: string}
     */
    private function actor(array $entry, ?int $subject): array
    {
        $person = self::id($entry['actor_person_id'] ?? null);
        $wpUser = self::id($entry['actor_wp_user_id'] ?? null);
        $context = History::context();
        $channel = (string) ($context['channel'] ?? '');

        if (null === $person && null === $wpUser && null !== $context) {
            $person = $context['person_id'];

            if (null === $person && null !== $context['telegram_user_id']) {
                $person = $this->repository->personForTelegram($context['telegram_user_id']);
            }
        }

        if (null === $person && null === $wpUser && function_exists('get_current_user_id')) {
            $wpUser = self::id(get_current_user_id());
        }

        if (null !== $wpUser && null === $person) {
            return [
                'actor_kind' => History::ACTOR_WP_USER,
                'actor_person_id' => null,
                'actor_wp_user_id' => $wpUser,
                'channel' => '' !== $channel ? $channel : History::CHANNEL_ADMIN,
            ];
        }

        if (null === $person) {
            return [
                'actor_kind' => History::ACTOR_SYSTEM,
                'actor_person_id' => null,
                'actor_wp_user_id' => null,
                'channel' => '' !== $channel ? $channel : History::CHANNEL_SYSTEM,
            ];
        }

        $kind = $person !== $subject && $this->isOrganizer($person)
            ? History::ACTOR_ORGANIZER
            : History::ACTOR_PERSON;

        return [
            'actor_kind' => $kind,
            'actor_person_id' => $person,
            'actor_wp_user_id' => null,
            'channel' => $channel,
        ];
    }

    private function isOrganizer(int $personId): bool
    {
        return $this->organizers[$personId] ??= $this->repository->isOrganizer($personId);
    }

    /**
     * True when someone other than the person acted on did it: a WordPress
     * user, or another person.
     *
     * @param array<string, mixed> $row
     */
    private static function byOther(array $row): bool
    {
        if (History::ACTOR_WP_USER === $row['actor_kind']) {
            return true;
        }

        return null !== $row['actor_person_id'] && $row['actor_person_id'] !== $row['person_id'];
    }

    /**
     * The fields an edit to a task changes, compared with the stored task.
     * Names only, never the new values (the notes are free text).
     *
     * @param array<string, mixed>|null $stored
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    private function changedFields(?array $stored, array $fields): array
    {
        $changed = [];

        foreach ($fields as $name => $value) {
            $name = (string) $name;

            if (1 !== preg_match('/^[a-z_]{1,32}$/', $name)) {
                continue;
            }

            if (null === $stored || ! array_key_exists($name, $stored) || (string) $stored[$name] !== (string) $value) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    /**
     * The rows a change to a person's organizer flag or roles makes, compared
     * with the stored person (read before the change is written).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $entry
     * @return list<array<string, mixed>>
     */
    private function personChanges(array $row, array $entry): array
    {
        $personId = $row['person_id'];

        if (null === $personId) {
            return [];
        }

        $stored = $this->repository->personRoles($personId);

        if (null === $stored) {
            return [];
        }

        $rows = [];

        if (array_key_exists('is_organizer', $entry)) {
            $organizer = ! empty($entry['is_organizer']);

            if ($organizer !== $stored['is_organizer']) {
                $rows[] = [
                    'kind' => History::ORGANIZER_CHANGED,
                    'details' => self::details(['organizer' => $organizer ? 1 : 0]),
                ] + $row;
            }
        }

        if (array_key_exists('role_slugs', $entry)) {
            $new = HistoryRepository::slugs((string) $entry['role_slugs']);
            $added = array_values(array_diff($new, $stored['role_slugs']));
            $removed = array_values(array_diff($stored['role_slugs'], $new));

            if ([] !== $added || [] !== $removed) {
                $rows[] = [
                    'kind' => History::ROLES_CHANGED,
                    'details' => self::details(array_filter(['added' => $added, 'removed' => $removed])),
                ] + $row;
            }
        }

        return $rows;
    }

    /**
     * Starts collecting changed EventCrew options for one admin-post request
     * that saves an EventCrew form (action eventcrew_*). Hooked on admin_init.
     */
    public function watchSettings(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which form is posting.
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : '';

        if ($this->watching || ! str_starts_with($action, 'eventcrew_') || ! self::isAdminPost()) {
            return;
        }

        $this->watching = true;
        add_action('updated_option', [$this, 'optionUpdated'], 10, 3);
        add_action('added_option', [$this, 'optionAdded'], 10, 2);
        add_action('shutdown', [$this, 'recordSettings']);
    }

    public function optionUpdated(string $option, mixed $old = null, mixed $new = null): void
    {
        if ($old !== $new) {
            $this->noteOption($option);
        }
    }

    public function optionAdded(string $option, mixed $value = null): void
    {
        $this->noteOption($option);
    }

    /** Whether an option is an EventCrew setting an organizer saves. */
    public static function isSetting(string $option): bool
    {
        return str_starts_with($option, 'eventcrew_')
            && 'eventcrew_' !== $option
            && 1 !== preg_match(self::INTERNAL_OPTION, $option);
    }

    private function noteOption(string $option): void
    {
        if (self::isSetting($option)) {
            $this->changedOptions[substr($option, strlen('eventcrew_'))] = true;
        }
    }

    /**
     * Writes one row with the names of the settings that changed in this
     * request, when any did. Hooked on shutdown, which also runs after the
     * redirect that ends a save.
     */
    public function recordSettings(): void
    {
        if ([] === $this->changedOptions) {
            return;
        }

        $names = array_keys($this->changedOptions);
        $this->changedOptions = [];
        $details = ['options' => array_slice($names, 0, self::MAX_OPTIONS)];

        if (count($names) > self::MAX_OPTIONS) {
            $details['more'] = count($names) - self::MAX_OPTIONS;
        }

        $this->handle(['kind' => History::SETTINGS_CHANGED, 'details' => $details]);
    }

    private static function isAdminPost(): bool
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';

        return str_ends_with($script, 'admin-post.php');
    }

    /**
     * The details as short JSON, cut to fit the column: whole list items are
     * dropped from the end until it fits.
     *
     * @param array<string, mixed> $details
     */
    public static function details(array $details): string
    {
        if ([] === $details) {
            return '';
        }

        $json = (string) wp_json_encode($details);

        while (strlen($json) > 191) {
            $cut = false;

            foreach ($details as $key => $value) {
                if (is_array($value) && count($value) > 1) {
                    array_pop($details[$key]);
                    $details['more'] = (int) ($details['more'] ?? 0) + 1;
                    $cut = true;
                    break;
                }
            }

            $json = (string) wp_json_encode($details);

            if (! $cut) {
                return substr($json, 0, 191);
            }
        }

        return $json;
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
