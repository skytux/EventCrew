<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\History;
use EventCrew\Support\Language;
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
 * the end time, ACTIVE_SECONDS after Act as). Each private update from the
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
 * The organizer is checked again at every update; Stop acting, or the end
 * time, ends it.
 */
final class ActingAs
{
    /** Callback data: act:<person_id> starts, act:stop ends. */
    public const PREFIX = 'act:';
    public const STOP = 'act:stop';

    /** How long acting lasts from the Act as tap. */
    public const ACTIVE_SECONDS = 15 * MINUTE_IN_SECONDS;

    private const STATE_PREFIX = 'eventcrew_tg_acting_';

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
        private readonly TaskRepository $tasks = new TaskRepository()
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
            delete_transient(self::STATE_PREFIX . $sender);

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
        $organizer = $sender > 0 ? $this->people->findByTelegramUserId($sender) : null;

        if (self::STOP === $data) {
            $state = self::state($sender);
            delete_transient(self::STATE_PREFIX . $sender);
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

        set_transient(
            self::STATE_PREFIX . $sender,
            ['person_id' => $subject->id, 'until' => time() + self::ACTIVE_SECONDS],
            self::STATE_TTL
        );
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
     * @param array{person_id: int, until: int} $state
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
     * Whom $telegramUserId acts as and until when, ended or not; null when
     * they never started or stopped.
     *
     * @return array{person_id: int, until: int}|null
     */
    private static function state(int $telegramUserId): ?array
    {
        $state = get_transient(self::STATE_PREFIX . $telegramUserId);

        if (! is_array($state) || (int) ($state['person_id'] ?? 0) <= 0) {
            return null;
        }

        return ['person_id' => (int) $state['person_id'], 'until' => (int) ($state['until'] ?? 0)];
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
