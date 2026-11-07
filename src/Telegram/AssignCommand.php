<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\Roles;
use EventCrew\Support\RoleTaskService;

/**
 * /assign and /unassign: an organizer puts a named member on a task, or takes
 * them off, by typing it (or through the assistant, which writes the command).
 *
 * - "/assign [date] <task> ; <person>" finds the task by name on the next
 *   event (or on the date given) and the member by name, forgiving small
 *   typos. Without the ";" the longest leading words that name a task are
 *   taken as the task.
 * - Two or more matching tasks or members: the bot asks with one button each
 *   and never guesses. No match: it says so.
 * - Nothing is written before the organizer taps ✅ on the confirmation. On a
 *   role-restricted task the confirmation says when the person lacks the role
 *   (adding gives it to them) and asks "Count toward free-entry credit?".
 * - The write is RoleTaskService::addPerson() / removePerson(), the same
 *   service behind "➕ Add someone" under /board, so the person gets the
 *   normal sign-up confirmation and ticket.
 *
 * Private chat and organizers only, checked at the command and at every tap.
 */
final class AssignCommand
{
    /** Callback data prefix of every button this command shows. */
    public const PREFIX = 'as:';

    /** Transient key prefix of a request waiting for a choice or ✅. */
    public const STATE_PREFIX = 'eventcrew_tg_assign_';

    public const STATE_TTL = 15 * MINUTE_IN_SECONDS;

    public const ADD = 'assign';
    public const REMOVE = 'unassign';

    /** More matching members than this: ask for more of the name instead of a wall of buttons. */
    private const MAX_CHOICES = 6;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly RoleTaskService $roleTasks,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * @param string $mode self::ADD or self::REMOVE.
     */
    public function onCommand(int $chatId, int $fromId, string $text, bool $isPrivate, string $mode): void
    {
        $command = '/' . $mode;

        if (! $isPrivate) {
            /* translators: %s: a bot command, /assign or /unassign. */
            $this->telegram->sendMessage($chatId, sprintf(__('Use %s in a private chat with me.', 'eventcrew'), $command));

            return;
        }

        $organizer = $this->organizer($fromId);

        if (null === $organizer) {
            $this->telegram->sendMessage($chatId, RoleTaskService::text(RoleTaskService::ORGANIZERS_ONLY));

            return;
        }

        $args = trim((string) preg_replace('#^/\S+\s*#u', '', trim($text)));

        if ('' === $args) {
            $this->telegram->sendMessage($chatId, self::usage($mode));

            return;
        }

        [$date, $args] = self::takeDate($args);
        $pool = $this->tasks->upcoming();

        if (self::ADD === $mode) {
            $pool = RoleTaskService::addableOf($pool);
        }

        if ('' !== $date) {
            $pool = array_values(array_filter($pool, static fn (Task $t): bool => $t->taskDate === $date));

            if ([] === $pool) {
                $this->telegram->sendMessage(
                    $chatId,
                    /* translators: %s: a short day label, like "Sat 14 Nov". */
                    sprintf(__('There are no tasks on %s.', 'eventcrew'), Dates::dayLabel($date))
                );

                return;
            }
        }

        [$taskQuery, $personQuery] = self::split($args, $pool);

        if ('' === $taskQuery || '' === $personQuery) {
            $this->telegram->sendMessage($chatId, self::usage($mode));

            return;
        }

        $tasks = self::matchTasks($taskQuery, $pool);

        if ([] === $tasks) {
            $this->telegram->sendMessage($chatId, $this->noTask($taskQuery, $pool, $mode, $date));

            return;
        }

        $people = self::matchPeople($personQuery, $this->members());

        if ([] === $people) {
            $this->telegram->sendMessage(
                $chatId,
                self::ADD === $mode
                    /* translators: %s: the name the organizer typed. */
                    ? sprintf(__('No member called “%s” was found. Someone who is not a member yet: ➕ Add someone under /board.', 'eventcrew'), $personQuery)
                    /* translators: %s: the name the organizer typed. */
                    : sprintf(__('No member called “%s” was found.', 'eventcrew'), $personQuery)
            );

            return;
        }

        if (count($people) > self::MAX_CHOICES) {
            $this->telegram->sendMessage(
                $chatId,
                /* translators: %s: the name the organizer typed. */
                sprintf(__('Many members match “%s”. Write more of the name.', 'eventcrew'), $personQuery)
            );

            return;
        }

        $state = [
            'user' => $fromId,
            'mode' => $mode,
            'tasks' => array_map(static fn (Task $t): int => $t->id, $tasks),
            'people' => array_map(static fn (Person $p): int => $p->id, $people),
            'task' => 1 === count($tasks) ? $tasks[0]->id : 0,
            'person' => 1 === count($people) ? $people[0]->id : 0,
        ];
        $id = bin2hex(random_bytes(8));
        set_transient(self::STATE_PREFIX . $id, $state, self::STATE_TTL);

        $this->next($chatId, $id, $state, $tasks, $people);
    }

    /**
     * A tap on one of this command's buttons: as:t / as:p choose a task or a
     * member, as:y / as:n confirm (with or without free-entry credit), as:x
     * cancels.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onCallback(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? $fromId);
        $messageId = (int) ($message['message_id'] ?? 0);
        $data = (string) ($callbackQuery['data'] ?? '');

        if (1 !== preg_match('/^as:([tpynx]):([0-9a-f]{16})(?::(\d+))?$/', $data, $m)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $key = self::STATE_PREFIX . $m[2];
        $state = get_transient($key);

        if (! is_array($state) || 0 === $fromId || (int) ($state['user'] ?? 0) !== $fromId) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('This request has expired. Please send it again.', 'eventcrew'),
                true
            );

            return;
        }

        $this->removeButtons($chatId, $messageId);

        if ('x' === $m[1]) {
            delete_transient($key);
            $this->telegram->answerCallbackQuery($callbackId, __('Cancelled.', 'eventcrew'));
            $this->telegram->sendMessage($chatId, '✖️ ' . __('Cancelled. Nothing was changed.', 'eventcrew'));

            return;
        }

        $organizer = $this->organizer($fromId);

        if (null === $organizer) {
            delete_transient($key);
            $this->telegram->answerCallbackQuery($callbackId, RoleTaskService::text(RoleTaskService::ORGANIZERS_ONLY), true);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $choice = (int) ($m[3] ?? 0);

        if ('t' === $m[1] || 'p' === $m[1]) {
            $field = 't' === $m[1] ? 'task' : 'person';
            $allowed = array_map('intval', (array) ($state['t' === $m[1] ? 'tasks' : 'people'] ?? []));

            if (! in_array($choice, $allowed, true)) {
                return;
            }

            $state[$field] = $choice;
            set_transient($key, $state, self::STATE_TTL);
            $this->next($chatId, $m[2], $state);

            return;
        }

        // ✅ (y) or "no credit" (n): used once.
        delete_transient($key);
        $taskId = (int) ($state['task'] ?? 0);
        $personId = (int) ($state['person'] ?? 0);
        $task = $taskId > 0 ? $this->tasks->find($taskId) : null;

        if (self::REMOVE === ($state['mode'] ?? '')) {
            $result = $this->roleTasks->removePerson($organizer, $taskId, $personId);
            $done = RoleTaskService::REMOVED === $result['code'];
            $this->telegram->sendMessage(
                $chatId,
                $this->result($done, $result['person'], $task, RoleTaskService::text($result['code']))
            );

            return;
        }

        $result = $this->roleTasks->addPerson($organizer, $taskId, '', '', 'n' !== $m[1], $personId);
        $done = RoleTaskService::ADDED === $result['code'];
        $this->telegram->sendMessage(
            $chatId,
            $this->result($done, $result['person'], $task, RoleTaskService::text($result['code'], $result['emailed']))
        );
    }

    /**
     * How to use the command, with an example.
     */
    public static function usage(string $mode): string
    {
        return self::REMOVE === $mode
            ? __("Take a member off a task: /unassign task ; name\nExample: /unassign Host ; Milla\nA date first picks another event: /unassign 21.11. Host ; Milla", 'eventcrew')
            : __("Put a member on a task: /assign task ; name\nExample: /assign Host ; Milla\nA date first picks another event: /assign 21.11. Host ; Milla", 'eventcrew');
    }

    /**
     * The next step once the task and member are known or still open: ask for
     * the task, then the member, then show the confirmation.
     *
     * @param array<string, mixed> $state
     * @param list<Task>|null $tasks
     * @param list<Person>|null $people
     */
    private function next(int $chatId, string $id, array $state, ?array $tasks = null, ?array $people = null): void
    {
        if (0 === (int) $state['task']) {
            $tasks ??= $this->findTasks((array) $state['tasks']);
            $rows = array_map(
                fn (Task $t): array => [['text' => $this->taskLabel($t), 'callback_data' => self::PREFIX . 't:' . $id . ':' . $t->id]],
                $tasks
            );
            $rows[] = $this->cancelRow($id);
            $this->telegram->sendMessage($chatId, __('Which task?', 'eventcrew'), ['inline_keyboard' => $rows]);

            return;
        }

        if (0 === (int) $state['person']) {
            $people ??= $this->findPeople((array) $state['people']);
            $rows = array_map(
                static fn (Person $p): array => [['text' => self::personLabel($p, $people), 'callback_data' => self::PREFIX . 'p:' . $id . ':' . $p->id]],
                $people
            );
            $rows[] = $this->cancelRow($id);
            $this->telegram->sendMessage($chatId, __('Which member?', 'eventcrew'), ['inline_keyboard' => $rows]);

            return;
        }

        $this->confirm($chatId, $id, $state);
    }

    /**
     * Checks what can be told before writing (blocked, already on, full, not
     * on), then asks ✅ / ✖️.
     *
     * @param array<string, mixed> $state
     */
    private function confirm(int $chatId, string $id, array $state): void
    {
        $task = $this->tasks->find((int) $state['task']);
        $person = $this->people->find((int) $state['person']);

        if (null === $task || null === $person) {
            delete_transient(self::STATE_PREFIX . $id);
            $this->telegram->sendMessage($chatId, RoleTaskService::text(RoleTaskService::NOT_RESTRICTED));

            return;
        }

        $name = $person->name();
        $label = $this->taskLabel($task);
        $isOn = $this->roleTasks->isOn($task->id, $person->id);

        if (self::REMOVE === $state['mode']) {
            if (! $isOn) {
                delete_transient(self::STATE_PREFIX . $id);
                $this->telegram->sendMessage($chatId, $name . ' · ' . $label . "\n" . RoleTaskService::text(RoleTaskService::NOT_ON));

                return;
            }

            $this->telegram->sendMessage(
                $chatId,
                /* translators: 1: a person's name, 2: a task with its day and time. */
                sprintf(__('Take %1$s off %2$s?', 'eventcrew'), $name, $label),
                ['inline_keyboard' => [[
                    ['text' => '✅ ' . __('Remove', 'eventcrew'), 'callback_data' => self::PREFIX . 'y:' . $id],
                    ['text' => '✖️ ' . __('Cancel', 'eventcrew'), 'callback_data' => self::PREFIX . 'x:' . $id],
                ]]]
            );

            return;
        }

        $stop = null;

        if ($person->isBanned()) {
            /* translators: %s: a person's name. */
            $stop = sprintf(__('%s is blocked from sign-ups. An organizer can lift it on the People page.', 'eventcrew'), $name);
        } elseif ($isOn) {
            $stop = RoleTaskService::text(RoleTaskService::ALREADY_ON);
        } elseif (! $task->hasNoLimit()) {
            $taken = (int) ($this->tasks->occupancyFor([$task->id])[$task->id] ?? 0);

            if ($taken >= $task->capacity) {
                /* translators: 1: places taken, 2: all places. */
                $stop = sprintf(__('That task is full (%1$d of %2$d places taken). Nothing was added.', 'eventcrew'), $taken, $task->capacity);
            }
        }

        if (null !== $stop) {
            delete_transient(self::STATE_PREFIX . $id);
            $this->telegram->sendMessage($chatId, $name . ' · ' . $label . "\n" . $stop);

            return;
        }

        /* translators: 1: a person's name, 2: a task with its day and time. */
        $text = sprintf(__('Add %1$s to %2$s?', 'eventcrew'), $name, $label);

        if ($task->isRestricted() && ! $person->hasRole($task->roleSlug)) {
            $text .= "\n⚠️ " . sprintf(
                /* translators: 1: a person's name, 2: a role name. */
                __('%1$s does not have the %2$s role. As an organizer you can still add them; they get the role.', 'eventcrew'),
                $name,
                $task->roleLabel()
            );
        }

        $cancel = ['text' => '✖️ ' . __('Cancel', 'eventcrew'), 'callback_data' => self::PREFIX . 'x:' . $id];

        if ($task->isRestricted()) {
            $text .= "\n" . __('Count toward free-entry credit?', 'eventcrew');
            $rows = [
                [
                    ['text' => '✅ ' . __('Add, counts', 'eventcrew'), 'callback_data' => self::PREFIX . 'y:' . $id],
                    ['text' => '✅ ' . __('Add, no credit', 'eventcrew'), 'callback_data' => self::PREFIX . 'n:' . $id],
                ],
                [$cancel],
            ];
        } else {
            $rows = [[
                ['text' => '✅ ' . __('Add', 'eventcrew'), 'callback_data' => self::PREFIX . 'y:' . $id],
                $cancel,
            ]];
        }

        $this->telegram->sendMessage($chatId, $text, ['inline_keyboard' => $rows]);
    }

    private function result(bool $done, ?Person $person, ?Task $task, string $sentence): string
    {
        $head = null === $person || null === $task ? '' : $person->name() . ' · ' . $this->taskLabel($task) . "\n";

        return ($done ? '✅ ' : '⚠️ ') . $head . $sentence;
    }

    /**
     * Why no task matched, with the task names to pick from.
     *
     * @param list<Task> $pool
     */
    private function noTask(string $query, array $pool, string $mode, string $date): string
    {
        $all = self::ADD === $mode ? $this->tasks->upcoming() : [];

        foreach ($all as $task) {
            if (('' === $date || $task->taskDate === $date) && Roles::ORGANIZE_SLUG === $task->roleSlug && [] !== self::matchTasks($query, [$task])) {
                return __('Organize tasks are for organizers only. Make someone an organizer with /people.', 'eventcrew');
            }
        }

        $first = [] === $pool ? '' : $pool[0]->taskDate;
        $names = [];

        foreach ($pool as $task) {
            if ($task->taskDate === $first) {
                $names[$task->roleLabel()] = true;
            }
        }

        if ([] === $names) {
            return __('There are no upcoming tasks.', 'eventcrew');
        }

        return sprintf(
            /* translators: 1: the task name the organizer typed, 2: a day label, 3: comma-separated task names. */
            __('No task called “%1$s”. Tasks on %2$s: %3$s', 'eventcrew'),
            $query,
            Dates::dayLabel($first),
            implode(', ', array_keys($names))
        );
    }

    /**
     * A leading date ("21.11.", "2026-11-21") and the rest.
     *
     * @return array{0: string, 1: string}
     */
    private static function takeDate(string $args): array
    {
        $parts = preg_split('/\s+/u', $args, 2);
        $date = Dates::parseUserDate((string) ($parts[0] ?? ''), current_time('Y-m-d'));

        return '' === $date ? ['', $args] : [$date, trim((string) ($parts[1] ?? ''))];
    }

    /**
     * The task words and the member words. "task ; name" splits at the ";";
     * otherwise the longest leading words that name a task in $pool are the
     * task.
     *
     * @param list<Task> $pool
     * @return array{0: string, 1: string}
     */
    public static function split(string $args, array $pool): array
    {
        if (str_contains($args, ';')) {
            [$task, $person] = explode(';', $args, 2);

            return [trim($task), trim($person)];
        }

        $words = preg_split('/\s+/u', trim($args)) ?: [];

        for ($i = count($words) - 1; $i >= 1; $i--) {
            $head = implode(' ', array_slice($words, 0, $i));

            foreach ($pool as $task) {
                $norm = self::norm($head);

                if ($norm === self::norm($task->roleLabel()) || $norm === self::norm($task->roleSlug)) {
                    return [$head, implode(' ', array_slice($words, $i))];
                }
            }
        }

        return ['', ''];
    }

    /**
     * The tasks whose name matches best: exact, then starts with, then
     * contains, then off by a typo or two. Only the earliest date among them,
     * so "Host" means the next event's Host.
     *
     * @param list<Task> $pool
     * @return list<Task>
     */
    public static function matchTasks(string $query, array $pool): array
    {
        $q = self::norm($query);

        if ('' === $q) {
            return [];
        }

        $tests = [
            static fn (string $name): bool => $name === $q,
            static fn (string $name): bool => str_starts_with($name, $q),
            static fn (string $name): bool => mb_strlen($q) >= 3 && str_contains($name, $q),
            static fn (string $name): bool => levenshtein($name, $q) <= (mb_strlen($q) > 5 ? 2 : 1),
        ];

        foreach ($tests as $test) {
            $found = array_values(array_filter(
                $pool,
                static fn (Task $t): bool => $test(self::norm($t->roleLabel())) || $test(self::norm($t->roleSlug))
            ));

            if ([] !== $found) {
                $first = $found[0]->taskDate;

                return array_values(array_filter($found, static fn (Task $t): bool => $t->taskDate === $first));
            }
        }

        return [];
    }

    /**
     * The members whose name matches best: the whole name, then every word
     * typed starting a word of the name, then a part of the name or of the
     * email before the @, then a name word off by a typo or two.
     *
     * @param list<Person> $members
     * @return list<Person>
     */
    public static function matchPeople(string $query, array $members): array
    {
        $q = self::norm($query);
        $qWords = preg_split('/\s+/u', $q) ?: [];

        if ('' === $q) {
            return [];
        }

        $words = static fn (Person $p): array => preg_split('/[\s\-]+/u', self::norm($p->name())) ?: [];
        $near = static fn (string $a, string $b): bool => levenshtein($a, $b) <= (mb_strlen($b) > 4 ? 2 : 1);

        $tests = [
            static fn (Person $p): bool => self::norm($p->name()) === $q,
            static function (Person $p) use ($qWords, $words): bool {
                $own = $words($p);

                foreach ($qWords as $w) {
                    if ([] === array_filter($own, static fn (string $o): bool => str_starts_with($o, $w))) {
                        return false;
                    }
                }

                return true;
            },
            static fn (Person $p): bool => mb_strlen($q) >= 3 && (
                str_contains(self::norm($p->name()), $q)
                || str_contains(self::norm(strstr($p->email, '@', true) ?: ''), $q)
            ),
            static function (Person $p) use ($qWords, $words, $near): bool {
                $own = $words($p);

                foreach ($qWords as $w) {
                    if ([] === array_filter($own, static fn (string $o): bool => $near($o, $w))) {
                        return false;
                    }
                }

                return true;
            },
        ];

        foreach ($tests as $test) {
            $found = array_values(array_filter($members, $test));

            if ([] !== $found) {
                return $found;
            }
        }

        return [];
    }

    private static function norm(string $text): string
    {
        $text = mb_strtolower(trim($text));

        if (function_exists('remove_accents')) {
            $text = remove_accents($text);
        }

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Everyone who can be put on a task: not anonymized.
     *
     * @return list<Person>
     */
    private function members(): array
    {
        return array_values(array_filter(
            $this->people->all(['per_page' => 2000, 'orderby' => 'display_name']),
            static fn (Person $p): bool => ! $p->isAnonymized()
        ));
    }

    /**
     * @param array<int, mixed> $ids
     * @return list<Task>
     */
    private function findTasks(array $ids): array
    {
        return array_values(array_filter(array_map(fn ($id): ?Task => $this->tasks->find((int) $id), $ids)));
    }

    /**
     * @param array<int, mixed> $ids
     * @return list<Person>
     */
    private function findPeople(array $ids): array
    {
        return array_values(array_filter(array_map(fn ($id): ?Person => $this->people->find((int) $id), $ids)));
    }

    private function taskLabel(Task $task): string
    {
        return trim($task->roleLabel() . ' · ' . Dates::dayLabel($task->taskDate) . ' ' . $task->timeRange());
    }

    /**
     * A member's button text: the name, plus the start of the email when two
     * members share the name.
     *
     * @param list<Person> $all
     */
    private static function personLabel(Person $person, array $all): string
    {
        $same = array_filter($all, static fn (Person $p): bool => self::norm($p->name()) === self::norm($person->name()));

        if (count($same) < 2) {
            return $person->name();
        }

        return $person->name() . ' · ' . mb_substr((string) strstr($person->email, '@', true), 0, 4) . '…';
    }

    /**
     * @return array<int, array{text: string, callback_data: string}>
     */
    private function cancelRow(string $id): array
    {
        return [['text' => '✖️ ' . __('Cancel', 'eventcrew'), 'callback_data' => self::PREFIX . 'x:' . $id]];
    }

    private function organizer(int $telegramUserId): ?Person
    {
        if (0 === $telegramUserId) {
            return null;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        return null !== $person && $person->isOrganizer ? $person : null;
    }

    private function removeButtons(int $chatId, int $messageId): void
    {
        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        $this->telegram->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }
}
