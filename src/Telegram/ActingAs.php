<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\History;
use EventCrew\Support\Language;
use EventCrew\Support\Mailer;
use EventCrew\Support\TakeoverService;

/**
 * 👤 Act as (Owner 2026-11-11): from a person's card under /people, an
 * organizer sees that person's /me in the private chat, as the person sees
 * it, and can use their buttons: sign up, cancel, take over a task or answer
 * a takeover, get a free-entry ticket, give a ticket away, change their
 * language and notifications. Never their name, email, web sign-in, sign out
 * or deleting their data.
 *
 * Who is acted on is kept per organizer (a transient: the person's id and
 * the end time, ACTIVE_SECONDS after Act as or after the organizer's last
 * action while acting, Owner 2026-11-11). WARN_SECONDS before the end the
 * bot warns the organizer, with Keep acting and Stop acting; at the end it
 * says acting stopped. Both are WP-Cron single events (WARN_HOOK, END_HOOK)
 * with the organizer's Telegram id; each reads the state again, so a later
 * action only moves the end time. Each private update from the
 * organizer that is one of those screens or buttons (scope()) runs with
 * PersonRepository::standIn(), so every findByTelegramUserId() of the
 * organizer's Telegram id finds the person: the existing /me handlers act on
 * them with no handler of their own. Anything else the organizer does is
 * their own, as before.
 *
 * The organizer stays the one who did it: the history's context is the
 * Telegram sender (UpdateRouter::dispatch()), so each row names the
 * organizer as the actor (actor_kind organizer). Each row about the person
 * becomes a line of one DM to them, in their language, after the update.
 * The session keeps those changes (record()); when it ends (Stop acting,
 * the end time, Act as on someone else), the person gets one email listing
 * them, with who acted and when (mailSummary()).
 * The organizer is checked again at every update; Stop acting, or the end
 * time, ends it.
 */
final class ActingAs
{
    /** Callback data: act:<person_id> starts, act:stop ends. */
    public const PREFIX = 'act:';
    public const STOP = 'act:stop';

    /** Keep acting, under the warning: another ACTIVE_SECONDS. */
    public const KEEP = 'act:keep';

    /** How long acting lasts from the organizer's last action while acting. */
    public const ACTIVE_SECONDS = 15 * MINUTE_IN_SECONDS;

    /** The warning comes this long before the end. */
    public const WARN_SECONDS = 2 * MINUTE_IN_SECONDS;

    /** WP-Cron: the warning, and the end; the argument is the organizer's Telegram id. */
    public const WARN_HOOK = 'eventcrew_acting_warn';
    public const END_HOOK = 'eventcrew_acting_end';

    private const STATE_PREFIX = 'eventcrew_tg_acting_';

    /** At most this many changes are kept for the session's email. */
    private const MAX_CHANGES = 100;

    /** The state is kept a day, so a tap after the end time says it ended. */
    private const STATE_TTL = DAY_IN_SECONDS;

    /** Under the person's Settings: never touched while acting. */
    private const REFUSED = [
        MenuButtons::NAME,
        MenuButtons::EMAIL,
        MenuButtons::SIGN_OUT,
        MenuButtons::DELETE,
        MenuButtons::WEB,
    ];

    /** The person's own screens and actions, run as the person. */
    private const MENU = [
        MenuButtons::ME,
        MenuButtons::TICKETS,
        MenuButtons::SETTINGS,
        MenuButtons::BOARD,
        MenuButtons::TICKET,
        MenuButtons::REPLACE,
        MenuButtons::NOTIFICATIONS,
        MenuButtons::LANGUAGE,
        MenuButtons::CANCEL,
    ];

    /** Button prefixes of the person's actions. */
    private const PREFIXES = [
        BoardService::PICK_PREFIX,
        BoardService::CANCEL_ASK_PREFIX,
        'rep:',
        TakeoverService::CALLBACK_PREFIX,
        'tkt:',
        TransferBot::PREFIX,
        'np:',
        LanguageService::PREFIX,
    ];

    /** The commands that open the person's screens. */
    private const COMMANDS = ['me', 'tasks', 'tickets', 'settings', 'board'];

    /**
     * The update running as someone: the organizer's Telegram id, the
     * organizer, the person, and the rows recorded about the person.
     *
     * @var array{telegram: int, organizer: Person, subject: Person, rows: list<array<string, mixed>>}|null
     */
    private static ?array $scope = null;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly ProfileService $profile,
        private readonly TaskRepository $tasks = new TaskRepository(),
        private readonly ?Mailer $mailer = null
    ) {
    }

    /** The 👤 Act as row under a person's card (PermissionService). */
    public static function startRow(int $personId): array
    {
        return [['text' => '👤 ' . __('Act as', 'eventcrew'), 'callback_data' => self::PREFIX . $personId]];
    }

    /**
     * The Stop acting row, under each of the person's screens while acting.
     *
     * @return array<int, array{text: string, callback_data: string}>
     */
    public static function stopRow(): array
    {
        return [['text' => '⏹ ' . __('Stop acting', 'eventcrew'), 'callback_data' => self::STOP]];
    }

    /**
     * The person acted on in this update, when $telegramUserId is the
     * organizer acting: ProfileService puts the banner and Stop acting on
     * the screens then.
     */
    public static function subjectIn(int $telegramUserId): ?Person
    {
        return null !== self::$scope && self::$scope['telegram'] === $telegramUserId ? self::$scope['subject'] : null;
    }

    /** "Acting as Sam. Every change is logged under your name." */
    public static function banner(Person $subject): string
    {
        return '👤 ' . sprintf(
            /* translators: %s: the name of the person the organizer acts as */
            __('Acting as %s. Every change is logged under your name.', 'eventcrew'),
            $subject->name()
        );
    }

    /**
     * Routes one update: an Act as or Stop acting tap here; one of the
     * person's screens or actions while acting through $route as the person;
     * anything else through $route as it is. $awaiting: the organizer's next
     * plain message answers a question the person's flow asked (who to take
     * over from, who gets the ticket).
     *
     * @param array<string, mixed> $update
     * @param callable(): void $route
     */
    public function route(array $update, bool $awaiting, callable $route): void
    {
        $callback = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
        $data = null === $callback ? '' : (string) ($callback['data'] ?? '');

        if (null !== $callback && str_starts_with($data, self::PREFIX)) {
            $this->onTap($callback);

            return;
        }

        $sender = (int) ($callback['from']['id'] ?? $update['message']['from']['id'] ?? 0);
        $chat = null === $callback ? ($update['message']['chat'] ?? []) : ($callback['message']['chat'] ?? []);
        $state = $sender > 0 && 'private' === (is_array($chat) ? ($chat['type'] ?? '') : '')
            ? self::state($sender)
            : null;

        if (null === $state) {
            $route();

            return;
        }

        $inScope = self::inScope($update, $data, $awaiting);
        $pair = $this->pair($sender, $state);

        if (null === $pair) {
            // Ended (the time is up, or the organizer or the person is gone):
            // a tap on one of the person's buttons does nothing, so it is
            // never mistaken for the organizer's own.
            $this->stop($sender, $state);

            if (null !== $callback && $inScope) {
                $this->telegram->answerCallbackQuery(
                    (string) ($callback['id'] ?? ''),
                    __('Acting as someone has ended. Open their card under /people to start again.', 'eventcrew'),
                    true
                );

                return;
            }

            $route();

            return;
        }

        if (null !== $callback && in_array($data, self::REFUSED, true)) {
            $this->telegram->answerCallbackQuery(
                (string) ($callback['id'] ?? ''),
                sprintf(
                    /* translators: %s: the name of the person the organizer acts as */
                    __('Not while acting as %s: their name, email and sign-in stay theirs.', 'eventcrew'),
                    $pair[1]->name()
                ),
                true
            );

            return;
        }

        if (! $inScope) {
            $route();

            return;
        }

        // Each of the person's screens or actions moves the end time.
        $this->extend($sender, $state);
        $this->run($sender, $pair[0], $pair[1], $data, $route);
    }

    /**
     * Runs $route as $subject: the organizer's Telegram id finds them, and
     * each history row about them is kept for the DM sent after.
     *
     * @param callable(): void $route
     */
    public function run(int $telegramUserId, Person $organizer, Person $subject, string $data, callable $route): void
    {
        $outer = self::$scope;
        self::$scope = ['telegram' => $telegramUserId, 'organizer' => $organizer, 'subject' => $subject, 'rows' => []];
        PersonRepository::standIn($telegramUserId, $subject->id);
        History::observe(static function (array $row) use ($subject): void {
            if (null !== self::$scope && self::rowIsAbout($row, $subject->id)) {
                self::$scope['rows'][] = $row;
            }
        });

        try {
            $route();
            $rows = self::$scope['rows'] ?? [];
        } finally {
            History::observe(null);
            PersonRepository::standIn($telegramUserId, null);
            self::$scope = $outer;
        }

        self::record($telegramUserId, $subject->id, $rows, $data);
        $this->tell($organizer, $subject, $rows, $data);
    }

    /**
     * Act as (act:<id>) and Stop acting (act:stop).
     *
     * @param array<string, mixed> $callback
     */
    private function onTap(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $sender = (int) ($callback['from']['id'] ?? 0);
        $chatId = (int) ($callback['message']['chat']['id'] ?? $sender);
        $isPrivate = 'private' === ($callback['message']['chat']['type'] ?? 'private');
        $data = (string) ($callback['data'] ?? '');

        if (self::KEEP === $data) {
            $this->onKeep($callbackId, $sender);

            return;
        }

        $organizer = $sender > 0 ? $this->people->findByTelegramUserId($sender) : null;

        if (self::STOP === $data) {
            $state = self::state($sender);
            $this->stop($sender, $state);
            $this->telegram->answerCallbackQuery($callbackId);
            $subject = null === $state ? null : $this->people->find($state['person_id']);
            $this->telegram->sendMessage(
                $chatId,
                null === $subject
                    ? __('You are not acting as anyone.', 'eventcrew')
                    : sprintf(
                        /* translators: %s: the name of the person the organizer acted as */
                        __('Stopped acting as %s.', 'eventcrew'),
                        $subject->name()
                    ),
                null !== $organizer && $organizer->isOrganizer ? MenuButtons::back(MenuButtons::PEOPLE) : null
            );

            return;
        }

        if (null === $organizer || ! $organizer->isOrganizer || ! $isPrivate) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Only organizers can act as someone, in the private chat.', 'eventcrew'),
                true
            );

            return;
        }

        $personId = (int) substr($data, strlen(self::PREFIX));
        $subject = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $subject || $subject->isAnonymized() || ! $subject->isEmailVerified()) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That person has no confirmed account to act in.', 'eventcrew'),
                true
            );

            return;
        }

        // Act as on the same person while still acting goes on with the same
        // session; anyone else (or after the end) ends it, with its email.
        $old = self::state($sender);
        $same = null !== $old && $old['person_id'] === $subject->id && $old['until'] > time();

        if ($same) {
            $this->clearWarning($sender, $old['warning']);
            self::unschedule(self::END_HOOK, $sender);
        } else {
            $this->stop($sender, $old);
        }

        $until = time() + self::ACTIVE_SECONDS;
        self::save($sender, [
            'person_id' => $subject->id,
            'until' => $until,
            'warned' => false,
            'warning' => 0,
            'organizer_id' => $organizer->id,
            'started' => $same ? $old['started'] : time(),
            'changes' => $same ? $old['changes'] : [],
        ]);
        self::schedule(self::WARN_HOOK, $until - self::WARN_SECONDS, $sender);
        $this->telegram->answerCallbackQuery($callbackId);
        $this->run($sender, $organizer, $subject, MenuButtons::ME, function () use ($sender, $chatId): void {
            $this->profile->onMe($sender, $chatId);
        });
    }

    /**
     * The organizer and the person while acting is still on: the end time is
     * not past, the sender is still an organizer, and the person is still
     * there. null otherwise.
     *
     * @param array<string, mixed> $state
     * @return array{0: Person, 1: Person}|null
     */
    private function pair(int $sender, array $state): ?array
    {
        if ($state['until'] <= time()) {
            return null;
        }

        $organizer = $this->people->findByTelegramUserId($sender);

        if (null === $organizer || ! $organizer->isOrganizer) {
            return null;
        }

        $subject = $this->people->find($state['person_id']);

        if (null === $subject || $subject->isAnonymized() || ! $subject->isEmailVerified()) {
            return null;
        }

        return [$organizer, $subject];
    }

    /**
     * Whom $telegramUserId acts as and until when, ended or not, whether
     * the warning went and its message id, who started it and when, and the
     * session's changes for the email (record()); null when they never
     * started or stopped.
     *
     * @return array{person_id: int, until: int, warned: bool, warning: int, organizer_id: int, started: int,
     *     changes: list<array<string, mixed>>}|null
     */
    private static function state(int $telegramUserId): ?array
    {
        $state = get_transient(self::STATE_PREFIX . $telegramUserId);

        if (! is_array($state) || (int) ($state['person_id'] ?? 0) <= 0) {
            return null;
        }

        return [
            'person_id' => (int) $state['person_id'],
            'until' => (int) ($state['until'] ?? 0),
            'warned' => (bool) ($state['warned'] ?? false),
            'warning' => (int) ($state['warning'] ?? 0),
            'organizer_id' => (int) ($state['organizer_id'] ?? 0),
            'started' => (int) ($state['started'] ?? 0),
            'changes' => is_array($state['changes'] ?? null) ? array_values($state['changes']) : [],
        ];
    }

    /**
     * Keeps one update's changes for the session's email: the history rows
     * about the person, and a language or notification change (data).
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function record(int $telegramUserId, int $subjectId, array $rows, string $data): void
    {
        $state = self::state($telegramUserId);

        if (null === $state || $state['person_id'] !== $subjectId) {
            return;
        }

        $keys = array_flip(['kind', 'person_id', 'other_person_id', 'task_id', 'event_date']);
        $changes = [];

        foreach ($rows as $row) {
            $changes[] = array_intersect_key($row, $keys);
        }

        if (str_starts_with($data, LanguageService::PREFIX)) {
            $changes[] = ['kind' => 'language'];
        } elseif (str_starts_with($data, 'np:')) {
            $changes[] = ['kind' => 'notifications'];
        }

        if ([] === $changes) {
            return;
        }

        $state['changes'] = array_slice(array_merge($state['changes'], $changes), -self::MAX_CHANGES);
        self::save($telegramUserId, $state);
    }

    /**
     * The session's email to the person (Owner 2026-11-11): who acted, when
     * it started and ended, and a line for each change, the lines of the
     * DMs. Nothing when nothing changed, or without a confirmed email.
     *
     * @param array<string, mixed> $state
     */
    private function mailSummary(array $state): void
    {
        if ([] === $state['changes'] || null === $this->mailer) {
            return;
        }

        $subject = $this->people->find($state['person_id']);
        $organizer = $state['organizer_id'] > 0 ? $this->people->find($state['organizer_id']) : null;

        if (null === $subject || null === $organizer || $subject->isAnonymized() || ! $subject->isEmailVerified()) {
            return;
        }

        $ended = min(time(), $state['until']);
        $zone = function_exists('wp_timezone') ? wp_timezone() : null;

        Language::forPerson($subject, function () use ($state, $subject, $organizer, $ended, $zone): void {
            $lines = [];

            foreach ($state['changes'] as $change) {
                $line = $this->changeLine($change, $organizer->name(), $subject->id);

                if ('' !== $line) {
                    $lines[] = '- ' . $line;
                }
            }

            if ([] === $lines) {
                return;
            }

            $body = sprintf(
                /* translators: 1: the organizer's name, 2: start, 3: end, each like "Sat 1 Aug 18:00" */
                __('%1$s acted for you in the Telegram bot from %2$s to %3$s and made these changes:', 'eventcrew'),
                $organizer->name(),
                Dates::dayTimeLabel($state['started'] > 0 ? $state['started'] : $ended, $zone),
                Dates::dayTimeLabel($ended, $zone)
            ) . "\n\n" . implode("\n", $lines);

            $this->mailer->toPerson(
                $subject->id,
                $subject->email,
                sprintf(
                    /* translators: %s: the organizer's name */
                    __('%s made changes for you', 'eventcrew'),
                    $organizer->name()
                ),
                $body
            );
        });
    }

    /**
     * @param array<string, mixed> $change
     */
    private function changeLine(array $change, string $organizer, int $subjectId): string
    {
        $kind = (string) ($change['kind'] ?? '');

        if ('language' === $kind) {
            return sprintf(
                /* translators: %s: the organizer's name */
                __('%s changed your language.', 'eventcrew'),
                $organizer
            );
        }

        if ('notifications' === $kind) {
            return sprintf(
                /* translators: %s: the organizer's name */
                __('%s changed your notifications.', 'eventcrew'),
                $organizer
            );
        }

        return $this->line($change, $organizer, $subjectId);
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function save(int $telegramUserId, array $state): void
    {
        set_transient(self::STATE_PREFIX . $telegramUserId, $state, self::STATE_TTL);
    }

    /**
     * Another ACTIVE_SECONDS from now. After the warning, its buttons go and
     * a new warning is due; before it, the warning event moves itself
     * (onWarnDue()).
     *
     * @param array<string, mixed> $state
     */
    private function extend(int $telegramUserId, array $state): void
    {
        $state['until'] = time() + self::ACTIVE_SECONDS;

        if ($state['warned']) {
            $this->clearWarning($telegramUserId, $state['warning']);
            $state['warned'] = false;
            $state['warning'] = 0;
            self::unschedule(self::END_HOOK, $telegramUserId);
            self::schedule(self::WARN_HOOK, $state['until'] - self::WARN_SECONDS, $telegramUserId);
        }

        self::save($telegramUserId, $state);
    }

    /**
     * Ends acting: the state, the events, and the warning's buttons; the
     * person gets the session's email.
     *
     * @param array<string, mixed>|null $state
     */
    private function stop(int $telegramUserId, ?array $state): void
    {
        delete_transient(self::STATE_PREFIX . $telegramUserId);
        self::unschedule(self::WARN_HOOK, $telegramUserId);
        self::unschedule(self::END_HOOK, $telegramUserId);

        if (null !== $state) {
            $this->clearWarning($telegramUserId, $state['warning']);
            $this->mailSummary($state);
        }
    }

    /** The warning's Keep acting and Stop acting go, once it no longer applies. */
    private function clearWarning(int $telegramUserId, int $messageId): void
    {
        if ($messageId > 0) {
            $this->telegram->editMessageReplyMarkup($telegramUserId, $messageId, null);
        }
    }

    /** One single event at $at, in place of any of that hook for the organizer. */
    private static function schedule(string $hook, int $at, int $telegramUserId): void
    {
        self::unschedule($hook, $telegramUserId);

        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event($at, $hook, [$telegramUserId]);
        }
    }

    private static function unschedule(string $hook, int $telegramUserId): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($hook, [$telegramUserId]);
        }
    }

    /**
     * WARN_HOOK: "Acting as Sam ends in 2 minutes." with Keep acting and
     * Stop acting, in the organizer's private chat. Nothing when acting
     * already ended or the warning went; a later end time (an action since)
     * moves the event there.
     */
    public function onWarnDue(int $telegramUserId): void
    {
        $state = self::state($telegramUserId);

        if (null === $state || $state['warned']) {
            return;
        }

        $warnAt = $state['until'] - self::WARN_SECONDS;

        // Acted since: the warning moves with the end time. A minute of
        // slack, since WP-Cron runs about once a minute (the pinger).
        if ($warnAt > time() + MINUTE_IN_SECONDS) {
            self::schedule(self::WARN_HOOK, $warnAt, $telegramUserId);

            return;
        }

        if ($state['until'] <= time()) {
            $this->onEndDue($telegramUserId);

            return;
        }

        $pair = $this->pair($telegramUserId, $state);

        if (null === $pair) {
            $this->stop($telegramUserId, $state);

            return;
        }

        [$organizer, $subject] = $pair;
        $sent = Language::forPerson($organizer, fn (): ?array => $this->telegram->record()->sendMessage(
            $telegramUserId,
            '⏳ ' . sprintf(
                /* translators: %s: the name of the person the organizer acts as */
                __('Acting as %s ends in 2 minutes.', 'eventcrew'),
                $subject->name()
            ),
            ['inline_keyboard' => [
                [['text' => '▶️ ' . __('Keep acting', 'eventcrew'), 'callback_data' => self::KEEP]],
                self::stopRow(),
            ]]
        ));

        $state['warned'] = true;
        $state['warning'] = (int) (is_array($sent) ? ($sent['message_id'] ?? 0) : 0);
        self::save($telegramUserId, $state);
        self::schedule(self::END_HOOK, $state['until'], $telegramUserId);
    }

    /**
     * END_HOOK: when the end time is past, ends acting and says so, with
     * Back to People. Nothing when acting already ended or an action since
     * moved the end time.
     */
    public function onEndDue(int $telegramUserId): void
    {
        $state = self::state($telegramUserId);

        if (null === $state || $state['until'] > time()) {
            return;
        }

        $this->stop($telegramUserId, $state);
        $organizer = $this->people->findByTelegramUserId($telegramUserId);
        $subject = $this->people->find($state['person_id']);

        if (null === $organizer || null === $subject) {
            return;
        }

        Language::forPerson($organizer, fn (): ?array => $this->telegram->sendMessage(
            $telegramUserId,
            sprintf(
                /* translators: %s: the name of the person the organizer acted as */
                __('Stopped acting as %s: 15 minutes without an action.', 'eventcrew'),
                $subject->name()
            ),
            $organizer->isOrganizer ? MenuButtons::back(MenuButtons::PEOPLE) : null
        ));
    }

    /**
     * Keep acting, under the warning: another ACTIVE_SECONDS, a short
     * answer, and the warning's buttons go.
     */
    private function onKeep(string $callbackId, int $sender): void
    {
        $state = self::state($sender);
        $pair = null === $state ? null : $this->pair($sender, $state);

        if (null === $state || null === $pair) {
            $this->stop($sender, $state);
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Acting as someone has ended. Open their card under /people to start again.', 'eventcrew'),
                true
            );

            return;
        }

        $this->extend($sender, $state);
        $this->telegram->answerCallbackQuery($callbackId, sprintf(
            /* translators: %s: the name of the person the organizer acts as */
            __('Still acting as %s.', 'eventcrew'),
            $pair[1]->name()
        ));
    }

    /**
     * True for the person's screens and actions: their menu buttons and
     * pages, the board and task buttons, takeovers, tickets, giving a ticket,
     * notifications and language; the commands of their screens; and a plain
     * message answering their flow's question.
     *
     * @param array<string, mixed> $update
     */
    private static function inScope(array $update, string $data, bool $awaiting): bool
    {
        if (isset($update['callback_query'])) {
            if (in_array($data, self::MENU, true) || null !== MenuButtons::parsePage($data)) {
                return true;
            }

            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with($data, $prefix)) {
                    return true;
                }
            }

            // A task button on a board: t:/j:/l:<id>.
            return 1 === preg_match('/^[jlt]:\d+(?::\d{4}-\d{2}-\d{2})?$/', $data);
        }

        $text = trim((string) ($update['message']['text'] ?? ''));

        if ('' === $text) {
            return false;
        }

        if (! str_starts_with($text, '/')) {
            return $awaiting;
        }

        return 1 === preg_match('#^/(' . implode('|', self::COMMANDS) . ')(@\w+)?(\s|$)#i', $text);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowIsAbout(array $row, int $personId): bool
    {
        return $personId === (int) ($row['person_id'] ?? 0) || $personId === (int) ($row['other_person_id'] ?? 0);
    }

    /**
     * One DM to the person with a line for each change: the history rows
     * about them, and a language or notification change (not in the
     * history). Nothing when nothing changed, when the person has no
     * Telegram, or when the organizer acted as themselves.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function tell(Person $organizer, Person $subject, array $rows, string $data): void
    {
        if (null === $subject->telegramUserId || $subject->id === $organizer->id) {
            return;
        }

        $lines = Language::forPerson($subject, function () use ($organizer, $subject, $rows, $data): array {
            $lines = [];

            foreach ($rows as $row) {
                $line = $this->line($row, $organizer->name(), $subject->id);

                if ('' !== $line) {
                    $lines[] = $line;
                }
            }

            if (str_starts_with($data, LanguageService::PREFIX)) {
                $lines[] = sprintf(
                    /* translators: %s: the organizer's name */
                    __('%s changed your language.', 'eventcrew'),
                    $organizer->name()
                );
            } elseif (str_starts_with($data, 'np:')) {
                $lines[] = sprintf(
                    /* translators: %s: the organizer's name */
                    __('%s changed your notifications.', 'eventcrew'),
                    $organizer->name()
                );
            }

            return array_values(array_unique($lines));
        });

        if ([] === $lines) {
            return;
        }

        $this->telegram->record()->sendMessage($subject->telegramUserId, implode("\n", $lines));
    }

    /**
     * One history row about the person as a line of their DM, like "Lou
     * signed you up for Decorate on Fri 14 Nov."; '' for a row that is not
     * one of the person's own actions.
     *
     * @param array<string, mixed> $row
     */
    public function line(array $row, string $organizer, int $subjectId): string
    {
        $kind = (string) ($row['kind'] ?? '');
        $date = (string) ($row['event_date'] ?? '');
        $day = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Dates::dayLabel($date) : '';
        $taskId = (int) ($row['task_id'] ?? 0);
        $task = $taskId > 0 ? $this->tasks->find($taskId) : null;
        $role = null === $task ? __('a task', 'eventcrew') : $task->roleLabel();
        $isPerson = $subjectId === (int) ($row['person_id'] ?? 0);
        $otherId = $isPerson ? (int) ($row['other_person_id'] ?? 0) : (int) ($row['person_id'] ?? 0);
        $other = static function () use ($otherId): string {
            $person = $otherId > 0 ? (new PersonRepository())->find($otherId) : null;

            return null === $person ? __('someone', 'eventcrew') : $person->name();
        };

        switch ($kind) {
            case History::SIGNED_UP:
            case History::ASSIGNED:
                return sprintf(
                    /* translators: 1: the organizer's name, 2: a role, like "Decorate", 3: a day, like "Fri 14 Nov" */
                    __('%1$s signed you up for %2$s on %3$s.', 'eventcrew'),
                    $organizer,
                    $role,
                    $day
                );
            case History::CANCELLED:
            case History::LATE_CANCEL:
            case History::TAKEN_OFF:
                return sprintf(
                    /* translators: 1: the organizer's name, 2: a role, like "Decorate", 3: a day, like "Fri 14 Nov" */
                    __('%1$s cancelled your %2$s task on %3$s.', 'eventcrew'),
                    $organizer,
                    $role,
                    $day
                );
            case History::REPLACED:
                if ($isPerson) {
                    return sprintf(
                        /* translators: 1: the organizer's name, 2: a role, 3: a day, 4: who took the task */
                        __('%1$s gave your %2$s task on %3$s to %4$s.', 'eventcrew'),
                        $organizer,
                        $role,
                        $day,
                        $other()
                    );
                }

                return sprintf(
                    /* translators: 1: the organizer's name, 2: a role, 3: a day, 4: who had the task */
                    __('%1$s had you take over %2$s on %3$s from %4$s.', 'eventcrew'),
                    $organizer,
                    $role,
                    $day,
                    $other()
                );
            case History::CREDIT_REDEEMED:
                return sprintf(
                    /* translators: 1: the organizer's name, 2: a day, like "Fri 14 Nov" */
                    __('%1$s used one of your credits for free entry on %2$s.', 'eventcrew'),
                    $organizer,
                    $day
                );
            case History::REDEMPTION_CANCELLED:
                return sprintf(
                    /* translators: 1: the organizer's name, 2: a day, like "Fri 14 Nov" */
                    __('%1$s cancelled your free entry on %2$s.', 'eventcrew'),
                    $organizer,
                    $day
                );
            case History::TICKET_OFFERED:
            case History::TICKET_GIVEN:
                if (! $isPerson) {
                    return History::TICKET_GIVEN === $kind
                        ? sprintf(
                            /* translators: 1: the organizer's name, 2: who gave the ticket, 3: a day */
                            __('%1$s took the ticket %2$s gave you for %3$s.', 'eventcrew'),
                            $organizer,
                            $other(),
                            $day
                        )
                        : '';
                }

                if (History::TICKET_GIVEN === $kind) {
                    return sprintf(
                        /* translators: 1: the organizer's name, 2: a day, 3: who got the ticket */
                        __('%1$s gave your ticket for %2$s to %3$s.', 'eventcrew'),
                        $organizer,
                        $day,
                        $other()
                    );
                }

                return sprintf(
                    /* translators: 1: the organizer's name, 2: a day, 3: who the ticket is offered to */
                    __('%1$s offered your ticket for %2$s to %3$s.', 'eventcrew'),
                    $organizer,
                    $day,
                    $other()
                );
            case History::TICKET_OFFER_ENDED:
                return sprintf(
                    /* translators: 1: the organizer's name, 2: a day, like "Fri 14 Nov" */
                    __('%1$s cancelled the offer of your ticket for %2$s.', 'eventcrew'),
                    $organizer,
                    $day
                );
        }

        return '';
    }
}
