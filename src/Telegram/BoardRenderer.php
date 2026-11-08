<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\BoardVisibility;
use EventCrew\Support\Dates;
use EventCrew\Support\EventName;
use EventCrew\Support\RoleTaskService;

/**
 * Turns the open tasks into the board's text and inline keyboard - the pure
 * "what the board looks like" half of BoardService, pulled out so posting and
 * editing the message (the lifecycle) reads separately from composing it.
 *
 * Each event is headed in the text (📅 date · name); the keyboard holds only
 * buttons that do something: the open tasks by role, three to a row
 * (taskButtons()), the day buttons (private copies only), then the links at the
 * foot (footRows()). No
 * headings in the keyboard and no full tasks (Owner 2026-11-06: the board was
 * too tall). Boards posted before that still carry heading rows with
 * SPACER_DATA, which no handler acts on.
 *
 * The buttons are for one day at a time: the next one, unless another day was
 * picked from the row of day buttons under it (DayPicker, BoardService::PICK_PREFIX),
 * under a full-width button naming that day. The text shows that day only
 * (TEXT_DAYS, Owner 2026-11-07). On a picked day the task buttons
 * carry that day (t:<id>:<Y-m-d>), so the redraw after a join or leave stays
 * on it; on the next day they stay plain t:<id>.
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

    /**
     * How many days the text shows. It was the shown day and the next two
     * (Q365); Owner 2026-11-07 later: the selected day only.
     */
    public const TEXT_DAYS = 1;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ?RoleTaskService $roleTasks = null
    ) {
    }

    /**
     * The board in the plain format (BoardService::CARDS_OPTION off): per
     * task its time and count, no names.
     *
     * @param string      $date   The day to show (Y-m-d); '' or a day with no
     *                            open tasks shows the next one.
     * @param bool        $group  Whether this is the group's message, not a private copy.
     * @param Person|null $viewer The person a private copy is for: the tasks
     *                            of their roles are added (roleButtons()). Not
     *                            used on the group's message.
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    public function render(string $date = '', bool $group = true, ?Person $viewer = null): array
    {
        return $this->compose($date, $group, $group ? null : $viewer, false);
    }

    /**
     * The board with the card format on (BoardService::CARDS_OPTION): the
     * text board that goes under the next event's card. It shows one day:
     * the next event, or $date when it has open tasks. The text lists, per
     * task, who is signed up, by first name, on the line under the task; the
     * keyboard has the day's task buttons (taskButtons()), the row of day
     * buttons (DayPicker), and the links at the foot. The group's message
     * always shows the next event (Owner, 2026-11-06: the board was too long
     * with three); another day is shown only in a private copy. The group's
     * message has no day buttons (Owner 2026-11-07): its More tasks link opens
     * the next day in the private chat instead.
     *
     * A private copy for a known person ($viewer) also lists the tasks of
     * their roles, which the group's message never shows, under the same
     * event headings, with the ✋ / ❌ / ➕ buttons of RoleTaskBot under the
     * board's own task buttons (roleButtons()). Its day buttons include the days
     * that have only such tasks.
     *
     * @param string      $date   The day to show (Y-m-d); '' or a day with no open
     *                            tasks shows the next one.
     * @param bool        $group  Whether this is the group's message, not a private copy.
     * @param Person|null $viewer The person a private copy is for. Not used on
     *                            the group's message.
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    public function crewBoard(string $date = '', bool $group = true, ?Person $viewer = null): array
    {
        return $this->compose($date, $group, $group ? null : $viewer, true);
    }

    /**
     * One day of the board, in either format: the text and the keyboard.
     *
     * @param bool $withNames The card format: who is signed up, under each
     *                        task. Otherwise the count line (countLine()).
     * @return array{text: string, keyboard: array<int, array<int, array<string, mixed>>>}
     */
    private function compose(string $date, bool $group, ?Person $viewer, bool $withNames): array
    {
        // The group's board is the guest board, whoever asked for it.
        [$tasks, $days, $extra, $addable] = $this->viewTasks($group ? null : $viewer);

        if ([] === $days) {
            return $this->emptyBoard();
        }

        $dates = $this->dates($days);
        $shown = in_array($date, $dates, true) ? $date : $dates[0];
        $daySuffix = $shown === $dates[0] ? '' : ':' . $shown;
        // The text and the buttons are for the shown day (TEXT_DAYS).
        $textDates = array_slice($dates, (int) array_search($shown, $dates, true), self::TEXT_DAYS);
        $listed = array_values(array_filter($tasks, static fn (Task $t): bool => in_array($t->taskDate, $textDates, true)));
        $tasks = array_values(array_filter($listed, static fn (Task $t): bool => $t->taskDate === $shown));
        $ids = array_map(static fn (Task $t): int => $t->id, $listed);
        $occupants = [];

        if ($withNames) {
            $occupants = $this->tasks->occupantsFor($ids);
            $taken = array_map('count', $occupants);
        } else {
            $taken = $this->tasks->occupancyFor($ids);
        }

        // A task whose role has Guest sign up off has no button for a guest
        // (BoardVisibility::blocksGuestSignup()): on the group's message for
        // nobody, on a private copy unless the reader holds the role or is an
        // organizer.
        $guest = array_values(array_filter(
            $tasks,
            static fn (Task $t): bool => BoardVisibility::onGuestBoard($t) && ! BoardVisibility::blocksGuestSignup($t, $viewer)
        ));
        $own = array_values(array_filter($tasks, static fn (Task $t): bool => isset($extra[$t->id])));
        $role = null === $viewer || $group ? ['buttons' => [], 'legend' => ''] : $this->roleButtons($own, $addable, $taken, $viewer);

        $lines = $this->introLines();

        // The event's heading and each task's time and count go in the text;
        // the keyboard has only the task buttons (taskButtons()).
        foreach ($this->groupByEvent($listed) as $heading) {
            // One empty line before a heading; a task already ends with one.
            if (! str_ends_with((string) end($lines), "\n")) {
                $lines[] = '';
            }

            // Date, then title, then an empty line before the tasks (Owner 2026-11-07).
            $lines[] = '📅 ' . $heading['date'];

            if ('' !== $heading['title']) {
                $lines[] = $heading['title'];
            }

            $lines[] = '';

            // An empty line after each task (Owner 2026-11-07). fit() drops
            // whole entries, so the task and its empty line are one entry.
            foreach ($heading['tasks'] as $task) {
                // A task nobody has signed up for is left out of the text;
                // its button stays (Owner 2026-11-07).
                if ($withNames && [] === ($occupants[$task->id] ?? [])) {
                    continue;
                }

                $lines[] = ($withNames
                    ? $this->taskLine($task, $occupants[$task->id] ?? [], $viewer)
                    : $this->countLine($task, $taken[$task->id] ?? 0, $viewer)) . "\n";
            }
        }

        // The guest board's buttons and the special-task ones in one run,
        // blank buttons only on the last row: no gap between them (Owner
        // 2026-11-07). Above them, across the whole row, the day they are
        // for, as the text now shows more days than the buttons (Q365).
        $buttons = array_merge($this->taskButtons($guest, $taken, $daySuffix), $role['buttons']);
        $keyboard = [] === $buttons ? [] : [[self::dayButton($shown)]];

        foreach (self::rowsOfThree($buttons) as $row) {
            $keyboard[] = $row;
        }

        if (! $group) {
            foreach (DayPicker::rows($dates, $shown, BoardService::PICK_PREFIX) as $row) {
                $keyboard[] = $row;
            }
        }

        foreach ($this->footRows($group ? $this->nextDay($dates, $shown) : '', $group) as $row) {
            $keyboard[] = $row;
        }

        // The legend of the special-task buttons under the tasks, next to the
        // buttons it explains (Owner 2026-11-07); kept when fit() shortens.
        return ['text' => self::fit($lines, $role['legend']), 'keyboard' => $keyboard];
    }

    /**
     * The full-width "📅 Sat 14 Nov" over the task buttons: it only names
     * their day, so a tap does nothing (SPACER_DATA). The day picker under
     * them changes the day.
     *
     * @return array{text: string, callback_data: string}
     */
    private static function dayButton(string $date): array
    {
        return ['text' => '📅 ' . Dates::dayLabel($date), 'callback_data' => self::SPACER_DATA];
    }

    /**
     * What one board reads, from one upcoming() call, in that order:
     * - every task $viewer may see (BoardVisibility::visibleTo()), the lines
     *   of the text; without a viewer only the guest board's;
     * - the tasks whose days the board shows: the guest board's, and on a
     *   private copy also the ones $viewer can act on;
     * - the ids of the special tasks $viewer can act on (a restricted task of
     *   their role, or for an organizer any they can add someone to), which
     *   get RoleTaskBot's buttons (roleButtons());
     * - the ids $viewer, an organizer, can add someone to.
     * Without a viewer (or without RoleTaskService) the last two are empty.
     *
     * @return array{0: array<int, Task>, 1: array<int, Task>, 2: array<int, true>, 3: array<int, true>}
     */
    private function viewTasks(?Person $viewer): array
    {
        // Only what this reader may see (BoardVisibility): the group board
        // (no viewer) only the guest board's tasks, a member also the tasks of
        // their roles, an organizer every task; an archived role's tasks
        // never (Owner 2026-11-07, replaces the special-task lines of Q362).
        $upcoming = array_values(array_filter(
            $this->tasks->upcoming(),
            static fn (Task $t): bool => BoardVisibility::visibleTo($t, $viewer)
        ));
        $extra = [];
        $addable = [];

        if (null !== $viewer && null !== $this->roleTasks) {
            // The same tasks RoleTaskBot's list had: the person's restricted
            // roles, and for an organizer every task they can add someone to.
            foreach (RoleTaskService::ownOf($viewer, $upcoming) as $task) {
                $extra[$task->id] = true;
            }

            if ($viewer->isOrganizer) {
                foreach (RoleTaskService::addableOf($upcoming) as $task) {
                    $addable[$task->id] = true;
                    $extra[$task->id] = true;
                }
            }
        }

        $days = [];

        foreach ($upcoming as $task) {
            if (BoardVisibility::onGuestBoard($task)) {
                // Already on the board with its own button: no ➕ for it.
                unset($extra[$task->id], $addable[$task->id]);
                $days[] = $task;
            } elseif (isset($extra[$task->id])) {
                $days[] = $task;
            }
        }

        return [$upcoming, $days, $extra, $addable];
    }

    /**
     * The buttons for the tasks of the viewer's roles on the shown day, the
     * callbacks RoleTaskBot answers: ✋ sign up (a restricted task of a role
     * they hold, with a free place), ❌ cancel (one they are on), ➕ add
     * someone (an organizer, on a task with a free place). By role, as the
     * board's own buttons, three to a row; and the one-line legend for them,
     * '' when there are none.
     *
     * @param array<int, Task> $tasks
     * @param array<int, true> $addable
     * @param array<int, int>  $taken
     * @return array{buttons: array<int, array<string, string>>, legend: string}
     */
    private function roleButtons(array $tasks, array $addable, array $taken, Person $viewer): array
    {
        $roles = array_count_values(array_map(static fn (Task $t): string => $t->roleDisplay(), $tasks));
        $buttons = [];
        $own = false;
        $add = false;

        foreach ($tasks as $task) {
            $label = $task->roleDisplay();

            if ($roles[$label] > 1 && '' !== $task->timeRange()) {
                $label .= ' · ' . $task->timeRange();
            }

            // A task with no limit on people always has a place.
            $free = $task->hasNoLimit() || ($taken[$task->id] ?? 0) < $task->capacity;

            if ($task->isRestricted() && $viewer->hasRole($task->roleSlug)) {
                if (null !== $this->roleTasks && $this->roleTasks->isOn($task->id, $viewer->id)) {
                    $buttons[] = ['text' => '❌ ' . $label, 'callback_data' => RoleTaskBot::PREFIX . 'c:' . $task->id];
                    $own = true;
                } elseif ($free) {
                    $buttons[] = ['text' => '✋ ' . $label, 'callback_data' => RoleTaskBot::PREFIX . 's:' . $task->id];
                    $own = true;
                }
            }

            if (isset($addable[$task->id]) && $free) {
                $buttons[] = ['text' => '➕ ' . $label, 'callback_data' => RoleTaskBot::PREFIX . 'a:' . $task->id];
                $add = true;
            }
        }

        $legend = [];

        if ($own) {
            $legend[] = __('✋ sign up · ❌ cancel', 'eventcrew');
        }

        if ($add) {
            $legend[] = __('➕ add someone', 'eventcrew');
        }

        return ['buttons' => $buttons, 'legend' => implode(' · ', $legend)];
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
        return $this->nextCardTarget()['event'] ?? null;
    }

    /**
     * The event nextCard() shows and its day (Y-m-d), or null.
     *
     * @return array{event: int, date: string, photo: string}|null
     */
    public function nextCardTarget(): ?array
    {
        return self::cardTarget($this->boardTasks());
    }

    /**
     * The picture of the board's next-event card for $upcoming (as
     * TaskRepository::upcoming() returns them), or '' when there is none:
     * the same picture the group gets as its photo message. The crew page
     * shows it behind its sign-in card, from the tasks it has already read.
     *
     * @param array<int, Task> $upcoming
     */
    public static function nextCardPhoto(array $upcoming): string
    {
        return self::cardTarget(self::onGuestBoard($upcoming))['photo'] ?? '';
    }

    /**
     * The first event with a picture on the first day of $tasks, its day and
     * its picture, or null.
     *
     * @param array<int, Task> $tasks
     * @return array{event: int, date: string, photo: string}|null
     */
    private static function cardTarget(array $tasks): ?array
    {
        if ([] === $tasks) {
            return null;
        }

        $date = self::dates($tasks)[0];
        $seen = [];

        foreach ($tasks as $task) {
            if ($task->taskDate !== $date || null === $task->eventPostId || isset($seen[$task->eventPostId])) {
                continue;
            }

            $seen[$task->eventPostId] = true;
            $card = EventCard::forEvent($task->eventPostId, '');

            if (null !== $card && '' !== $card['photo']) {
                return ['event' => $task->eventPostId, 'date' => $date, 'photo' => $card['photo']];
            }
        }

        return null;
    }

    /**
     * "🎨 Decorate · 17:00–18:30 · 2/3", and on the next line who is signed
     * up, "Anna, Ben", or "–" when nobody is (Owner 2026-11-07). Each person by
     * the first word of their name, never their email. One string with the
     * line break inside, so fit() drops a task and its names together.
     *
     * A role with Anonymous display on shows a guest "2 signed up" in place
     * of the names; one with Guest sign up off adds "crew only" to the first
     * line for a guest (BoardVisibility). $viewer null: the group's message.
     *
     * @param list<string> $names Display names, '' for someone who gave none.
     */
    private function taskLine(Task $task, array $names, ?Person $viewer = null): string
    {
        $parts = [$task->roleDisplay()];
        $time = $task->timeRange();

        if ('' !== $time) {
            $parts[] = $time;
        }

        $first = self::firstNames($names);

        // The count moved here from the button, which has only the role. No
        // count for a task with no limit on people (Owner 2026-11-07).
        if (! $task->hasNoLimit()) {
            $parts[] = sprintf('%d/%d', count($names), $task->capacity);
        }

        if (BoardVisibility::blocksGuestSignup($task, $viewer)) {
            $parts[] = self::crewOnly();
        }

        if ([] === $first) {
            $who = '–';
        } elseif (! BoardVisibility::showsNames($task, $viewer)) {
            /* translators: %d: people signed up so far, for a task with no limit on people */
            $who = sprintf(__('%d signed up', 'eventcrew'), count($first));
        } else {
            $who = implode(', ', $first);
        }

        return implode(' · ', $parts) . "\n" . $who;
    }

    /**
     * Each name by its first word, "someone" for a person who gave none:
     * how the boards name who is signed up, never by email. The web board
     * uses it too.
     *
     * @param list<string> $names Display names.
     * @return list<string>
     */
    public static function firstNames(array $names): array
    {
        return array_map(
            static function (string $name): string {
                $word = (string) strtok(trim($name), " \t");

                return '' === $word ? __('someone', 'eventcrew') : $word;
            },
            array_values($names)
        );
    }

    /**
     * The mark on a task line that a guest cannot sign up for it: the
     * board does not invite them to.
     */
    private static function crewOnly(): string
    {
        return __('crew only', 'eventcrew');
    }

    /**
     * $lines as one text within Telegram's TEXT_LIMIT, whole entries dropped
     * from the end when it is longer (a task and its names line are one).
     * $tail (the legend) goes after them after an empty line and is never
     * dropped.
     *
     * @param array<int, string> $lines
     */
    private static function fit(array $lines, string $tail = ''): string
    {
        $join = static function (array $lines) use ($tail): string {
            $text = rtrim(implode("\n", $lines));

            return '' === $tail ? $text : $text . "\n\n" . $tail;
        };
        $text = $join($lines);

        while (count($lines) > 1 && EntityHtml::units($text) > self::TEXT_LIMIT) {
            array_pop($lines);
            $text = $join($lines);
        }

        return $text;
    }

    /**
     * The guest board's tasks: the ones with a button on the group's message.
     *
     * @return array<int, Task>
     */
    private function boardTasks(): array
    {
        // The tasks with a button on the group's message, and the days it
        // shows. A restricted task (Organize, DJ, ...) or one of a role
        // switched off "Show on the guest board" is not on it at all (Owner
        // 2026-11-07): the people holding its role take it on
        // the crew page or in their private copy of the board. The web page
        // shows the same tasks when signed out (BoardVisibility).
        return self::onGuestBoard($this->tasks->upcoming());
    }

    /**
     * @param array<int, Task> $tasks
     * @return list<Task> Those of $tasks on the group's board.
     */
    private static function onGuestBoard(array $tasks): array
    {
        return array_values(array_filter(
            $tasks,
            static fn (Task $task): bool => BoardVisibility::onGuestBoard($task)
        ));
    }

    /**
     * @param array<int, Task> $tasks
     * @return array<int, string> Every day of $tasks, Y-m-d, ascending.
     */
    private static function dates(array $tasks): array
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
     * The links at the foot of the board (Owner 2026-11-07): your own info,
     * what each task involves and, on the group's message, More tasks side
     * by side; then sign-up across the whole row at the bottom. No crew
     * guide. Only the links there are go in; [] when none.
     *
     * @param string $moreDay The day More tasks opens in the private chat
     *                        (Y-m-d); '' = no More tasks button.
     * @param bool $group Whether this is the group's board. The private copy
     *                    has no sign-up row (Owner 2026-11-07): there a tap on a
     *                    task asks a newcomer to set up first anyway.
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function footRows(string $moreDay = '', bool $group = true): array
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

        $signUp = $group ? $this->deepLinkButton('onboard', __('👋 New here? Sign up →', 'eventcrew')) : null;

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
     * @return array<int, array<string, string>>
     */
    private function taskButtons(array $tasks, array $taken, string $daySuffix): array
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

        return $buttons;
    }

    /**
     * $buttons three to a row, the last row filled with blank buttons so
     * every button is the same width.
     *
     * @param array<int, array<string, string>> $buttons
     * @return array<int, array<int, array<string, string>>>
     */
    private static function rowsOfThree(array $buttons): array
    {
        $rows = array_chunk($buttons, 3);
        $last = count($rows) - 1;

        while ($last >= 0 && count($rows[$last]) < 3) {
            $rows[$last][] = ['text' => DayPicker::BLANK, 'callback_data' => self::SPACER_DATA];
        }

        return $rows;
    }

    /**
     * "🎨 Decorate · 17:00–18:30 · 1/3", and "· full" when it is, or
     * "· crew only" for a guest on a role with Guest sign up off.
     */
    private function countLine(Task $task, int $taken, ?Person $viewer = null): string
    {
        $parts = [$task->roleDisplay()];
        $time = $task->timeRange();

        if ('' !== $time) {
            $parts[] = $time;
        }

        if (! $task->hasNoLimit()) {
            $parts[] = sprintf('%d/%d', $taken, $task->capacity);

            if ($taken >= $task->capacity) {
                $parts[] = __('full', 'eventcrew');
            }
        }

        if (BoardVisibility::blocksGuestSignup($task, $viewer)) {
            $parts[] = self::crewOnly();
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
            // By day too: the text shows more than one day (TEXT_DAYS).
            $key = null !== $task->eventPostId
                ? 'e:' . $task->eventPostId . '|' . $task->taskDate
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
            'url' => 'https://t.me/' . $username . '?start=' . rawurlencode(StartCode::append($payload)),
        ];
    }
}
