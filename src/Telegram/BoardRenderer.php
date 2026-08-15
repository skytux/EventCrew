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
 * in the keyboard itself: 📅 and the event's name, then the date under it.
 * Those headings used to live in the message text above the buttons, which read
 * correctly and scanned badly - Telegram renders the text and the keyboard as
 * two separate blocks, so a heading in the text sat several rows away from the
 * buttons it named, and with two events open there was no way to tell where one
 * event's buttons ended and the next began.
 *
 * The 📅 sits on the name rather than on the date, where it began. It is doing
 * the work of marking where a group starts, and the first row of a group is
 * where the eye needs that mark while scrolling; on the second row it labelled
 * something that already said it was a date.
 *
 * The headings are buttons too, since Telegram has no inert row. They carry
 * SPACER_DATA, which no handler acts on, so tapping one dismisses its own
 * spinner and does nothing. Tasks are told apart from them by their own role
 * emoji, which no heading carries.
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

    /**
     * The separator row's label.
     *
     * A rule rather than a genuinely blank row: Telegram requires an inline
     * button to carry text, and a whitespace-only label is at best undefined -
     * a rejected button means editMessageText fails and the board silently
     * stops updating, which is a poor trade for a slightly quieter gap. Box
     * drawing characters render identically on every client and read as a
     * divider rather than as something to tap.
     */
    public const DIVIDER = '───────────';

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
            $keyboard[] = [$this->spacerButton('📅 ' . $group['title'])];
            $keyboard[] = [$this->spacerButton($group['date'])];

            foreach ($group['tasks'] as $task) {
                $taken = $occupancy[$task->id] ?? 0;
                $keyboard[] = [$this->taskButton($task, $taken)];
            }
        }

        // The two links at the foot are not part of any event - they lead out of
        // the board rather than into a slot - so a rule separates them from the
        // last task above. Built first and only added if there is at least one,
        // or an un-configured bot would end the board on a divider with nothing
        // under it.
        $deepLinks = [];

        foreach (
            [
                'onboard' => __('New here? Sign up →', 'eventcrew'),
                'me' => __('See my info →', 'eventcrew'),
            ] as $payload => $label
        ) {
            $button = $this->deepLinkButton((string) $payload, $label);

            if (null !== $button) {
                $deepLinks[] = [$button];
            }
        }

        if ([] !== $deepLinks) {
            $keyboard[] = [$this->spacerButton(self::DIVIDER)];

            foreach ($deepLinks as $row) {
                $keyboard[] = $row;
            }
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
     * One toggle button per task: "🎨 Decorate · 17:00–18:30 · 1/3".
     *
     * The job's name leads. It is the thing a person is choosing between - the
     * times under one heading are all the same evening, so they separate the
     * rows far less than the work does - and a name in a fixed left-hand column
     * is what makes the list scannable rather than something to read through.
     * What follows is when, then how full, which is the order the questions
     * arrive in.
     *
     * The role's own emoji, from roleDisplay(), is also what tells a task from
     * a heading: headings carry 📅 or nothing, so a row opening with a role
     * emoji is a row that does something. No ✅ among them, which reads as
     * "done" rather than "taken".
     *
     * The date is not repeated here; the heading above carries it, and it used
     * to be on every button only because there was nowhere else to put it.
     *
     * @return array<string, string>
     */
    private function taskButton(Task $task, int $taken): array
    {
        $parts = [$task->roleDisplay()];

        // A task created from a role template has no times until someone
        // decides them, and an empty one would leave a stray separator.
        $time = $task->timeRange();

        if ('' !== $time) {
            $parts[] = $time;
        }

        $parts[] = sprintf('%d/%d', $taken, $task->capacity);

        if ($taken >= $task->capacity) {
            $parts[] = __('full', 'eventcrew');
        }

        return ['text' => implode(' · ', $parts), 'callback_data' => 't:' . $task->id];
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
