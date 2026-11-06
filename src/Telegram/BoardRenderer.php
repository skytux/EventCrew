<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\BoardVisibility;
use EventCrew\Support\Dates;
use EventCrew\Support\EventName;

/**
 * Turns the open tasks into the board's text and inline keyboard - the pure
 * "what the board looks like" half of BoardService, pulled out so posting and
 * editing the message (the lifecycle) reads separately from composing it.
 *
 * Each event is headed in the text (📅 date · name); the keyboard holds only
 * buttons that do something: the open tasks by role, three to a row
 * (taskRows()), the day buttons (private copies only), then the links at the
 * foot (footRows()). No
 * headings in the keyboard and no full tasks (Owner 2026-11-06: the board was
 * too tall). Boards posted before that still carry heading rows with
 * SPACER_DATA, which no handler acts on.
 *
 * Only one event day is shown at a time: the next one, unless another day was
 * picked from the row of day buttons under it (DayPicker, BoardService::PICK_PREFIX). On
 * a picked day the task buttons carry that day (t:<id>:<Y-m-d>), so the redraw
 * after a join or leave stays on it; on the next day they stay plain t:<id>.
 *
 * With the card format on (BoardService::CARDS_OPTION), nextCard() is the
 * photo message for the next event and crewBoard() the text board under it:
 * one day, with who is signed up, and the same row of day buttons.
 *
 * The group's message always shows the next event and has no day buttons
 * (Owner 2026-11-07): its 📅 More tasks link opens the next day in the
 * person's private chat (start=board-<Ymd>). A day button on a group board
 * posted before that still sends that day privately (BoardService::onPickDate()).
 */
final class BoardRenderer
{
    /**
     * The callback payload the heading rows of older boards carry.
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
     * The longest event heading, "Name · DJ X", in characters. A button label
     * cannot break a line, so the DJ line shares the name's line; longer than
     * this, the heading is the name alone, so a phone does not cut it short.
     */
    public const EVENT_HEADING_MAX = 40;

    /** Telegram's limit for a message's text, in UTF-16 units. */
    public const TEXT_LIMIT = 4096;

    public function __construct(
        private readonly TaskRepository $tasks
    ) {
    }

    /**
     * @param string $date The day to show (Y-m-d); '' or a day with no open
     *                     tasks shows the next one.
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    public function render(string $date = '', bool $group = true): array
    {
        $tasks = $this->boardTasks();

        if ([] === $tasks) {
            return $this->emptyBoard();
        }

        $dates = $this->dates($tasks);
        $shown = in_array($date, $dates, true) ? $date : $dates[0];

        return $this->day($tasks, $dates, $shown, $group);
    }

    /**
     * The board with the card format on (BoardService::CARDS_OPTION): the
     * text board that goes under the next event's card. It shows one day:
     * the next event, or $date when it has open tasks. The text lists, per
     * task, who is signed up, by first name; the keyboard has the day's
     * task buttons (taskRows()), the row of day buttons (DayPicker), and
     * the links at the foot. The group's message always shows the next event
     * (Owner, 2026-11-06: the board was too long with three); another day is
     * shown only in a private copy. The group's message has no day buttons
     * (Owner 2026-11-07): its More tasks link opens the next day in the private
     * chat instead.
     *
     * @param string $date  The day to show (Y-m-d); '' or a day with no open
     *                      tasks shows the next one.
     * @param bool   $group Whether this is the group's message, not a private copy.
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    public function crewBoard(string $date = '', bool $group = true): array
    {
        $tasks = $this->boardTasks();

        if ([] === $tasks) {
            return $this->emptyBoard();
        }

        $dates = $this->dates($tasks);
        $shown = in_array($date, $dates, true) ? $date : $dates[0];
        $daySuffix = $shown === $dates[0] ? '' : ':' . $shown;
        $tasks = array_values(array_filter($tasks, static fn (Task $t): bool => $t->taskDate === $shown));
        $occupants = $this->tasks->occupantsFor(array_map(static fn (Task $t): int => $t->id, $tasks));

        $lines = $this->introLines();
        $keyboard = [];
        $taken = [];

        foreach ($this->groupByEvent($tasks) as $heading) {
            $lines[] = '';
            // Date, then title, then an empty line before the tasks (Owner 2026-11-07).
            $lines[] = '📅 ' . $heading['date'];

            if ('' !== $heading['title']) {
                $lines[] = $heading['title'];
            }

            $lines[] = '';

            foreach ($heading['tasks'] as $task) {
                $names = $occupants[$task->id] ?? [];
                $taken[$task->id] = count($names);
                $lines[] = $this->taskLine($task, $names);
            }
        }

        foreach ($this->taskRows($tasks, $taken, $daySuffix) as $row) {
            $keyboard[] = $row;
        }

        if (! $group) {
            foreach (DayPicker::rows($dates, $shown, BoardService::PICK_PREFIX) as $row) {
                $keyboard[] = $row;
            }
        }

        foreach ($this->footRows($group ? $this->nextDay($dates, $shown) : '') as $row) {
            $keyboard[] = $row;
        }

        return ['text' => self::fit($lines), 'keyboard' => $keyboard];
    }

    /**
     * The card of the next event: the first EventMesh event with a picture on
     * the board's first day, or null when that day has none (or the board has
     * no tasks). Its caption is the card's name, date and price lines and,
     * instead of the event's description, the short text about its DJ or
     * host (BoardDjText) when one is written; no sign-up lines and no task
     * buttons, only the card's own links.
     *
     * @return array{
     *     event: int,
     *     photo: string,
     *     caption: string,
     *     buttons: array<int, array<int, array{text: string, url: string}>>
     * }|null
     */
    public function nextCard(): ?array
    {
        $event = $this->nextCardEvent();

        if (null === $event) {
            return null;
        }

        $card = EventCard::forEvent($event, BoardDjText::stored($event));

        if (null === $card) {
            return null;
        }

        // No 📱 Crew app button on the board's card (Owner 2026-11-07); the
        // /events cards keep it.
        $card['buttons'] = array_values(array_filter(
            $card['buttons'],
            static fn (array $row): bool => ! str_starts_with((string) ($row[0]['text'] ?? ''), '📱 ')
        ));

        return ['event' => $event] + $card;
    }

    /** The post id of the event nextCard() shows, or null. */
    public function nextCardEvent(): ?int
    {
        $tasks = $this->boardTasks();

        if ([] === $tasks) {
            return null;
        }

        $date = $this->dates($tasks)[0];
        $seen = [];

        foreach ($tasks as $task) {
            if ($task->taskDate !== $date || null === $task->eventPostId || isset($seen[$task->eventPostId])) {
                continue;
            }

            $seen[$task->eventPostId] = true;
            $card = EventCard::forEvent($task->eventPostId, '');

            if (null !== $card && '' !== $card['photo']) {
                return $task->eventPostId;
            }
        }

        return null;
    }

    /**
     * "🎨 Decorate · 17:00–18:30 · 2/3: Anna, Ben", or "…: –" when nobody is signed
     * up. Each person by the first word of their name, never their email.
     *
     * @param list<string> $names Display names, '' for someone who gave none.
     */
    private function taskLine(Task $task, array $names): string
    {
        $parts = [$task->roleDisplay()];
        $time = $task->timeRange();

        if ('' !== $time) {
            $parts[] = $time;
        }

        $first = array_map(
            static function (string $name): string {
                $word = (string) strtok(trim($name), " \t");

                return '' === $word ? __('someone', 'eventcrew') : $word;
            },
            $names
        );

        // The count moved here from the button, which has only the role.
        $parts[] = sprintf('%d/%d', count($names), $task->capacity);

        return implode(' · ', $parts) . ': ' . ([] === $first ? '–' : implode(', ', $first));
    }

    /**
     * $lines as one text within Telegram's TEXT_LIMIT, whole lines dropped
     * from the end when it is longer.
     *
     * @param array<int, string> $lines
     */
    private static function fit(array $lines): string
    {
        $text = implode("\n", $lines);

        while (count($lines) > 1 && EntityHtml::units($text) > self::TEXT_LIMIT) {
            array_pop($lines);
            $text = rtrim(implode("\n", $lines));
        }

        return $text;
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

    /**
     * The line at the top of the board. (Your own summary is the "See my
     * info" button at the foot.)
     *
     * @return array<int, string>
     */
    private function introLines(): array
    {
        return [
            __('Open tasks — tap one to sign up. To cancel, tap 👤 My info.', 'eventcrew'),
        ];
    }

    /**
     * One day of the board: the text and the keyboard.
     *
     * @param array<int, Task>   $tasks       Every task the board may show.
     * @param array<int, string> $dates       Every day of $tasks, ascending.
     * @param string             $shown       The day to show, Y-m-d.
     * @param bool               $group       The group's message: no day buttons.
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    private function day(array $tasks, array $dates, string $shown, bool $group): array
    {
        $tasks = array_values(array_filter($tasks, static fn (Task $t): bool => $t->taskDate === $shown));
        $daySuffix = $shown === $dates[0] ? '' : ':' . $shown;

        $occupancy = $this->tasks->occupancyFor(array_map(static fn (Task $t): int => $t->id, $tasks));
        $groups = $this->groupByEvent($tasks);

        $lines = $this->introLines();
        $keyboard = [];

        // The event's heading and each task's time and count go in the text;
        // the keyboard has only the task buttons (taskRows()).
        foreach ($groups as $heading) {
            $lines[] = '';
            // Date, then title, then an empty line before the tasks (Owner 2026-11-07).
            $lines[] = '📅 ' . $heading['date'];

            if ('' !== $heading['title']) {
                $lines[] = $heading['title'];
            }

            $lines[] = '';

            foreach ($heading['tasks'] as $task) {
                $lines[] = $this->countLine($task, $occupancy[$task->id] ?? 0);
            }
        }

        foreach ($this->taskRows($tasks, $occupancy, $daySuffix) as $row) {
            $keyboard[] = $row;
        }

        if (! $group) {
            foreach (DayPicker::rows($dates, $shown, BoardService::PICK_PREFIX) as $row) {
                $keyboard[] = $row;
            }
        }

        foreach ($this->footRows($group ? $this->nextDay($dates, $shown) : '') as $row) {
            $keyboard[] = $row;
        }

        return ['text' => implode("\n", $lines), 'keyboard' => $keyboard];
    }

    /**
     * The links at the foot of the board (Owner 2026-11-07): your own info,
     * what each task involves and, on the group's message, More tasks side
     * by side; then sign-up across the whole row at the bottom. No crew
     * guide. Only the links there are go in; [] when none.
     *
     * @param string $moreDay The day More tasks opens in the private chat
     *                        (Y-m-d); '' = no More tasks button.
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function footRows(string $moreDay = ''): array
    {
        $rows = [];

        // What each task involves, sent as a private message (Owner 2026-11-07).
        $descriptions = $this->deepLinkButton('tasks', __('📝 Task info', 'eventcrew'));

        $more = '' === $moreDay
            ? null
            : $this->deepLinkButton(
                BoardService::START_VERB . '-' . str_replace('-', '', $moreDay),
                __('📅 More tasks', 'eventcrew')
            );

        $links = array_values(array_filter([
            $this->deepLinkButton('me', __('👤 My info', 'eventcrew')),
            $descriptions,
            $more,
        ]));

        if ([] !== $links) {
            $rows[] = $links;
        }

        $signUp = $this->deepLinkButton('onboard', __('👋 New here? Sign up →', 'eventcrew'));

        if (null !== $signUp) {
            $rows[] = [$signUp];
        }

        // No crew guide on the board (Owner 2026-11-06).
        return $rows;
    }

    /**
     * The first day after $shown that has open tasks, or '' when there is none.
     *
     * @param array<int, string> $dates Every day of the board, ascending.
     */
    private function nextDay(array $dates, string $shown): string
    {
        foreach ($dates as $date) {
            if ($date > $shown) {
                return $date;
            }
        }

        return '';
    }

    /**
     * The task buttons, three to a row (Owner 2026-11-07), the last row filled
     * with blank buttons so every button is the same width: only the tasks
     * with a free place, each
     * by its role alone (the text has the times and who is in). The time is
     * added only when two open tasks share a role, to tell them apart.
     *
     * @param array<int, Task> $tasks
     * @param array<int, int>  $taken Places taken, by task id.
     * @return array<int, array<int, array<string, string>>>
     */
    private function taskRows(array $tasks, array $taken, string $daySuffix): array
    {
        $open = array_values(array_filter(
            $tasks,
            static fn (Task $t): bool => ($taken[$t->id] ?? 0) < $t->capacity
        ));
        $roles = array_count_values(array_map(static fn (Task $t): string => $t->roleDisplay(), $open));
        $buttons = [];

        foreach ($open as $task) {
            $label = $task->roleDisplay();

            if ($roles[$label] > 1 && '' !== $task->timeRange()) {
                $label .= ' · ' . $task->timeRange();
            }

            $buttons[] = ['text' => $label, 'callback_data' => 't:' . $task->id . $daySuffix];
        }

        $rows = array_chunk($buttons, 3);
        $last = count($rows) - 1;

        while ($last >= 0 && count($rows[$last]) < 3) {
            $rows[$last][] = ['text' => DayPicker::BLANK, 'callback_data' => self::SPACER_DATA];
        }

        return $rows;
    }

    /** "🎨 Decorate · 17:00–18:30 · 1/3", and "· full" when it is. */
    private function countLine(Task $task, int $taken): string
    {
        $parts = [$task->roleDisplay()];
        $time = $task->timeRange();

        if ('' !== $time) {
            $parts[] = $time;
        }

        $parts[] = sprintf('%d/%d', $taken, $task->capacity);

        if ($taken >= $task->capacity) {
            $parts[] = __('full', 'eventcrew');
        }

        return implode(' · ', $parts);
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
