<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * The open tasks one person may take, for a message that ends in "sign up":
 * one line per task (no names), and one Sign up action per task.
 *
 * A task is listed when it has not started, the person can see it on the
 * board (BoardVisibility), its role does not shut them out
 * (SignupService::blocksRestrictedSlot()), they are not on it yet, and it
 * has a free place (a task with no limit always has). The rest of the rules
 * (standing, the team's OK) stay with SignupService: the Sign up tap runs
 * them and answers with the normal result.
 *
 * Sign up, in the DM: the board's join-only callback (j:, never a cancel),
 * or for a restricted task the role list's sign-up (rt:s:, which asks the
 * credit question first). In the email: the crew page at that task's row.
 *
 * The senders put the rows in the context under CONTEXT_KEY; the
 * {open_tasks} placeholder and the actions read them from there.
 */
final class OpenTaskList
{
    /** The context key of the rows: list<array{task: Task, free: int|null}>. */
    public const CONTEXT_KEY = 'open_rows';

    /** How many tasks a message lists, unless the caller says otherwise. */
    public const DEFAULT_MAX = 3;

    /** The crew page element of one task row: this followed by the task id. */
    public const ANCHOR_PREFIX = 'eventcrew-task-';

    private readonly TaskRepository $tasks;
    private readonly AssignmentRepository $assignments;

    public function __construct(?TaskRepository $tasks = null, ?AssignmentRepository $assignments = null)
    {
        $this->tasks = $tasks ?? new TaskRepository();
        $this->assignments = $assignments ?? new AssignmentRepository();
    }

    /**
     * Up to $max open tasks $person may take, soonest first: of one event
     * when $eventId is given, of one role when $roleSlug is.
     *
     * @return list<array{task: Task, free: int|null}> free is null for a task with no limit
     */
    public function forPerson(
        Person $person,
        ?int $eventId = null,
        int $max = self::DEFAULT_MAX,
        string $roleSlug = ''
    ): array {
        if ($max <= 0) {
            return [];
        }

        $candidates = [];

        foreach ($this->tasks->upcoming() as $task) {
            if (
                (null !== $eventId && $task->eventPostId !== $eventId)
                || ('' !== $roleSlug && $task->roleSlug !== $roleSlug)
                || $task->hasStarted()
                || ! BoardVisibility::visibleTo($task, $person)
                || SignupService::blocksRestrictedSlot($task, $person)
            ) {
                continue;
            }

            $candidates[] = $task;
        }

        if ([] === $candidates) {
            return [];
        }

        $on = [];

        foreach ($this->assignments->forPerson($person->id) as $assignment) {
            if ($assignment->isOccupying()) {
                $on[$assignment->taskId] = true;
            }
        }

        $occupancy = $this->tasks->occupancyFor(array_map(static fn (Task $task): int => $task->id, $candidates));
        $rows = [];

        foreach ($candidates as $task) {
            if (isset($on[$task->id])) {
                continue;
            }

            $free = $task->hasNoLimit() ? null : $task->capacity - ($occupancy[$task->id] ?? 0);

            if (null !== $free && $free <= 0) {
                continue;
            }

            $rows[] = ['task' => $task, 'free' => $free];

            if (count($rows) >= $max) {
                break;
            }
        }

        return $rows;
    }

    /**
     * The {open_tasks} of a message: an empty line, "Open now:" and one line
     * per row; '' when there are no rows.
     *
     * @param array<string, mixed> $context
     */
    public static function block(array $context): string
    {
        $rows = self::rows($context);

        if ([] === $rows) {
            return '';
        }

        return "\n\n" . __('Open now:', 'eventcrew') . "\n"
            . implode("\n", array_map(static fn (array $row): string => self::line($row['task'], $row['free']), $rows));
    }

    /**
     * "- 📅 Sat 14 Nov 18:00–20:00: 🚪 Door at Dance Night (2 of 3 free)",
     * without the places for a task with no limit.
     */
    public static function line(Task $task, ?int $free): string
    {
        $time = $task->timeRange();
        $day = Dates::dayLabel($task->taskDate) . ('' === $time ? '' : ' ' . $time);

        return '- 📅 ' . (null === $free
            ? sprintf(
                /* translators: 1: day and time, 2: role with emoji, 3: event */
                __('%1$s: %2$s at %3$s', 'eventcrew'),
                $day,
                $task->roleDisplay(),
                $task->eventName()
            )
            : sprintf(
                /* translators: 1: day and time, 2: role with emoji, 3: event, 4: free places, 5: places in all */
                __('%1$s: %2$s at %3$s (%4$d of %5$d free)', 'eventcrew'),
                $day,
                $task->roleDisplay(),
                $task->eventName(),
                $free,
                $task->capacity
            ));
    }

    /**
     * One Sign up action per row (MessageTemplates::actions()).
     *
     * @param array<string, mixed> $context
     * @return list<MessageAction>
     */
    public static function actions(array $context, Mailer $mailer): array
    {
        return array_map(
            static fn (array $row): MessageAction => self::action($row['task'], $mailer),
            self::rows($context)
        );
    }

    /** Sign up for $task: the DM's callback, the crew page's row on the web. */
    public static function action(Task $task, Mailer $mailer): MessageAction
    {
        $label = self::label($task);

        return new MessageAction(
            'signup_' . $task->id,
            $label,
            $label,
            static function () use ($mailer, $task): string {
                $url = $mailer->boardUrl();

                return '' === $url ? '' : $url . '#' . self::ANCHOR_PREFIX . $task->id;
            },
            ($task->isRestricted() ? 'rt:s:' : 'j:') . $task->id
        );
    }

    /** "Sign up: 🚪 Door · Sat 14 Nov", as the welcome DM's buttons. */
    public static function label(Task $task): string
    {
        return sprintf(
            /* translators: 1: the role with its emoji, 2: the day, e.g. "Sat 7 Nov" */
            __('Sign up: %1$s · %2$s', 'eventcrew'),
            $task->roleDisplay(),
            Dates::dayLabel($task->taskDate)
        );
    }

    /**
     * The placeholder of the kinds that list the rows.
     *
     * @return array{description: string, resolve: \Closure(array<string, mixed>): string, required: bool}
     */
    public static function placeholder(): array
    {
        return [
            'description' => __('An empty line and the open tasks this person can sign up for, one line each, with no names. Empty when there are none.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
            'resolve' => static fn (array $c): string => self::block($c),
            'required' => false,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array{task: Task, free: int|null}>
     */
    private static function rows(array $context): array
    {
        $rows = [];

        foreach ((array) ($context[self::CONTEXT_KEY] ?? []) as $row) {
            if (is_array($row) && ($row['task'] ?? null) instanceof Task) {
                $rows[] = ['task' => $row['task'], 'free' => isset($row['free']) ? (int) $row['free'] : null];
            }
        }

        return $rows;
    }
}
