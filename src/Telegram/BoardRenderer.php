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
 * Tasks are grouped by event, and each group is introduced by two heading rows
 * in the keyboard itself: the event's name, then its date. Those headings used
 * to live in the message text above the buttons, which read correctly and
 * scanned badly - Telegram renders the text and the keyboard as two separate
 * blocks, so a heading in the text sat several rows away from the buttons it
 * named, and with two events open there was no way to tell where one event's
 * buttons ended and the next began.
 *
 * The headings are therefore buttons too. They carry SPACER_DATA, which no
 * handler acts on, so tapping one dismisses its own spinner and does nothing.
 */
final class BoardRenderer
{
    /**
     * The callback payload every heading row carries.
     *
     * Telegram gives no way to put an inert row in an inline keyboard - every
     * button must carry a callback, a URL or one of the other actions - so an
     * inert row has to be a button whose callback means nothing. This one is
     * deliberately not of the form BoardService::parseData() accepts, so it
     * falls through that method's guard and is answered with an empty
     * answerCallbackQuery: the tap stops the client's loading spinner and
     * changes nothing.
     */
    public const SPACER_DATA = 'x';

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

        $lines = [__('Open tasks — tap one to sign up, tap again to cancel.', 'eventcrew')];
        $lines[] = __('Send /me in a DM to get your summary.', 'eventcrew');
        $keyboard = [];

        // Headings go in for one event as readily as for several. A board that
        // changes shape when a second event opens is a board people have to
        // re-learn, and the event's own name is worth having even when it is
        // the only one - the date alone never said which night it was.
        foreach ($groups as $group) {
            $keyboard[] = [$this->spacerButton($group['title'])];
            $keyboard[] = [$this->spacerButton('📅 ' . $group['date'])];

            foreach ($group['tasks'] as $task) {
                $taken = $occupancy[$task->id] ?? 0;
                $keyboard[] = [$this->taskButton($task, $taken)];
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
     * A heading row: text, and a callback nothing acts on.
     *
     * @return array<string, string>
     */
    private function spacerButton(string $text): array
    {
        return ['text' => $text, 'callback_data' => self::SPACER_DATA];
    }

    /**
     * One toggle button per task, reading time first: "17:00–18:30 · Clean 1/3".
     *
     * The time leads because it is what somebody scanning for a slot they can
     * make is actually looking for, and because the rows under one heading are
     * in time order - so a leading time column makes the evening's shape
     * readable at a glance. The date is no longer repeated here: the heading
     * two rows up carries it, and it used to be on every button only because
     * there was nowhere else to put it.
     *
     * The task's own emoji and count come through roleDisplay(); no ✅, which
     * reads as "done" rather than "taken".
     *
     * @return array<string, string>
     */
    private function taskButton(Task $task, int $taken): array
    {
        $label = sprintf('%s %d/%d', $task->roleDisplay(), $taken, $task->capacity);

        if ($taken >= $task->capacity) {
            $label .= ' · ' . __('full', 'eventcrew');
        }

        // A task created from a role template has no times until someone
        // decides them, and an empty time would leave a stray separator.
        $time = $task->timeRange();

        if ('' !== $time) {
            $label = $time . ' · ' . $label;
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
