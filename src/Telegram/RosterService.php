<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\Dates;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\Roles;
use EventCrew\Support\RosterAssembler;

/**
 * The bot side of the roster: /roster returns who is on what for the most
 * recent event day, or for the day named after it (/roster 1.8.).
 *
 * The answer always goes to the asker's private chat, never back to the group,
 * so the crew's attendance is not pinned up in public. It is gated on the
 * sender being either a linked organizer - a person marked is_organizer on the
 * People page whose Telegram is connected - or someone rostered on that day
 * themselves, so a bystander cannot pull the whole crew's attendance out of the
 * bot. Organizers additionally get one-tap buttons per person to mark them
 * completed, arrived late or no-show on the running event, the same write the
 * wp-admin roster offers, plus arrived (no credit) for someone still signed
 * up. Someone on that night's sales-desk task who is not an organizer gets
 * only the arrived button.
 *
 * Only organizers and that night's sales desk see each person's status,
 * standing and score, and the cancelled and no-show rows. Other crew rostered
 * that night see only the names of the people on each task.
 *
 * Under the roster, a row of buttons for the next event days switches the same
 * message to another day's roster (rd:<Y-m-d>), the way /door switches nights.
 *
 * Telegram takes at most 100 buttons and 4096 characters in one message, so a
 * big night is split into pages: the message shows one page, with previous and
 * next buttons (rd:<Y-m-d>:<page>) above the day buttons. A mark button on a
 * later page carries its page (rm:<id>:<mark>:<page>), so the edit after the
 * tap shows that same page again. A night that fits shows as before.
 */
final class RosterService
{
    use GroupBreadcrumb;

    /** Callback data prefix for a marking tap: rm:<assignment_id>:<a|c|l|n>[:<page>]. */
    private const MARK_PREFIX = 'rm:';

    /** Callback data prefix for a day or page button under the roster: rd:<Y-m-d>[:<page>]. */
    public const SELECT_PREFIX = 'rd:';

    /** How many buttons the date selector takes: ◀, the shown day, ▶. */
    private const PICKER_DATES = 3;

    /** Telegram's limit for inline buttons in one message. */
    private const MAX_BUTTONS = 100;

    /** Buttons kept free on every page for the day buttons and the previous/next row. */
    private const RESERVED_BUTTONS = self::PICKER_DATES + 2;

    /** Characters kept free on every page for the page note and the legend. */
    private const RESERVED_TEXT = 300;

    public function __construct(
        private readonly RosterAssembler $assembler,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly AssignmentRepository $assignments,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * @param string $payload An optional date after the command ("1.8." or
     *                        "2026-08-01"), read the same way the door list reads
     *                        it. Empty means the default day.
     */
    public function onRosterCommand(
        int $telegramUserId,
        int $chatId,
        bool $isPrivate = true,
        string $payload = ''
    ): void {
        // Refusals and nudges go back to wherever they asked, so they are visible
        // even from the group; only the roster itself - the sensitive part - goes
        // to the DM, with a breadcrumb in the group so the asker knows to look.
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person) {
            $this->telegram->sendMessage($chatId, $this->deniedMessage());

            return;
        }

        if ('' !== $payload) {
            $date = Dates::parseUserDate($payload, current_time('Y-m-d'));

            if ('' === $date) {
                $this->telegram->sendMessage($chatId, UnreadableDate::text());

                return;
            }
        } else {
            $date = $this->defaultDate();

            if ('' === $date) {
                $this->telegram->sendMessage($chatId, __('No tasks are scheduled yet.', 'eventcrew'));

                return;
            }
        }

        if (! $person->isOrganizer && ! $this->isRosteredOn($person->id, $date)) {
            $this->telegram->sendMessage($chatId, $this->deniedMessage());

            return;
        }

        $canMark = $person->isOrganizer || $this->isOnSalesDesk($person->id, $date);
        $rendered = $this->render($date, $this->assembler->forDate($date), $canMark, $person->isOrganizer);
        $keyboard = array_merge($rendered['keyboard'], $this->pickerRows($person, $date));
        $this->telegram->sendMessage($telegramUserId, $rendered['text'], $this->markup($keyboard));
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * A day button tap: shows that day's roster in the same message, with the
     * same access rule as /roster with a date typed after it.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $data = substr((string) ($callbackQuery['data'] ?? ''), strlen(self::SELECT_PREFIX));
        $valid = 1 === preg_match('#^(\d{4}-\d{2}-\d{2})(?::(\d{1,3}))?$#', $data, $matches);

        if (! $valid || 0 === $chatId || 0 === $messageId) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $date = $matches[1];
        $page = (int) ($matches[2] ?? 0);

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || (! $person->isOrganizer && ! $this->isRosteredOn($person->id, $date))) {
            $this->telegram->answerCallbackQuery($callbackId, $this->deniedMessage(), true);

            return;
        }

        $canMark = $person->isOrganizer || $this->isOnSalesDesk($person->id, $date);
        $rendered = $this->render($date, $this->assembler->forDate($date), $canMark, $person->isOrganizer, $page);
        $keyboard = array_merge($rendered['keyboard'], $this->pickerRows($person, $date));

        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->editMessageText($chatId, $messageId, $rendered['text'], ['inline_keyboard' => $keyboard]);
    }

    /**
     * Handles a marking button tap: an organizer setting one person arrived,
     * completed, arrived late or no-show, or that night's sales desk setting someone
     * still signed up to arrived. Anyone else, and any other mark by the sales
     * desk, is refused, and the roster message is edited in
     * place so the buttons and counts reflect the change without a new post.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onMark(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        [$assignmentId, $status, $page] = $this->parseMark((string) ($callbackQuery['data'] ?? ''));

        if (0 === $assignmentId || '' === $status || 0 === $telegramUserId) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person) {
            $this->telegram->answerCallbackQuery($callbackId, $this->deniedMarkMessage(), true);

            return;
        }

        $assignment = $this->assignments->find($assignmentId);

        if (null === $assignment) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That sign-up no longer exists. Send /roster again to see the current list.', 'eventcrew'),
                true
            );

            return;
        }

        // Organizers may always mark; someone on a sales-desk task may mark
        // their own night - which needs the task's date, so its lookup is
        // skipped for organizers.
        $canMark = $person->isOrganizer;

        if (! $canMark) {
            $task = $this->tasks->find($assignment->taskId);
            $canMark = null !== $task && $this->isOnSalesDesk($person->id, $task->taskDate);
        }

        if (! $canMark) {
            $this->telegram->answerCallbackQuery($callbackId, $this->deniedMarkMessage(), true);

            return;
        }

        // The sales desk may only mark someone still signed up as arrived: no
        // completed or arrived late (both earn a credit), no no-show, and no
        // changing a mark already made. Checked here, not only by which
        // buttons are shown, so a crafted tap cannot get past it.
        if (
            ! $person->isOrganizer
            && (AssignmentStatus::ARRIVED !== $status || AssignmentStatus::SIGNED_UP !== $assignment->status)
        ) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('The welcome crew can only mark people as arrived.', 'eventcrew'),
                true
            );

            return;
        }

        $this->assignments->setStatus($assignmentId, $status, $person->id, MarkedBy::PERSON);

        $this->telegram->answerCallbackQuery(
            $callbackId,
            match ($status) {
                AssignmentStatus::ARRIVED => __('Marked arrived.', 'eventcrew'),
                AssignmentStatus::COMPLETED => __('Marked completed.', 'eventcrew'),
                AssignmentStatus::ARRIVED_LATE => __('Marked arrived late.', 'eventcrew'),
                default => __('Marked no-show.', 'eventcrew'),
            }
        );

        $this->refreshRosterMessage($callbackQuery, $assignment->taskId, $person, $page);
    }

    private function deniedMessage(): string
    {
        return __('Only organizers or people rostered on the day can see the roster.', 'eventcrew');
    }

    private function deniedMarkMessage(): string
    {
        return __('Only organizers and that night’s welcome crew can mark attendance.', 'eventcrew');
    }

    /**
     * Whether this person is on that date's task of a role with "Sales desk"
     * ticked - what lets someone who is not an organizer mark that night's crew.
     */
    private function isOnSalesDesk(int $personId, string $date): bool
    {
        foreach ($this->tasks->forDate($date) as $task) {
            if (! Roles::opensSalesDesk($task->roleSlug)) {
                continue;
            }

            $assignment = $this->assignments->findFor($task->id, $personId);

            if (null !== $assignment && $assignment->isOccupying()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rebuilds the roster message the tapped button belongs to and edits it in
     * place, so a just-marked person drops out of the "still expected" buttons
     * and the taken counts move. The date comes from the marked task, not the
     * default, so it stays correct even if the default day has since rolled on.
     * $page is the roster page the tapped button was on.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function refreshRosterMessage(array $callbackQuery, int $taskId, Person $person, int $page): void
    {
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        $task = $this->tasks->find($taskId);
        $date = null === $task ? '' : $task->taskDate;

        if ('' === $date) {
            return;
        }

        $rendered = $this->render($date, $this->assembler->forDate($date), true, $person->isOrganizer, $page);
        // An empty inline_keyboard clears the buttons when nobody is left to
        // mark, rather than leaving stale taps on an already-closed event.
        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            $rendered['text'],
            ['inline_keyboard' => array_merge($rendered['keyboard'], $this->pickerRows($person, $date))]
        );
    }

    /**
     * Splits "rm:<id>:<a|c|l|n>[:<page>]" into the assignment id, the status
     * it maps to and the roster page the button was on (0 when the data names
     * none), or [0, '', 0] when the data is not a marking tap.
     *
     * @return array{int, string, int}
     */
    private function parseMark(string $data): array
    {
        $pattern = '#^' . preg_quote(self::MARK_PREFIX, '#') . '(\d+):([acln])(?::(\d{1,3}))?$#';

        if (1 !== preg_match($pattern, $data, $matches)) {
            return [0, '', 0];
        }

        return [
            (int) $matches[1],
            match ($matches[2]) {
                'a' => AssignmentStatus::ARRIVED,
                'c' => AssignmentStatus::COMPLETED,
                'l' => AssignmentStatus::ARRIVED_LATE,
                default => AssignmentStatus::NO_SHOW,
            },
            (int) ($matches[3] ?? 0),
        ];
    }

    /**
     * @param array<int, array<int, array{text: string, callback_data: string}>> $keyboard
     * @return array{inline_keyboard: array<int, mixed>}|null
     */
    private function markup(array $keyboard): ?array
    {
        return [] === $keyboard ? null : ['inline_keyboard' => $keyboard];
    }

    /**
     * The date selector under the roster (DayPicker): the shown day marked,
     * with arrows to the days before and after it. Someone who is not an
     * organizer steps only through the days they are rostered on, since those
     * are the only ones they may open. Then, for an organizer, Back to Admin.
     *
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    private function pickerRows(Person $person, string $shown): array
    {
        $rows = DayPicker::rows(
            $this->tasks->datesWithTasks(),
            $shown,
            self::SELECT_PREFIX,
            $person->isOrganizer ? null : fn (string $date): bool => $this->isRosteredOn($person->id, $date)
        );

        // Roster is under Admin, an organizer's menu: Back goes there, below
        // the day buttons, however the roster was opened.
        if ($person->isOrganizer) {
            $rows[] = MenuButtons::backRow(MenuButtons::ADMIN);
        }

        return $rows;
    }

    /**
     * Whether this person holds a live slot on the given date - the test that
     * lets a rostered crew member, not just an organizer, pull the day's list.
     */
    private function isRosteredOn(int $personId, string $date): bool
    {
        return in_array($personId, $this->assignments->personIdsAssignedOn($date), true);
    }

    /**
     * The nearest upcoming day that has tasks, falling back to the most recent
     * past one. Same rule the wp-admin roster defaults to.
     */
    private function defaultDate(): string
    {
        return DayPicker::defaultDate($this->tasks->datesWithTasks(), (string) current_time('Y-m-d'));
    }

    /**
     * The roster as a message. Someone who may mark ($canMark: an organizer or
     * that night's sales desk) sees every row with its status, standing and
     * sign-up time; other crew see only the names of the people on each task.
     * For someone who may mark it also carries a keyboard of per-person mark buttons:
     * done, late and no-show for an organizer, plus arrived on a row of its own
     * above them for someone still signed up (four buttons on one row cut the
     * names short on a phone), and only arrived for the sales desk.
     * Organizers-only tasks are left out unless the viewer is an organizer
     * ($isOrganizer).
     *
     * A roster too big for one Telegram message is split into pages, and only
     * page $page (from 0; past the end means the last) is returned, with
     * "page 2 of 3" in its first line and previous/next buttons under its mark
     * buttons. The caller adds the day buttons below.
     *
     * @param array<int, array{task: \EventCrew\Models\Task, people: array<int, array{
     *     assignment_id: int, name: string, status: string,
     *     status_label: string, occupying: bool, marked_by: string, signed_up_at?: string}>}> $roster
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    private function render(string $date, array $roster, bool $canMark, bool $isOrganizer, int $page = 0): array
    {
        $pages = $this->paginate($this->blocks($roster, $canMark, $isOrganizer));
        $count = count($pages);
        $page = max(0, min($page, $count - 1));
        $lines = $pages[$page]['lines'];
        $keyboard = $pages[$page]['keyboard'];
        $title = '📋 ' . $this->dateLabel($date);

        if ($count > 1) {
            $title .= MessagePages::note($page, $count);
        }

        // A mark button on a later page carries the page, so the edit after
        // the tap shows the same page.
        if ($page > 0) {
            $keyboard = array_map(
                static fn (array $row): array => array_map(
                    static fn (array $button): array => ['callback_data' => $button['callback_data'] . ':' . $page]
                        + $button,
                    $row
                ),
                $keyboard
            );
        }

        $lines = array_merge([$title], $lines);

        // The mark buttons carry only a symbol and a name, so say what each
        // symbol means whenever there are buttons to tap.
        if ([] !== $keyboard) {
            $lines[] = '';
            $lines[] = $isOrganizer
                ? __('👋 arrived · ✓ done · ⏰ late · ✗ no-show', 'eventcrew')
                : __('👋 arrived', 'eventcrew');
        }

        if ($count > 1) {
            $keyboard[] = MessagePages::buttons(self::SELECT_PREFIX . $date, $page, $count);
        }

        return ['text' => implode("\n", $lines), 'keyboard' => $keyboard];
    }

    /**
     * The roster cut where a page break may fall: per task its heading lines,
     * then one piece per person (their lines and their mark buttons), or a
     * single "nobody yet" piece.
     *
     * @param array<int, array{task: \EventCrew\Models\Task, people: array<int, array<string, mixed>>}> $roster
     * @return array<int, array{heading: array<int, string>, pieces: array<int, array{
     *     lines: array<int, string>, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}>}>
     */
    private function blocks(array $roster, bool $canMark, bool $isOrganizer): array
    {
        $blocks = [];

        foreach ($roster as $row) {
            $task = $row['task'];

            if (! $isOrganizer && $task->isOrganizersOnly()) {
                continue;
            }

            $taken = count(array_filter($row['people'], static fn (array $p): bool => $p['occupying']));
            $heading = [
                '',
                $task->hasNoLimit()
                    ? sprintf('%s — %s (%d)', $task->roleDisplay(), $task->eventName(), $taken)
                    : sprintf('%s — %s (%d/%d)', $task->roleDisplay(), $task->eventName(), $taken, $task->capacity),
            ];

            // Crew who may not mark see only who is on the task: names, no
            // status, standing or sign-up time, and no cancelled or no-show rows.
            $people = $canMark
                ? $row['people']
                : array_values(array_filter($row['people'], static fn (array $p): bool => $p['occupying']));

            if ([] === $people) {
                $blocks[] = [
                    'heading' => $heading,
                    'pieces' => [['lines' => ['  ' . __('— nobody yet', 'eventcrew')], 'keyboard' => []]],
                ];

                continue;
            }

            $pieces = [];

            foreach ($people as $person) {
                if (! $canMark) {
                    $pieces[] = ['lines' => ['  • ' . $person['name']], 'keyboard' => []];

                    continue;
                }

                $mark = $person['occupying'] ? '•' : '×';
                $standing = $person['standing'] ?? null;
                $reliability = null === $standing ? '' : ' — ' . $standing->ratedSummary();
                $lines = [sprintf(
                    '  %s %s (%s)%s%s',
                    $mark,
                    $person['name'],
                    $person['status_label'],
                    $reliability,
                    $this->signedUpNote((string) ($person['signed_up_at'] ?? ''))
                )];

                if ('' !== ($person['marked_by'] ?? '')) {
                    $lines[] = '    ' . $person['marked_by'];
                }

                $keyboard = [];

                if ($canMark && $isOrganizer && $this->isMarkable($person['status'])) {
                    if (AssignmentStatus::SIGNED_UP === $person['status']) {
                        $keyboard[] = [$this->arrivedButton($person['assignment_id'], $person['name'])];
                    }

                    $keyboard[] = $this->markButtons($person['assignment_id'], $person['name']);
                } elseif ($canMark && ! $isOrganizer && AssignmentStatus::SIGNED_UP === $person['status']) {
                    $keyboard[] = [$this->arrivedButton($person['assignment_id'], $person['name'])];
                }

                $pieces[] = ['lines' => $lines, 'keyboard' => $keyboard];
            }

            $blocks[] = ['heading' => $heading, 'pieces' => $pieces];
        }

        return $blocks;
    }

    /**
     * Fills pages with the pieces in order, starting a new page when the next
     * piece would take this one past Telegram's button or text limit, less
     * the room kept for the day buttons, the page buttons, the page note and
     * the legend. A task cut by a page break repeats its heading on the next
     * page. Always at least one page, so a day with no tasks keeps its title.
     *
     * @param array<int, array{heading: array<int, string>, pieces: array<int, array{
     *     lines: array<int, string>,
     *     keyboard: array<int, array<int, array{text: string, callback_data: string}>>}>}> $blocks
     * @return array<int, array{lines: array<int, string>,
     *     keyboard: array<int, array<int, array{text: string, callback_data: string}>>}>
     */
    private function paginate(array $blocks): array
    {
        $maxButtons = self::MAX_BUTTONS - self::RESERVED_BUTTONS;
        $maxText = MessagePages::MAX_TEXT - self::RESERVED_TEXT;
        $empty = ['lines' => [], 'keyboard' => [], 'buttons' => 0, 'length' => 0];
        $pages = [];
        $page = $empty;

        foreach ($blocks as $block) {
            foreach ($block['pieces'] as $index => $piece) {
                $buttons = array_sum(array_map('count', $piece['keyboard']));
                $lines = 0 === $index ? array_merge($block['heading'], $piece['lines']) : $piece['lines'];
                $full = $page['buttons'] + $buttons > $maxButtons
                    || $page['length'] + MessagePages::textLength($lines) > $maxText;

                if ($full && [] !== $page['lines']) {
                    $pages[] = $page;
                    $page = $empty;
                    $lines = array_merge($block['heading'], $piece['lines']);
                }

                $page['lines'] = array_merge($page['lines'], $lines);
                $page['keyboard'] = array_merge($page['keyboard'], $piece['keyboard']);
                $page['buttons'] += $buttons;
                $page['length'] += MessagePages::textLength($lines);
            }
        }

        $pages[] = $page;

        return array_map(
            static fn (array $p): array => ['lines' => $p['lines'], 'keyboard' => $p['keyboard']],
            $pages
        );
    }

    /**
     * Buttons only for people still expected - signed up or arrived, not yet
     * closed out. A completed or cancelled row has nothing left to mark.
     */
    private function isMarkable(string $status): bool
    {
        return in_array($status, [AssignmentStatus::SIGNED_UP, AssignmentStatus::ARRIVED], true);
    }

    /**
     * @return array<int, array{text: string, callback_data: string}>
     */
    private function markButtons(int $assignmentId, string $name): array
    {
        return [
            [
                /* translators: %s: person's name */
                'text' => sprintf(__('✓ %s', 'eventcrew'), $name),
                'callback_data' => self::MARK_PREFIX . $assignmentId . ':c',
            ],
            [
                /* translators: %s: person's name */
                'text' => sprintf(__('⏰ %s', 'eventcrew'), $name),
                'callback_data' => self::MARK_PREFIX . $assignmentId . ':l',
            ],
            [
                /* translators: %s: person's name */
                'text' => sprintf(__('✗ %s', 'eventcrew'), $name),
                'callback_data' => self::MARK_PREFIX . $assignmentId . ':n',
            ],
        ];
    }

    /**
     * The arrived button for a person still signed up - the only one the
     * sales desk gets, and one more for an organizer. It earns no credit.
     *
     * @return array{text: string, callback_data: string}
     */
    private function arrivedButton(int $assignmentId, string $name): array
    {
        return [
            /* translators: %s: person's name */
            'text' => sprintf(__('👋 %s', 'eventcrew'), $name),
            'callback_data' => self::MARK_PREFIX . $assignmentId . ':a',
        ];
    }

    /**
     * " · signed up 2 Nov 14:05", or '' for a row with no sign-up time. The
     * time is formatted by Dates::signedUpLabel, as on the crew page roster.
     */
    private function signedUpNote(string $signedUpAt): string
    {
        $when = Dates::signedUpLabel($signedUpAt);

        if ('' === $when) {
            return '';
        }

        /* translators: %s: when the person signed up, e.g. "2 Nov 14:05" */
        return ' · ' . sprintf(__('signed up %s', 'eventcrew'), $when);
    }

    private function dateLabel(string $date): string
    {
        return Dates::dayLabel($date);
    }
}
