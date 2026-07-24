<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;

/**
 * Turns the open tasks into the board's text and inline keyboard - the pure
 * "what the board looks like" half of BoardService, pulled out so posting and
 * editing the message (the lifecycle) reads separately from composing it.
 *
 * Tasks are grouped by event, and headings only appear when more than one event
 * is open at once, so a single event stays a plain list of task buttons.
 */
final class BoardRenderer
{
    public function __construct(
        private readonly TaskRepository $tasks
    ) {
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    public function render(): array
    {
        $tasks = $this->tasks->upcoming();

        if ([] === $tasks) {
            return [
                'text' => __('No open tasks right now. Check back soon!', 'eventcrew'),
                'keyboard' => [],
            ];
        }

        $occupancy = $this->tasks->occupancyFor(array_map(static fn (Task $t): int => $t->id, $tasks));
        $groups = $this->groupByEvent($tasks);
        $multiEvent = count($groups) > 1;

        $lines = [__('Open tasks — tap one to sign up, tap again to cancel.', 'eventcrew')];
        $lines[] = __('Send /me in a DM to get your summary.', 'eventcrew');
        $keyboard = [];

        foreach ($groups as $group) {
            if ($multiEvent) {
                $lines[] = '';
                $lines[] = '📅 ' . $group['date'] . ' · ' . $group['title'];
            }

            foreach ($group['tasks'] as $task) {
                $taken = $occupancy[$task->id] ?? 0;
                $keyboard[] = [$this->taskButton($task, $taken, $multiEvent)];
            }
        }

        $deepLinkOnboard = $this->deepLinkButton('onboard', __('New here? Sign up →', 'eventcrew'));

        if (null !== $deepLinkOnboard) {
            $keyboard[] = [$deepLinkOnboard];
        }

        $deepLinkMe = $this->deepLinkButton('me', __('See my info →', 'eventcrew'));

        if (null !== $deepLinkMe) {
            $keyboard[] = [$deepLinkMe];
        }

        return ['text' => implode("\n", $lines), 'keyboard' => $keyboard];
    }

    /**
     * @param array<int, Task> $tasks
     * @return array<string, array{title: string, tasks: array<int, Task>, date: string}>
     */
    private function groupByEvent(array $tasks): array
    {
        $groups = [];

        foreach ($tasks as $task) {
            $key = null !== $task->eventPostId
                ? 'e:' . $task->eventPostId
                : 'l:' . $task->eventLabel . '|' . $task->taskDate;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'title' => $task->eventName(),
                    'tasks' => [],
                    'date' => Dates::dayLabel($task->taskDate),
                ];
            }

            $groups[$key]['tasks'][] = $task;
        }

        return $groups;
    }

    /**
     * One toggle button per task. It carries the task's own emoji and count -
     * no ✅, which read as "done" - and, when more than one event is open, the
     * task's date, since the buttons sit in a flat list under the text and
     * otherwise give no clue which day a task belongs to.
     *
     * @return array<string, string>
     */
    private function taskButton(Task $task, int $taken, bool $multiEvent): array
    {
        $label = sprintf('%s %d/%d', $task->roleDisplay(), $taken, $task->capacity);

        if ($taken >= $task->capacity) {
            $label .= ' · ' . __('full', 'eventcrew');
        }

        if ($multiEvent) {
            $label = Dates::dayLabel($task->taskDate) . ' · ' . $task->timeRange() . ' · ' . $label;
        }

        return ['text' => $label, 'callback_data' => 't:' . $task->id];
    }

    /**
     * @return array<string, string>|null
     */
    private function deepLinkButton(string $payload, string $text): ?array
    {
        $username = trim((string) get_option(BoardService::USERNAME_OPTION, ''));

        if ('' === $username) {
            return null;
        }

        return [
            'text' => $text,
            'url' => 'https://t.me/' . $username . '?start=' . rawurlencode($payload),
        ];
    }
}
