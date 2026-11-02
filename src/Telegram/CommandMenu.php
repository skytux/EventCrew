<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\Language;
use EventCrew\Support\Roles;
use EventCrew\Support\SalesDeskGate;

/**
 * The bot's command menu (the list Telegram shows after typing "/"), so each
 * person sees only the commands they may use. Every setMyCommands and
 * deleteMyCommands call in the plugin is made here.
 *
 * Telegram picks the most specific list it has for a chat:
 * - group chats get /board and /help;
 * - private chats get the Everyone commands;
 * - organizers and welcome crew get a list of their own (scope "chat", their
 *   Telegram user id), the Everyone commands plus their extra ones.
 *
 * Each list is sent twice: once with no language (English), and once for
 * Finnish, which Telegram shows to people whose Telegram app is in Finnish.
 *
 * Who has a list of their own is kept in PEOPLE_OPTION, so a person who stops
 * qualifying has theirs deleted. The lists are refreshed when the webhook is
 * installed, once a day from the heartbeat, when someone's organizer status
 * changes, and when someone is marked arrived on a sales desk task.
 */
final class CommandMenu
{
    /** Person ids that have a personal command list in Telegram. */
    public const PEOPLE_OPTION = 'eventcrew_command_menu_people';

    /** When the personal lists were last refreshed for everyone (Unix time). */
    public const REFRESHED_OPTION = 'eventcrew_command_menu_refreshed_at';

    /** Fired with (int $personId) when a person's organizer status changes. */
    public const ORGANIZER_CHANGED_HOOK = 'eventcrew/organizer_changed';

    private const REFRESH_EVERY_SECONDS = DAY_IN_SECONDS;

    /** The Telegram language code of the second copy of each list. */
    private const FINNISH_CODE = 'fi';

    private const GROUP = ['board', 'help'];

    private const EVERYONE = ['board', 'me', 'help'];

    private const WELCOME_CREW = ['roster', 'door'];

    private const ORGANIZER = ['roster', 'door', 'people'];

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly DoorAccess $doorAccess,
        private readonly TelegramClient $telegram
    ) {
    }

    public function boot(): void
    {
        add_action(self::ORGANIZER_CHANGED_HOOK, [$this, 'refreshPerson']);
        add_action('eventcrew/assignment_status_changed', [$this, 'onStatusChanged'], 10, 2);
    }

    /**
     * Sends every list: the group and private-chat ones, then each personal
     * one. Called when the webhook is installed.
     */
    public function install(): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        $this->set(['type' => 'all_group_chats'], self::GROUP, false);
        $this->set(['type' => 'all_private_chats'], self::EVERYONE, false);
        $this->refreshAll();
    }

    /** The heartbeat's call: refreshAll() when the last one is a day old. */
    public function refreshIfDue(): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        if ((time() - (int) get_option(self::REFRESHED_OPTION, 0)) < self::REFRESH_EVERY_SECONDS) {
            return;
        }

        $this->refreshAll();
    }

    /**
     * Gives every organizer and every current welcome crew member their list,
     * and deletes the list of everyone who had one and no longer qualifies.
     */
    public function refreshAll(): void
    {
        update_option(self::REFRESHED_OPTION, time(), false);

        $dates = $this->doorAccess->nearbyDates();
        $candidates = [];

        foreach ($this->people->organizers() as $organizer) {
            $candidates[$organizer->id] = $organizer;
        }

        // Welcome crew hold a task on one of the nearby nights, so only the
        // people assigned on those nights need the full check.
        foreach ($dates as $date) {
            foreach ($this->assignments->personIdsAssignedOn($date) as $personId) {
                $candidates[$personId] ??= $this->people->find($personId);
            }
        }

        $kept = [];

        foreach ($candidates as $personId => $person) {
            if (null === $person || null === $person->telegramUserId) {
                continue;
            }

            $extra = $this->extraFor($person, $dates);

            if (null !== $extra) {
                $this->setFor($person, $extra);
                $kept[] = (int) $personId;
            }
        }

        foreach (array_diff($this->stored(), $kept) as $personId) {
            $person = $candidates[$personId] ?? $this->people->find($personId);

            if (null !== $person && null !== $person->telegramUserId) {
                $this->deleteFor($person);
            }
        }

        $this->store($kept);
    }

    /**
     * Sets or deletes one person's list, by whether they qualify now. The
     * ORGANIZER_CHANGED_HOOK listener.
     */
    public function refreshPerson(int $personId): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        $person = $this->people->find($personId);

        if (null === $person || null === $person->telegramUserId) {
            return;
        }

        $stored = $this->stored();
        $extra = $this->extraFor($person, null);

        if (null !== $extra) {
            $this->setFor($person, $extra);
            $this->store(array_merge($stored, [$personId]));

            return;
        }

        $this->deleteFor($person);
        $this->store(array_diff($stored, [$personId]));
    }

    /**
     * The eventcrew/assignment_status_changed listener: someone marked arrived
     * on a sales desk task gets the welcome crew commands now rather than at
     * the next daily refresh.
     */
    public function onStatusChanged(int $assignmentId, string $status): void
    {
        if (! SalesDeskGate::hasArrived($status)) {
            return;
        }

        $assignment = $this->assignments->find($assignmentId);
        $task = null === $assignment ? null : $this->tasks->find($assignment->taskId);

        if (null === $assignment || null === $task || ! Roles::opensSalesDesk($task->roleSlug)) {
            return;
        }

        $this->refreshPerson($assignment->personId);
    }

    /**
     * The commands one person gets on top of the Everyone ones; null when they
     * get no list of their own.
     *
     * @param array<int, string>|null $dates the nearby nights; null reads them
     * @return array<int, string>|null
     */
    private function extraFor(Person $person, ?array $dates): ?array
    {
        if ($person->isOrganizer) {
            return self::ORGANIZER;
        }

        return $this->doorAccess->isWelcomeCrew($person, $dates) ? self::WELCOME_CREW : null;
    }

    /**
     * @param array<int, string> $extra
     */
    private function setFor(Person $person, array $extra): void
    {
        $this->set(
            ['type' => 'chat', 'chat_id' => (int) $person->telegramUserId],
            array_merge(self::EVERYONE, $extra),
            true
        );
    }

    /**
     * A failure here is usually a person who never opened a private chat with
     * the bot, so it is logged quietly.
     */
    private function deleteFor(Person $person): void
    {
        $scope = ['type' => 'chat', 'chat_id' => (int) $person->telegramUserId];

        $this->telegram->deleteMyCommands($scope, '', true);
        $this->telegram->deleteMyCommands($scope, self::FINNISH_CODE, true);
    }

    /**
     * Sends one list in English (no language code) and in Finnish.
     *
     * @param array<string, mixed> $scope
     * @param array<int, string> $names
     */
    private function set(array $scope, array $names, bool $quiet): void
    {
        $english = Language::run(Language::ENGLISH, fn (): array => $this->commands($names));
        $finnish = Language::run(Language::FINNISH, fn (): array => $this->commands($names));

        $this->telegram->setMyCommands($english, $scope, '', $quiet);
        $this->telegram->setMyCommands($finnish, $scope, self::FINNISH_CODE, $quiet);
    }

    /**
     * @param array<int, string> $names
     * @return array<int, array{command: string, description: string}>
     */
    private function commands(array $names): array
    {
        $descriptions = $this->descriptions();

        return array_map(
            static fn (string $name): array => ['command' => $name, 'description' => $descriptions[$name]],
            $names
        );
    }

    /**
     * One line per command, in the current language. Telegram allows 3 to 256
     * characters.
     *
     * @return array<string, string>
     */
    private function descriptions(): array
    {
        return [
            'help' => __('The commands you can use', 'eventcrew'),
            'board' => __('Show the open tasks', 'eventcrew'),
            'me' => __('Your tasks, credits, ticket, settings', 'eventcrew'),
            'roster' => __('Who works a night; mark done, late or no-show', 'eventcrew'),
            'door' => __('The door list, guest list and sales desk', 'eventcrew'),
            'people' => __('Credits, roles, organizers and passes', 'eventcrew'),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function stored(): array
    {
        $ids = get_option(self::PEOPLE_OPTION, []);

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    /**
     * @param array<int, int> $ids
     */
    private function store(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        update_option(self::PEOPLE_OPTION, $ids, false);
    }
}
