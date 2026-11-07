<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\EventSource;
use EventCrew\Support\Roles;
use EventCrew\Web\CrewAdminTasks;

/**
 * Task management from a private chat, for organizers: the Tasks screen of
 * the Crew app Admin tab, typed (or written by the assistant).
 *
 * - "/addtask [date] <role> ; time 21:00-23:00 ; places 2 ; notes … ; plus 1"
 *   adds one task on the next event (or on the date given). Without a time it
 *   starts at the event's start, as the admin form does; places default to
 *   the role's.
 * - "/edittask [date] <task> ; <field> <value> [; …]" changes the time, the
 *   places, the notes or the plus-ones of a task.
 * - "/deletetask [date] <task>" deletes a task the way the admin delete does:
 *   everyone on it is taken off and emailed (TaskCancelledNotice).
 * - "/maketasks [date]" creates the next event's tasks (or the event on the
 *   date) from the roles, as "Create an event's tasks" does.
 * - "/mailtasks" sends the open-task email now, as the admin button does.
 *
 * Names match as in /assign (AssignCommand::bestMatches): two or more
 * matches are asked with one button each, never guessed. Nothing is written
 * before the organizer taps ✅; the buttons work only for that organizer, for
 * 15 minutes, and the organizer flag is checked again at the tap. Every write
 * is CrewAdminTasks, the same code behind the Admin tab, so the checks, the
 * board refresh and the emails are the Admin tab's own.
 */
final class TaskCommand
{
    /** Callback data prefix of every button this command shows. */
    public const PREFIX = 'tk:';

    /** Transient key prefix of a request waiting for a choice or ✅. */
    public const STATE_PREFIX = 'eventcrew_tg_task_';

    public const STATE_TTL = 15 * MINUTE_IN_SECONDS;

    public const ADD = 'addtask';
    public const EDIT = 'edittask';
    public const DELETE = 'deletetask';
    public const MAKE = 'maketasks';
    public const MAIL = 'mailtasks';

    /** Every command this class answers. */
    public const MODES = [self::ADD, self::EDIT, self::DELETE, self::MAKE, self::MAIL];

    /** More matches than this: ask for more of the name instead of a wall of buttons. */
    private const MAX_CHOICES = 6;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly CrewAdminTasks $admin,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * @param string $mode One of self::MODES.
     */
    public function onCommand(int $chatId, int $fromId, string $text, bool $isPrivate, string $mode): void
    {
        if (! $isPrivate) {
            /* translators: %s: a bot command, such as /edittask. */
            $this->telegram->sendMessage($chatId, sprintf(__('Use %s in a private chat with me.', 'eventcrew'), '/' . $mode));

            return;
        }

        if (null === $this->organizer($fromId)) {
            $this->telegram->sendMessage($chatId, self::organizersOnly());

            return;
        }

        $args = trim((string) preg_replace('#^/\S+\s*#u', '', trim($text)));
        $state = ['user' => $fromId, 'mode' => $mode, 'choices' => [], 'choice' => 0, 'fields' => [], 'date' => ''];

        $reply = match ($mode) {
            self::ADD => $this->startAdd($args, $state),
            self::EDIT, self::DELETE => $this->startTask($args, $state),
            self::MAKE => $this->startMake($args, $state),
            default => '' === $args ? null : self::usage($mode),
        };

        if (null !== $reply) {
            $this->telegram->sendMessage($chatId, $reply);

            return;
        }

        $id = bin2hex(random_bytes(8));
        set_transient(self::STATE_PREFIX . $id, $state, self::STATE_TTL);
        $this->next($chatId, $id, $state);
    }

    /**
     * A tap on one of this command's buttons: tk:c chooses a task, role or
     * event, tk:y confirms, tk:x cancels.
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

        if (1 !== preg_match('/^tk:([cyx]):([0-9a-f]{16})(?::(\d+))?$/', $data, $m)) {
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

        if (null === $this->organizer($fromId)) {
            delete_transient($key);
            $this->telegram->answerCallbackQuery($callbackId, self::organizersOnly(), true);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        if ('c' === $m[1]) {
            $choice = (int) ($m[3] ?? 0);

            if (! in_array($choice, array_map('intval', (array) ($state['choices'] ?? [])), true)) {
                return;
            }

            $state['choice'] = $choice;
            set_transient($key, $state, self::STATE_TTL);
            $this->next($chatId, $m[2], $state);

            return;
        }

        // ✅: used once.
        delete_transient($key);
        $this->telegram->sendMessage($chatId, $this->write($state));
    }

    /**
     * How to use a command, with an example.
     */
    public static function usage(string $mode): string
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- gettext literals; splitting one breaks extraction.
        return match ($mode) {
            self::ADD => __("Add a task: /addtask role ; time 21:00-23:00 ; places 2\nAlso: notes text, plus 1. A date first picks another night: /addtask 21.11. Host ; time 21:00", 'eventcrew'),
            self::EDIT => __("Change a task: /edittask task ; places 3\nFields: time 21:00-02:00, places, notes, plus. A date first picks another night: /edittask 21.11. Host ; time 22:00", 'eventcrew'),
            self::DELETE => __("Delete a task: /deletetask task\nA date first picks another night: /deletetask 21.11. Host", 'eventcrew'),
            self::MAKE => __("Create a night's tasks from the roles: /maketasks\nA date picks another night: /maketasks 21.11.", 'eventcrew'),
            default => __('Send the open-task email now: /mailtasks', 'eventcrew'),
        };
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * "time 21:00-23:00 ; places 2 ; notes … ; plus 1" as fields: start and
     * end (H:i, '' to clear the end), capacity, plus_ones, notes. A part that
     * cannot be read is returned as the error, word for word.
     *
     * @return array{0: array<string, string|int>, 1: string}
     */
    public static function parseFields(string $text): array
    {
        $fields = [];

        foreach (explode(';', $text) as $part) {
            $part = trim($part);

            if ('' === $part) {
                continue;
            }

            $words = preg_split('/\s+/u', $part, 2) ?: [];
            $name = strtolower((string) ($words[0] ?? ''));
            $value = trim((string) ($words[1] ?? ''));
            $ok = true;

            switch ($name) {
                case 'time':
                    $range = preg_split('/\s*[-–—]\s*/u', $value) ?: [];
                    $start = self::clock((string) ($range[0] ?? ''));
                    $end = isset($range[1]) ? self::clock($range[1]) : '';
                    $ok = null !== $start && null !== $end && count($range) <= 2;

                    if ($ok) {
                        $fields['start'] = (string) $start;

                        if (isset($range[1])) {
                            $fields['end'] = (string) $end;
                        }
                    }
                    break;

                case 'start':
                case 'end':
                    $clock = 'end' === $name && in_array($value, ['', '-'], true) ? '' : self::clock($value);
                    $ok = null !== $clock && ('start' === $name ? '' !== $clock : true);

                    if ($ok) {
                        $fields[$name] = (string) $clock;
                    }
                    break;

                case 'places':
                case 'plus':
                case 'plus-ones':
                    $ok = 1 === preg_match('/^\d{1,4}$/', $value);

                    if ($ok) {
                        $fields['places' === $name ? 'capacity' : 'plus_ones'] = (int) $value;
                    }
                    break;

                case 'notes':
                    $fields['notes'] = '-' === $value ? '' : $value;
                    break;

                default:
                    $ok = false;
            }

            if (! $ok) {
                return [[], $part];
            }
        }

        return [$fields, ''];
    }

    /**
     * "/addtask": the role, the night and the fields, or the reply that ends
     * the request.
     *
     * @param array<string, mixed> $state
     */
    private function startAdd(string $args, array &$state): ?string
    {
        [$date, $args] = AssignCommand::takeDate($args);
        $sides = explode(';', $args, 2);
        $roleQuery = trim($sides[0]);

        if ('' === $roleQuery) {
            return self::usage(self::ADD);
        }

        [$fields, $bad] = self::parseFields((string) ($sides[1] ?? ''));

        if ('' !== $bad) {
            return self::unreadable($bad);
        }

        $event = EventSource::forNewTask(EventSource::upcoming(), (string) current_time('Y-m-d'), $date);
        $date = '' !== $date ? $date : (string) ($event['date'] ?? '');

        if ('' === $date) {
            return self::usage(self::ADD);
        }

        if (! isset($fields['start']) && '' === (string) ($event['starts_at'] ?? '')) {
            return CrewAdminTasks::noticeText('admin_task_no_start');
        }

        $roles = Roles::active();
        $found = AssignCommand::bestMatches($roleQuery, $roles, static fn (array $r): array => [Roles::label($r['slug']), $r['slug']]);

        if ([] === $found) {
            return sprintf(
                /* translators: 1: the role name the organizer typed, 2: comma-separated role names. */
                __('No role called “%1$s”. Roles: %2$s', 'eventcrew'),
                $roleQuery,
                implode(', ', array_map(static fn (array $r): string => Roles::label($r['slug']), $roles))
            );
        }

        $state['date'] = $date;
        $state['fields'] = $fields;
        $state['event'] = (int) ($event['id'] ?? 0);
        $state['event_start'] = (string) ($event['starts_at'] ?? '');
        $state['roles'] = array_map(static fn (array $r): string => $r['slug'], $found);
        $state['choices'] = array_keys($state['roles']);
        $state['choice'] = 1 === count($found) ? 0 : -1;

        return null;
    }

    /**
     * "/edittask" and "/deletetask": the task (or the tasks to pick from) and
     * the fields, or the reply that ends the request.
     *
     * @param array<string, mixed> $state
     */
    private function startTask(string $args, array &$state): ?string
    {
        $mode = (string) $state['mode'];
        [$date, $args] = AssignCommand::takeDate($args);
        $sides = explode(';', $args, 2);
        $taskQuery = trim($sides[0]);

        if ('' === $taskQuery) {
            return self::usage($mode);
        }

        if (self::EDIT === $mode) {
            [$fields, $bad] = self::parseFields((string) ($sides[1] ?? ''));

            if ('' !== $bad) {
                return self::unreadable($bad);
            }

            if ([] === $fields) {
                return self::usage($mode);
            }

            $state['fields'] = $fields;
        } elseif ('' !== trim((string) ($sides[1] ?? ''))) {
            return self::usage($mode);
        }

        // With a date, every task of that night, also one with 0 places.
        $pool = '' === $date ? array_values($this->tasks->upcoming()) : array_values($this->tasks->forDate($date));

        if ([] === $pool) {
            return '' === $date
                ? __('There are no upcoming tasks.', 'eventcrew')
                /* translators: %s: a short day label, like "Sat 14 Nov". */
                : sprintf(__('There are no tasks on %s.', 'eventcrew'), Dates::dayLabel($date));
        }

        $found = AssignCommand::matchTasks($taskQuery, $pool);

        if ([] === $found) {
            $first = $pool[0]->taskDate;
            $names = [];

            foreach ($pool as $task) {
                if ($task->taskDate === $first) {
                    $names[$task->roleLabel()] = true;
                }
            }

            return sprintf(
                /* translators: 1: the task name the organizer typed, 2: a day label, 3: comma-separated task names. */
                __('No task called “%1$s”. Tasks on %2$s: %3$s', 'eventcrew'),
                $taskQuery,
                Dates::dayLabel($first),
                implode(', ', array_keys($names))
            );
        }

        $found = array_slice($found, 0, self::MAX_CHOICES);
        $state['choices'] = array_map(static fn (Task $t): int => $t->id, $found);
        $state['choice'] = 1 === count($found) ? $found[0]->id : 0;

        return null;
    }

    /**
     * "/maketasks": the event (or the events of the night to pick from), or
     * the reply that ends the request.
     *
     * @param array<string, mixed> $state
     */
    private function startMake(string $args, array &$state): ?string
    {
        $date = '' === $args ? '' : Dates::parseUserDate($args, (string) current_time('Y-m-d'));

        if ('' !== $args && '' === $date) {
            return self::usage(self::MAKE);
        }

        $today = (string) current_time('Y-m-d');
        $events = array_values(array_filter(
            EventSource::upcoming(),
            static fn (array $e): bool => '' !== $e['date'] && ('' === $date ? $e['date'] >= $today : $e['date'] === $date)
        ));

        if ([] === $events) {
            return '' === $date
                ? __('No coming event was found.', 'eventcrew')
                /* translators: %s: a short day label, like "Sat 14 Nov". */
                : sprintf(__('No event was found on %s.', 'eventcrew'), Dates::dayLabel($date));
        }

        $first = $events[0]['date'];
        $events = array_slice(array_values(array_filter($events, static fn (array $e): bool => $e['date'] === $first)), 0, self::MAX_CHOICES);
        $state['choices'] = array_map(static fn (array $e): int => (int) $e['id'], $events);
        $state['choice'] = 1 === count($events) ? (int) $events[0]['id'] : 0;

        return null;
    }

    /**
     * Asks which task, role or event when that is still open, else shows the
     * confirmation.
     *
     * @param array<string, mixed> $state
     */
    private function next(int $chatId, string $id, array $state): void
    {
        $mode = (string) $state['mode'];
        $open = self::ADD === $mode ? -1 === (int) $state['choice'] : (self::MAIL !== $mode && 0 === (int) $state['choice']);

        if ($open) {
            $rows = [];

            foreach ((array) $state['choices'] as $choice) {
                $label = $this->choiceLabel($mode, (int) $choice, $state);

                if ('' !== $label) {
                    $rows[] = [['text' => $label, 'callback_data' => self::PREFIX . 'c:' . $id . ':' . (int) $choice]];
                }
            }

            $rows[] = [$this->cancelButton($id)];
            $question = match ($mode) {
                self::ADD => __('Which role?', 'eventcrew'),
                self::MAKE => __('Which event?', 'eventcrew'),
                default => __('Which task?', 'eventcrew'),
            };
            $this->telegram->sendMessage($chatId, $question, ['inline_keyboard' => $rows]);

            return;
        }

        $text = $this->confirmText($state);

        if (null === $text) {
            delete_transient(self::STATE_PREFIX . $id);
            $this->telegram->sendMessage($chatId, CrewAdminTasks::noticeText('admin_task_gone'));

            return;
        }

        $yes = match ($mode) {
            self::ADD => __('Add', 'eventcrew'),
            self::EDIT => __('Save', 'eventcrew'),
            self::DELETE => __('Delete', 'eventcrew'),
            self::MAKE => __('Create', 'eventcrew'),
            default => __('Send', 'eventcrew'),
        };

        $this->telegram->sendMessage($chatId, $text, ['inline_keyboard' => [[
            ['text' => '✅ ' . $yes, 'callback_data' => self::PREFIX . 'y:' . $id],
            $this->cancelButton($id),
        ]]]);
    }

    /**
     * What ✅ will do, in words, or null when the task or event is gone.
     *
     * @param array<string, mixed> $state
     */
    private function confirmText(array $state): ?string
    {
        $mode = (string) $state['mode'];

        if (self::MAIL === $mode) {
            return __('Send the open-task email now, for the next night with open places? People who already have it are skipped.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        }

        if (self::MAKE === $mode) {
            $event = EventSource::describe((int) $state['choice']);

            return null === $event ? null : sprintf(
                /* translators: 1: an event name, 2: a short day label, like "Sat 14 Nov". */
                __('Create the tasks of %1$s (%2$s) from the roles? Roles that already have a task are skipped.', 'eventcrew'),
                $event['title'],
                Dates::dayLabel($event['date'])
            );
        }

        if (self::ADD === $mode) {
            $preview = $this->newTask($state);

            return implode("\n", [
                /* translators: %s: a task with its day and time. */
                sprintf(__('Add the task %s?', 'eventcrew'), AssignCommand::taskLabel($preview)),
                ...self::fieldLines(['capacity' => $preview->capacity] + (array) $state['fields'], true),
            ]);
        }

        $task = $this->tasks->findLive((int) $state['choice']);

        if (null === $task) {
            return null;
        }

        $taken = (int) ($this->tasks->occupancyFor([$task->id])[$task->id] ?? 0);
        $label = AssignCommand::taskLabel($task);

        if (self::DELETE === $mode) {
            /* translators: %s: a task with its day and time. */
            $lines = [sprintf(__('Delete %s?', 'eventcrew'), $label)];

            if ($taken > 0) {
                $lines[] = sprintf(
                    /* translators: %d: people signed up for the task. */
                    _n(
                        '%d person on it is taken off and gets an email.',
                        '%d people on it are taken off and get an email.',
                        $taken,
                        'eventcrew'
                    ),
                    $taken
                );
            }

            return implode("\n", $lines);
        }

        $fields = (array) $state['fields'];
        /* translators: %s: a task with its day and time. */
        $lines = [sprintf(__('Change %s?', 'eventcrew'), $label), ...self::fieldLines($fields, false)];

        if (isset($fields['capacity']) && ! $task->hasNoLimit() && (int) $fields['capacity'] < $taken) {
            $lines[] = '⚠️ ' . sprintf(
                /* translators: %d: people signed up for the task. */
                _n(
                    '%d person is on it. Nobody is taken off.',
                    '%d people are on it. Nobody is taken off.',
                    $taken,
                    'eventcrew'
                ),
                $taken
            );
        }

        if (isset($fields['capacity']) && 0 === (int) $fields['capacity']) {
            $lines[] = __('With 0 places the task is off the boards.', 'eventcrew');
        }

        return implode("\n", $lines);
    }

    /**
     * Runs the confirmed request through CrewAdminTasks and says how it went.
     *
     * @param array<string, mixed> $state
     */
    private function write(array $state): string
    {
        $mode = (string) $state['mode'];

        if (self::MAIL === $mode) {
            return self::result($this->admin->sendOpenTaskEmail(), '');
        }

        if (self::MAKE === $mode) {
            $event = EventSource::describe((int) $state['choice']);
            $head = null === $event ? '' : $event['title'] . ' · ' . Dates::dayLabel($event['date']);

            return self::result($this->admin->applyTemplate((int) $state['choice']), $head);
        }

        if (self::ADD === $mode) {
            $preview = $this->newTask($state);
            $fields = (array) $state['fields'];
            $post = [
                'role_slug' => $preview->roleSlug,
                'task_date' => $preview->taskDate,
                'starts_at' => (string) ($fields['start'] ?? substr((string) $state['event_start'], 0, 16)),
                'ends_at' => (string) ($fields['end'] ?? ''),
                'event_choice' => $state['event'] > 0 ? (string) $state['event'] : CrewAdminTasks::EVENT_OTHER,
                'event_label' => '',
                'capacity' => $preview->capacity,
                'plus_ones' => isset($fields['plus_ones']) ? (string) $fields['plus_ones'] : '',
                'notes' => (string) ($fields['notes'] ?? ''),
            ];

            return self::result($this->admin->save($post), AssignCommand::taskLabel($preview));
        }

        $task = $this->tasks->findLive((int) $state['choice']);

        if (null === $task) {
            return self::result('admin_task_gone', '');
        }

        if (self::DELETE === $mode) {
            return self::result($this->admin->delete($task->id), AssignCommand::taskLabel($task));
        }

        $code = $this->admin->save($this->editPost($task, (array) $state['fields']));
        $saved = $this->tasks->findLive($task->id) ?? $task;

        return self::result($code, AssignCommand::taskLabel($saved));
    }

    /**
     * The Admin tab's task form as it would be posted for $task, with $fields
     * changed and everything else as stored.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function editPost(Task $task, array $fields): array
    {
        $post = [
            'task_id' => $task->id,
            'role_slug' => $task->roleSlug,
            'task_date' => $task->taskDate,
            'starts_at' => self::formTime($task->startsAt),
            'ends_at' => self::formTime($task->endsAt),
            'event_choice' => null === $task->eventPostId ? CrewAdminTasks::EVENT_OTHER : (string) $task->eventPostId,
            'event_label' => $task->eventLabel,
            'capacity' => $task->capacity,
            'plus_ones' => (string) $task->plusOnes,
            'notes' => $task->notes,
        ];

        if (isset($fields['start'])) {
            $post['starts_at'] = (string) $fields['start'];
        }

        if (isset($fields['end'])) {
            $post['ends_at'] = (string) $fields['end'];
        }

        if (isset($fields['capacity'])) {
            $post['capacity'] = (int) $fields['capacity'];
        }

        if (isset($fields['plus_ones'])) {
            $post['plus_ones'] = (string) $fields['plus_ones'];
        }

        if (isset($fields['notes'])) {
            $post['notes'] = (string) $fields['notes'];
        }

        return $post;
    }

    /**
     * The task /addtask would create, for its label: the role chosen, the
     * night, the times (the event's start when none was typed) and the places.
     *
     * @param array<string, mixed> $state
     */
    private function newTask(array $state): Task
    {
        $slug = (string) ($state['roles'][max(0, (int) $state['choice'])] ?? '');
        $fields = (array) $state['fields'];
        $date = (string) $state['date'];
        $start = isset($fields['start']) ? $date . ' ' . $fields['start'] . ':00' : (string) $state['event_start'];
        $end = isset($fields['end']) && '' !== $fields['end'] ? $date . ' ' . $fields['end'] . ':00' : null;

        if (null !== $end && $end < $start) {
            $end = gmdate('Y-m-d H:i:s', (int) strtotime($end) + DAY_IN_SECONDS);
        }

        return new Task(
            0,
            $date,
            $slug,
            isset($fields['capacity']) ? (int) $fields['capacity'] : Roles::defaultCapacity($slug),
            null,
            '',
            '' === $start ? null : $start,
            $end
        );
    }

    /**
     * The confirmation's lines for the fields typed.
     *
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    private static function fieldLines(array $fields, bool $adding): array
    {
        $lines = [];

        if (! $adding && (isset($fields['start']) || isset($fields['end']))) {
            $time = isset($fields['start']) ? (string) $fields['start'] : '…';
            $time .= isset($fields['end']) && '' !== $fields['end'] ? '–' . $fields['end'] : '';
            $lines[] = __('Time', 'eventcrew') . ': ' . $time;
        }

        if (isset($fields['capacity'])) {
            $lines[] = __('People needed', 'eventcrew') . ': ' . (int) $fields['capacity'];
        }

        if (isset($fields['plus_ones'])) {
            $lines[] = __('Plus-ones', 'eventcrew') . ': ' . (int) $fields['plus_ones'];
        }

        if (isset($fields['notes'])) {
            $lines[] = __('Notes', 'eventcrew') . ': ' . ('' === $fields['notes'] ? '–' : (string) $fields['notes']);
        }

        return $lines;
    }

    /**
     * A choice button's text: a task, a role or an event.
     *
     * @param array<string, mixed> $state
     */
    private function choiceLabel(string $mode, int $choice, array $state): string
    {
        if (self::ADD === $mode) {
            return Roles::display((string) ($state['roles'][$choice] ?? ''));
        }

        if (self::MAKE === $mode) {
            $event = EventSource::describe($choice);

            return null === $event ? '' : $event['title'] . ' · ' . substr($event['starts_at'], 11, 5);
        }

        $task = $this->tasks->findLive($choice);

        return null === $task ? '' : AssignCommand::taskLabel($task);
    }

    /** "✅ <head>\n<the Admin tab's notice>", or ⚠️ when it did not go through. */
    private static function result(string $code, string $head): string
    {
        $done = in_array($code, ['admin_task_added', 'admin_task_updated', 'admin_task_deleted'], true)
            || str_starts_with($code, 'admin_template_created_')
            || str_starts_with($code, 'admin_open_task_sent_');

        return ($done ? '✅ ' : '⚠️ ') . ('' === $head ? '' : $head . "\n") . CrewAdminTasks::noticeText($code);
    }

    private static function unreadable(string $part): string
    {
        return sprintf(
            /* translators: %s: the part of the command that could not be read. */
            __('Could not read “%s”. Use time 21:00-23:00, places 2, notes text or plus 1.', 'eventcrew'),
            $part
        );
    }

    private static function organizersOnly(): string
    {
        return __('Only organizers can change tasks.', 'eventcrew');
    }

    /** "21", "21:30", "9.30" as "21:00", "21:30", "09:30"; null when not a time of day. */
    private static function clock(string $value): ?string
    {
        if (1 !== preg_match('/^\s*([01]?\d|2[0-3])(?:[:.]([0-5]\d))?\s*$/', $value, $m)) {
            return null;
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2] ?? '00');
    }

    /** A stored start or end as the form sends it: "Y-m-d H:i", or '' for none. */
    private static function formTime(?string $stored): string
    {
        if (null === $stored || '' === $stored) {
            return '';
        }

        return strlen($stored) >= 16 ? substr($stored, 0, 16) : substr($stored, 0, 5);
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    private function cancelButton(string $id): array
    {
        return ['text' => '✖️ ' . __('Cancel', 'eventcrew'), 'callback_data' => self::PREFIX . 'x:' . $id];
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
