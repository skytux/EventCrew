<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * The open tasks one person may take, for a message that ends in "sign up":
 * grouped under a heading per date (grouped()), one line per task (no
 * names), at most MAX_DATES dates and MAX_TASKS tasks. The message has one
 * See open tasks button for them all, not one per task (Owner 2026-11-07:
 * too many buttons).
 *
 * A task is listed when it has not started, the person can see it on the
 * board (BoardVisibility), its role does not shut them out
 * (SignupService::blocksRestrictedSlot(), or Guest sign up off:
 * BoardVisibility::blocksGuestSignup()), they are not on it yet, and it
 * has a free place (a task with no limit always has). The rest of the rules
 * (standing, the team's OK) stay with SignupService: the Sign up tap runs
 * them and answers with the normal result.
 *
 * action() is one task's Sign up, for the messages that still offer a
 * single task: in the DM the board's join-only callback (j:, never a
 * cancel), or for a restricted task the role list's sign-up (rt:s:, which
 * asks the credit question first). In the email: the crew page at that
 * task's row.
 *
 * The senders put the rows in the context under CONTEXT_KEY; the
 * {open_tasks} placeholder reads them from there.
 */
final class OpenTaskList
{
    /** The context key of the rows: list<array{task: Task, free: int|null}>. */
    public const CONTEXT_KEY = 'open_rows';

    /** How many Sign up buttons the welcome message offers (WelcomeSender). */
    public const DEFAULT_MAX = 3;

    /** The most tasks a list shows (Owner 2026-11-07, Q365). */
    public const MAX_TASKS = 8;

    /** The most dates a list shows (Owner 2026-11-07, Q365). */
    public const MAX_DATES = 3;

    /** The longest short description of a role under a list, in characters. */
    public const SHORT_DESCRIPTION_MAX = 90;

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
     * Up to $max open tasks $person may take, soonest first, on at most
     * MAX_DATES dates: of one event when $eventId is given, of one role when
     * $roleSlug is.
     *
     * @return list<array{task: Task, free: int|null}> free is null for a task with no limit
     */
    public function forPerson(
        Person $person,
        ?int $eventId = null,
        int $max = self::MAX_TASKS,
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
                || BoardVisibility::blocksGuestSignup($task, $person)
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
        $dates = [];

        foreach ($candidates as $task) {
            if (isset($on[$task->id])) {
                continue;
            }

            if (! isset($dates[$task->taskDate]) && count($dates) >= self::MAX_DATES) {
                break;
            }

            $free = $task->hasNoLimit() ? null : $task->capacity - ($occupancy[$task->id] ?? 0);

            if (null !== $free && $free <= 0) {
                continue;
            }

            $rows[] = ['task' => $task, 'free' => $free];
            $dates[$task->taskDate] = true;

            if (count($rows) >= $max) {
                break;
            }
        }

        return $rows;
    }

    /**
     * The {open_tasks} of a message: an empty line, "Open now:" and the rows
     * grouped by date (grouped()); '' when there are no rows.
     *
     * @param array<string, mixed> $context
     */
    public static function block(array $context): string
    {
        $text = self::grouped(self::rows($context));

        return '' === $text ? '' : "\n\n" . __('Open now:', 'eventcrew') . "\n" . $text;
    }

    /**
     * $rows under a "📅 Sat 14 Nov" heading per date, an empty line between
     * the dates, one line per row ($line, line() by default). At most
     * MAX_DATES dates and MAX_TASKS rows, the soonest; '' when there are no
     * rows (Owner 2026-11-07, Q365).
     *
     * @param list<array{task: Task, free: int|null}> $rows Soonest first.
     * @param (callable(Task, int|null): string)|null $line
     */
    public static function grouped(array $rows, ?callable $line = null): string
    {
        $line ??= static fn (Task $task, ?int $free): string => self::line($task, $free);
        $days = [];
        $count = 0;

        foreach ($rows as $row) {
            $date = $row['task']->taskDate;

            if ($count >= self::MAX_TASKS || (! isset($days[$date]) && count($days) >= self::MAX_DATES)) {
                break;
            }

            $days[$date][] = $line($row['task'], $row['free']);
            ++$count;
        }

        $blocks = [];

        // A task on two lines (its free places under it) is a block of its
        // own, with an empty line around it; one-line tasks stay together
        // (Owner 2026-11-07).
        $gap = "\n";

        foreach ($days as $lines) {
            foreach ($lines as $text) {
                $gap = str_contains($text, "\n") ? "\n\n" : $gap;
            }
        }

        foreach ($days as $date => $lines) {
            $blocks[] = '📅 ' . Dates::dayLabel((string) $date) . $gap . implode($gap, $lines);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * "🚪 Door 18:00–20:00 at Dance Night", then the free places on a line
     * of their own, "2 of 3 places free" (Owner 2026-11-07), under the day's
     * heading (grouped()): without the time when the task has none, without
     * the places line for a task with no limit.
     */
    public static function line(Task $task, ?int $free): string
    {
        $name = sprintf(
            /* translators: 1: role with emoji, 2: event */
            __('%1$s at %2$s', 'eventcrew'),
            self::taskName($task),
            $task->eventName()
        );

        return null === $free ? $name : $name . "\n" . self::freeLine($free, $task->capacity);
    }

    /**
     * "🎈 Decorate 13:00–14:00": the role and the time, as the welcome
     * lists them. The role's emoji is the bullet; a role without one gets 🔹.
     */
    public static function taskName(Task $task): string
    {
        $name = trim($task->roleDisplay() . ' ' . $task->timeRange());

        return EmailBody::isEmojiLine('- ' . $name) ? $name : '🔹 ' . $name;
    }

    /** "2 of 3 places free", the second line of a task in a list. */
    public static function freeLine(int $free, int $capacity): string
    {
        return sprintf(
            /* translators: 1: free places, 2: places in total */
            __('%1$d of %2$d places free', 'eventcrew'),
            $free,
            $capacity
        );
    }

    /**
     * What the roles of $rows involve, for the foot of a list (Owner
     * 2026-11-07): "📝 What each task involves" and one "🚪 Door: ..." line
     * per role, in the order listed, each description cut short
     * (shortDescription()). Roles without a description are left out; ''
     * when none has one.
     *
     * @param list<array{task: Task, free: int|null}> $rows
     */
    public static function descriptions(array $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $task = $row['task'];
            $short = self::shortDescription($task->roleDescription());

            if ('' === $short || isset($lines[$task->roleSlug])) {
                continue;
            }

            $lines[$task->roleSlug] = $task->roleDisplay() . ': ' . $short;
        }

        return [] === $lines ? '' : RoleGuide::descriptionsLabel() . "\n" . implode("\n", $lines);
    }

    /**
     * $text on one short line: its first sentence when that is short enough,
     * else cut at a word before SHORT_DESCRIPTION_MAX characters, with "…".
     */
    public static function shortDescription(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (1 === preg_match('/^.+?[.!?](?=\s|$)/u', $text, $match) && mb_strlen($match[0]) <= self::SHORT_DESCRIPTION_MAX) {
            return $match[0];
        }

        if (mb_strlen($text) <= self::SHORT_DESCRIPTION_MAX) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::SHORT_DESCRIPTION_MAX);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false === $space ? $cut : mb_substr($cut, 0, $space), ' ,;:-–') . '…';
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
            'description' => __('An empty line and the open tasks this person can sign up for, under a line with each date, with no names. Empty when there are none.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
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
