<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\HistoryRepository;

/**
 * The history of actions: who did what, and when, to which task, person or
 * night (the history table, schema 37). Organizers read it on the History
 * page in wp-admin and through the bot's "Current status".
 *
 * The code that changes something calls record() at the one place the change
 * is written (a repository method, mostly), so each action is recorded once
 * whichever screen, command or button started it. record() hands the entry to
 * the recorder (HistoryRecorder) that the kernel boots; with no recorder (the
 * unit tests, a request that has not booted) it does nothing. Recording never
 * breaks the action itself: the recorder catches every error.
 *
 * Who acted comes from the entry when the caller knows it (the changed_by of a
 * status change, the door session of a check-in), otherwise from the context
 * the request set: the bot sets the Telegram sender (actAs() in UpdateRouter),
 * the crew page the signed-in person (SessionPerson). With neither, a signed-in
 * WordPress user is the actor, and with nobody it was the system (WP-Cron).
 *
 * Rows are kept KEEP_DAYS days; the heartbeat deletes older ones once a day
 * (purgeIfDue()). An erased person's ids are emptied in every row at once
 * (HistoryRepository::forgetPerson()), so their rows stay as anonymous
 * actions. A row never holds free text someone typed, an email address or the
 * value of a setting: details holds only short keys, ids and counts.
 */
final class History
{
    /** Rows older than this are deleted (Owner, Q379: one year). */
    public const KEEP_DAYS = 365;

    /** When purgeIfDue() last deleted old rows, as a Unix time. */
    public const PURGED_OPTION = 'eventcrew_history_purged_at';

    // Tasks.
    public const SIGNED_UP = 'signed_up';
    public const ASSIGNED = 'assigned';
    public const CANCELLED = 'cancelled';
    public const LATE_CANCEL = 'late_cancel';
    public const TAKEN_OFF = 'taken_off';
    public const REPLACED = 'replaced';
    public const MARKED = 'marked';
    public const NO_SHOW = 'no_show';

    // The task list.
    public const TASK_CREATED = 'task_created';
    public const TASK_CHANGED = 'task_changed';
    public const TASK_DELETED = 'task_deleted';

    // The door.
    public const CHECKED_IN = 'checked_in';
    public const CHECKIN_UNDONE = 'checkin_undone';

    // Credits and tickets.
    public const CREDIT_GRANTED = 'credit_granted';
    public const CREDIT_REMOVED = 'credit_removed';
    public const CREDIT_REDEEMED = 'credit_redeemed';
    public const REDEMPTION_CANCELLED = 'redemption_cancelled';
    public const TICKET_OFFERED = 'ticket_offered';
    public const TICKET_GIVEN = 'ticket_given';
    public const TICKET_OFFER_ENDED = 'ticket_offer_ended';

    // People.
    public const PERSON_ADDED = 'person_added';
    public const ROLES_CHANGED = 'roles_changed';
    public const ORGANIZER_CHANGED = 'organizer_changed';
    public const PERSON_ERASED = 'person_erased';

    // Settings.
    public const SETTINGS_CHANGED = 'settings_changed';

    /**
     * What a caller records that the recorder turns into one of the kinds
     * above: a status write on an assignment (cancelled, no-show, marked...),
     * and a change to a person's organizer flag or roles.
     */
    public const STATUS = 'status';
    public const PERSON_CHANGED = 'person_changed';

    // Who acted.
    public const ACTOR_PERSON = 'person';
    public const ACTOR_ORGANIZER = 'organizer';
    public const ACTOR_WP_USER = 'wp_user';
    public const ACTOR_SYSTEM = 'system';

    // Where it was done.
    public const CHANNEL_BOT = 'bot';
    public const CHANNEL_WEB = 'web';
    public const CHANNEL_ADMIN = 'admin';
    public const CHANNEL_SYSTEM = 'system';

    /** The source of a row written as it happened, and of one the schema 37 update made from older data. */
    public const SOURCE_LIVE = '';
    public const SOURCE_BACKFILL = 'backfill';

    /** @var (callable(array<string, mixed>): void)|null */
    private static $sink = null;

    /** @var array{channel: string, person_id: ?int, telegram_user_id: ?int}|null */
    private static ?array $context = null;

    private static int $muted = 0;

    /** @var (callable(array<string, mixed>): void)|null */
    private static $observer = null;

    /**
     * Records one action. $entry holds the ids the caller has: person_id,
     * other_person_id, task_id, event_date, assignment_id, redemption_id,
     * transfer_id, details (short keys and numbers only) and, when the caller
     * knows who acted, actor_person_id or actor_wp_user_id.
     *
     * @param array<string, mixed> $entry
     */
    public static function record(string $kind, array $entry = []): void
    {
        if (self::$muted > 0 || null === self::$sink) {
            return;
        }

        try {
            (self::$sink)(['kind' => $kind] + $entry);
        } catch (\Throwable) {
            // The history never breaks the action it records.
        }
    }

    /**
     * Where record() sends entries: the recorder, set when it boots. null
     * turns recording off (the tests).
     *
     * @param (callable(array<string, mixed>): void)|null $sink
     */
    public static function useSink(?callable $sink): void
    {
        self::$sink = $sink;
    }

    /**
     * Who is acting in this request: the bot's sender (by Telegram user id,
     * matched to a person only when something is recorded) or the person
     * signed in on the crew page.
     */
    public static function actAs(string $channel, ?int $personId = null, ?int $telegramUserId = null): void
    {
        self::$context = [
            'channel' => $channel,
            'person_id' => null !== $personId && $personId > 0 ? $personId : null,
            'telegram_user_id' => null !== $telegramUserId && $telegramUserId > 0 ? $telegramUserId : null,
        ];
    }

    /**
     * @return array{channel: string, person_id: ?int, telegram_user_id: ?int}|null
     */
    public static function context(): ?array
    {
        return self::$context;
    }

    /** Forgets the sink, the context, the observer and any mute (between tests). */
    public static function reset(): void
    {
        self::$sink = null;
        self::$context = null;
        self::$muted = 0;
        self::$observer = null;
    }

    /**
     * Who is told of each row the recorder writes, with the row as written
     * (who it is about, the task and night filled in, the kind it became):
     * the bot's Act as, which DMs the person what was done for them
     * (Telegram\ActingAs). null stops it.
     *
     * @param (callable(array<string, mixed>): void)|null $observer
     */
    public static function observe(?callable $observer): void
    {
        self::$observer = $observer;
    }

    /**
     * The recorder wrote $row: tells the observer, if any. Never throws.
     *
     * @param array<string, mixed> $row
     */
    public static function observed(array $row): void
    {
        if (null === self::$observer) {
            return;
        }

        try {
            (self::$observer)($row);
        } catch (\Throwable) {
            // Telling someone never breaks the action.
        }
    }

    /**
     * Runs $callback with recording off, for a step that records one action
     * of its own in place of the several writes it makes (a takeover: the
     * holder's place becomes replaced and the cover joins).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function muted(callable $callback): mixed
    {
        ++self::$muted;

        try {
            return $callback();
        } finally {
            --self::$muted;
        }
    }

    /**
     * Deletes the rows older than KEEP_DAYS, at most once a day: the
     * heartbeat runs every hour and calls this each time. Returns how many
     * rows were deleted, or -1 when it was not due.
     */
    public static function purgeIfDue(
        HistoryRepository $repository = new HistoryRepository(),
        ?int $now = null
    ): int {
        $now ??= time();

        if (($now - (int) get_option(self::PURGED_OPTION, 0)) < DAY_IN_SECONDS) {
            return -1;
        }

        update_option(self::PURGED_OPTION, $now, false);

        return $repository->deleteBefore(self::cutoff((string) current_time('mysql')));
    }

    /** The site time before which rows are deleted: KEEP_DAYS before $siteNow ("Y-m-d H:i:s"). */
    public static function cutoff(string $siteNow): string
    {
        $at = strtotime($siteNow . ' UTC');

        return gmdate('Y-m-d H:i:s', (false === $at ? time() : $at) - self::KEEP_DAYS * DAY_IN_SECONDS);
    }

    /**
     * The filter groups of the History page, each with its kinds.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'tasks' => [
                self::SIGNED_UP, self::ASSIGNED, self::CANCELLED, self::LATE_CANCEL, self::TAKEN_OFF,
                self::REPLACED, self::MARKED, self::NO_SHOW,
            ],
            'tasklist' => [self::TASK_CREATED, self::TASK_CHANGED, self::TASK_DELETED],
            'door' => [self::CHECKED_IN, self::CHECKIN_UNDONE],
            'credits' => [
                self::CREDIT_GRANTED, self::CREDIT_REMOVED, self::CREDIT_REDEEMED, self::REDEMPTION_CANCELLED,
                self::TICKET_OFFERED, self::TICKET_GIVEN, self::TICKET_OFFER_ENDED,
            ],
            'people' => [self::PERSON_ADDED, self::ROLES_CHANGED, self::ORGANIZER_CHANGED, self::PERSON_ERASED],
            'settings' => [self::SETTINGS_CHANGED],
        ];
    }

    /** The label of a filter group. */
    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'tasks' => __('Task sign-ups', 'eventcrew'),
            'tasklist' => __('Task list', 'eventcrew'),
            'door' => __('Door', 'eventcrew'),
            'credits' => __('Credits and tickets', 'eventcrew'),
            'people' => __('People', 'eventcrew'),
            'settings' => __('Settings', 'eventcrew'),
            default => $group,
        };
    }
}
