<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\BoardVisibility;
use EventCrew\Support\Dates;
use EventCrew\Support\EventName;
use EventCrew\Support\RoleGuide;

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
 *
 * Only one event day is shown at a time: the next one, unless another day was
 * picked from the row of day buttons under it (DayPicker, BoardService::PICK_PREFIX). On
 * a picked day the task buttons carry that day (t:<id>:<Y-m-d>), so the redraw
 * after a join or leave stays on it; on the next day they stay plain t:<id>.
 *
 * With the card format on (BoardService::CARDS_OPTION), cards() gives one
 * message per day instead, and there is no row of day buttons.
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

    /**
     * The longest event heading, "Name · DJ X", in characters. A button label
     * cannot break a line, so the DJ line shares the name's line; longer than
     * this, the heading is the name alone, so a phone does not cut it short.
     */
    public const EVENT_HEADING_MAX = 40;


    public function __construct(
        private readonly TaskRepository $tasks
    ) {
    }

    /**
     * @param string $date The day to show (Y-m-d); '' or a day with no open
     *                     tasks shows the next one.
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    public function render(string $date = ''): array
    {
        $tasks = $this->boardTasks();

        if ([] === $tasks) {
            return $this->emptyBoard();
        }

        $dates = $this->dates($tasks);
        $shown = in_array($date, $dates, true) ? $date : $dates[0];

        return $this->day($tasks, $dates, $shown, true, null, []);
    }

    /**
     * The board in card format: one message per day, for the first
     * DayPicker::DATES days, with no row of day buttons.
     *
     * A day with an EventMesh event that has a picture is a card: the event's
     * picture, its card caption (EventCard) with the board's two lines on how
     * to sign up at the end, and that day's task buttons, then the card's own
     * link buttons. The event's heading rows are left out, because the
     * caption already gives its name and date. Any other day is the same text
     * message the board shows for that day, without the day buttons.
     *
     * With no open tasks the list has one text message under the key ''.
     *
     * @return array<string, array{
     *     kind: string,
     *     text: string,
     *     photo: string,
     *     keyboard: array<int, array<int, array<string, mixed>>>
     * }> Keyed by day (Y-m-d), in date order. kind is 'card' or 'text'; for
     *    a card, text is the caption.
     */
    public function cards(): array
    {
        $tasks = $this->boardTasks();

        if ([] === $tasks) {
            return ['' => ['kind' => 'text', 'photo' => ''] + $this->emptyBoard()];
        }

        $dates = $this->dates($tasks);
        $footer = implode("\n", $this->introLines());
        $messages = [];

        foreach (array_slice($dates, 0, DayPicker::DATES) as $date) {
            $card = $this->cardFor($tasks, $date, $footer);

            if (null === $card) {
                $messages[$date] = ['kind' => 'text', 'photo' => '']
                    + $this->day($tasks, $dates, $date, false, null, []);

                continue;
            }

            $day = $this->day($tasks, $dates, $date, false, $card['event'], $card['buttons']);
            $messages[$date] = [
                'kind' => 'card',
                'text' => $card['caption'],
                'photo' => $card['photo'],
                'keyboard' => $day['keyboard'],
            ];
        }

        return $messages;
    }

    /**
     * The card of the first event on $date that is an EventMesh event with a
     * picture, or null when there is none.
     *
     * @param array<int, Task> $tasks
     * @return array{
     *     event: int,
     *     photo: string,
     *     caption: string,
     *     buttons: array<int, array<int, array<string, string>>>
     * }|null
     */
    private function cardFor(array $tasks, string $date, string $footer): ?array
    {
        $seen = [];

        foreach ($tasks as $task) {
            if ($task->taskDate !== $date || null === $task->eventPostId || isset($seen[$task->eventPostId])) {
                continue;
            }

            $seen[$task->eventPostId] = true;
            $card = EventCard::forEvent($task->eventPostId, $footer);

            if (null !== $card && '' !== $card['photo']) {
                return ['event' => $task->eventPostId] + $card;
            }
        }

        return null;
    }

    /**
     * The tasks the group board may show.
     *
     * @return array<int, Task>
     */
    private function boardTasks(): array
    {
        // The board is one message the whole group reads, so a restricted task
        // (Organize, DJ, ...) cannot be on it at all; the people holding its
        // role take it on the crew page or in a private chat with the bot.
        // A role switched off "Show on the guest board" is left off too. The
        // web page shows the same tasks when signed out (BoardVisibility).
        return array_values(array_filter(
            $this->tasks->upcoming(),
            static fn (Task $task): bool => BoardVisibility::onGuestBoard($task)
        ));
    }

    /**
     * @param array<int, Task> $tasks
     * @return array<int, string> Every day of $tasks, Y-m-d, ascending.
     */
    private function dates(array $tasks): array
    {
        $dates = array_values(array_unique(array_map(static fn (Task $t): string => $t->taskDate, $tasks)));
        sort($dates);

        return $dates;
    }

    /**
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    private function emptyBoard(): array
    {
        return [
            'text' => __('No open tasks right now. Check back soon!', 'eventcrew'),
            'keyboard' => [],
        ];
    }

    /** @return array<int, string> The two lines at the top of the board. */
    private function introLines(): array
    {
        return [
            __('Open tasks — tap one to sign up, tap again to cancel.', 'eventcrew'),
            __('Send /me in a DM to get your summary.', 'eventcrew'),
        ];
    }

    /**
     * One day of the board: the text and the keyboard.
     *
     * @param array<int, Task>   $tasks       Every task the board may show.
     * @param array<int, string> $dates       Every day of $tasks, ascending.
     * @param string             $shown       The day to show, Y-m-d.
     * @param bool             $picker      Whether the row of day buttons goes in.
     * @param int|null           $cardEvent   The event shown as a card: its heading rows are left out.
     * @param array<int, array<int, array<string, string>>> $cardButtons The card's link buttons.
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    private function day(
        array $tasks,
        array $dates,
        string $shown,
        bool $picker,
        ?int $cardEvent,
        array $cardButtons
    ): array {
        $tasks = array_values(array_filter($tasks, static fn (Task $t): bool => $t->taskDate === $shown));
        $daySuffix = $shown === $dates[0] ? '' : ':' . $shown;

        $occupancy = $this->tasks->occupancyFor(array_map(static fn (Task $t): int => $t->id, $tasks));
        $groups = $this->groupByEvent($tasks);

        $lines = $this->introLines();
        $keyboard = [];

        // Headings go in for one event as readily as for several. A board that
        // changes shape when a second event opens is a board people have to
        // re-learn, and the event's own name is worth having even when it is
        // the only one - the date alone never said which night it was.
        foreach ($groups as $key => $group) {
            if (null === $cardEvent || 'e:' . $cardEvent !== $key) {
                $keyboard[] = [$this->spacerButton('📅 ' . $group['title'])];
                $keyboard[] = [$this->spacerButton($group['date'])];
            }

            foreach ($group['tasks'] as $task) {
                $taken = $occupancy[$task->id] ?? 0;
                $keyboard[] = [$this->taskButton($task, $taken, $daySuffix)];
            }
        }

        if ($picker) {
            foreach (DayPicker::rows($dates, $shown, BoardService::PICK_PREFIX, self::SPACER_DATA) as $row) {
                $keyboard[] = $row;
            }
        }

        // The links at the foot are not part of any event - they lead out of
        // the board rather than into a slot - so a rule separates them from the
        // last task above. Built first and only added if there is at least one,
        // or an un-configured bot would end the board on a divider with nothing
        // under it. A card's own links (ticket, DJ, Crew app) come first.
        $deepLinks = $cardButtons;

        foreach (
            [
                'onboard' => __('👋 New here? Sign up →', 'eventcrew'),
                'me' => __('👤 See my info →', 'eventcrew'),
            ] as $payload => $label
        ) {
            $button = $this->deepLinkButton((string) $payload, $label);

            if (null !== $button) {
                $deepLinks[] = [$button];
            }
        }

        // What each task involves: the descriptions section on the web board.
        // Needs only the sign-up page, not the bot username.
        $descriptions = RoleGuide::descriptionsButton();

        if (null !== $descriptions) {
            $deepLinks[] = [$descriptions];
        }

        // The crew guide needs no bot username, only the link itself.
        $guide = RoleGuide::button();

        if (null !== $guide) {
            $deepLinks[] = [$guide];
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
                    'title' => null === $task->eventPostId
                        ? $task->eventName()
                        : EventName::oneLine(
                            $task->eventPostId,
                            EventName::lang(),
                            $task->eventName(),
                            self::EVENT_HEADING_MAX
                        ),
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
    private function taskButton(Task $task, int $taken, string $daySuffix = ''): array
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

        return ['text' => implode(' · ', $parts), 'callback_data' => 't:' . $task->id . $daySuffix];
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
