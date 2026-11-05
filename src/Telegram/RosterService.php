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
 * wp-admin roster offers. Someone on that night's sales-desk task who is not an
 * organizer gets one button per person instead: arrived, which earns no credit.
 *
 * Under the roster, a row of buttons for the next event days switches the same
 * message to another day's roster (rd:<Y-m-d>), the way /door switches nights.
 */
final class RosterService
{
    use GroupBreadcrumb;

    /** Callback data prefix for a marking tap: rm:<assignment_id>:<a|c|l|n>. */
    private const MARK_PREFIX = 'rm:';

    /** Callback data prefix for a day button under the roster: rd:<Y-m-d>. */
    public const SELECT_PREFIX = 'rd:';

    /** How many upcoming days the day buttons offer, and how many per row. */
    private const PICKER_DATES = 6;
    private const PICKER_PER_ROW = 3;

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
        $date = substr((string) ($callbackQuery['data'] ?? ''), strlen(self::SELECT_PREFIX));

        if (1 !== preg_match('#^\d{4}-\d{2}-\d{2}$#', $date) || 0 === $chatId || 0 === $messageId) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || (! $person->isOrganizer && ! $this->isRosteredOn($person->id, $date))) {
            $this->telegram->answerCallbackQuery($callbackId, $this->deniedMessage(), true);

            return;
        }

        $canMark = $person->isOrganizer || $this->isOnSalesDesk($person->id, $date);
        $rendered = $this->render($date, $this->assembler->forDate($date), $canMark, $person->isOrganizer);
        $keyboard = array_merge($rendered['keyboard'], $this->pickerRows($person, $date));

        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->editMessageText($chatId, $messageId, $rendered['text'], ['inline_keyboard' => $keyboard]);
    }

    /**
     * Handles a marking button tap: an organizer setting one person completed,
     * arrived late or no-show, or that night's sales desk setting someone
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
        [$assignmentId, $status] = $this->parseMark((string) ($callbackQuery['data'] ?? ''));

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

        $this->refreshRosterMessage($callbackQuery, $assignment->taskId, $person);
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
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function refreshRosterMessage(array $callbackQuery, int $taskId, Person $person): void
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

        $rendered = $this->render($date, $this->assembler->forDate($date), true, $person->isOrganizer);
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
     * Splits "rm:<id>:<a|c|l|n>" into the assignment id and the status it maps
     * to, or [0, ''] when the data is not a marking tap.
     *
     * @return array{int, string}
     */
    private function parseMark(string $data): array
    {
        if (1 !== preg_match('#^' . preg_quote(self::MARK_PREFIX, '#') . '(\d+):([acln])$#', $data, $matches)) {
            return [0, ''];
        }

        return [
            (int) $matches[1],
            match ($matches[2]) {
                'a' => AssignmentStatus::ARRIVED,
                'c' => AssignmentStatus::COMPLETED,
                'l' => AssignmentStatus::ARRIVED_LATE,
                default => AssignmentStatus::NO_SHOW,
            },
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
     * The day buttons under the roster: the next upcoming days with tasks, the
     * shown one marked with ▶. Someone who is not an organizer gets only the
     * days they are rostered on, since those are the only ones they may open.
     *
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    private function pickerRows(Person $person, string $shown): array
    {
        $buttons = [];

        foreach ($this->tasks->upcomingDates(self::PICKER_DATES) as $date) {
            if (! $person->isOrganizer && ! $this->isRosteredOn($person->id, $date)) {
                continue;
            }

            $buttons[] = [
                'text' => ($date === $shown ? '▶ ' : '') . $this->dateLabel($date),
                'callback_data' => self::SELECT_PREFIX . $date,
            ];
        }

        // One button that is the day already shown switches to nothing.
        if (1 === count($buttons) && self::SELECT_PREFIX . $shown === $buttons[0]['callback_data']) {
            return [];
        }

        return array_chunk($buttons, self::PICKER_PER_ROW);
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
        $dates = $this->tasks->datesWithTasks(); // most recent first

        if ([] === $dates) {
            return '';
        }

        $today = current_time('Y-m-d');
        $upcoming = array_values(array_filter($dates, static fn (string $d): bool => $d >= $today));

        // end() of the upcoming subset is the nearest future date; with none,
        // the most recent past date is the head of the descending list.
        return [] !== $upcoming ? (string) end($upcoming) : $dates[0];
    }

    /**
     * The roster as a message: the text everyone rostered sees, and - for
     * someone who may mark ($canMark) - a keyboard of per-person mark buttons:
     * done, late and no-show for an organizer, only arrived for the sales desk.
     * Organizers-only tasks are left out unless the viewer is an organizer
     * ($isOrganizer).
     *
     * @param array<int, array{task: \EventCrew\Models\Task, people: array<int, array{
     *     assignment_id: int, name: string, status: string,
     *     status_label: string, occupying: bool, marked_by: string, signed_up_at?: string}>}> $roster
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    private function render(string $date, array $roster, bool $canMark, bool $isOrganizer): array
    {
        $lines = ['📋 ' . $this->dateLabel($date)];
        $keyboard = [];

        foreach ($roster as $row) {
            $task = $row['task'];

            if (! $isOrganizer && $task->isOrganizersOnly()) {
                continue;
            }

            $taken = count(array_filter($row['people'], static fn (array $p): bool => $p['occupying']));

            $lines[] = '';
            $lines[] = $task->hasNoLimit()
                ? sprintf('%s — %s (%d)', $task->roleDisplay(), $task->eventName(), $taken)
                : sprintf('%s — %s (%d/%d)', $task->roleDisplay(), $task->eventName(), $taken, $task->capacity);

            if ([] === $row['people']) {
                $lines[] = '  ' . __('— nobody yet', 'eventcrew');

                continue;
            }

            foreach ($row['people'] as $person) {
                $mark = $person['occupying'] ? '•' : '×';
                $standing = $person['standing'] ?? null;
                $reliability = null === $standing ? '' : ' — ' . $standing->ratedSummary();
                $lines[] = sprintf(
                    '  %s %s (%s)%s%s',
                    $mark,
                    $person['name'],
                    $person['status_label'],
                    $reliability,
                    $this->signedUpNote((string) ($person['signed_up_at'] ?? ''))
                );

                if ('' !== ($person['marked_by'] ?? '')) {
                    $lines[] = '    ' . $person['marked_by'];
                }

                if ($canMark && $isOrganizer && $this->isMarkable($person['status'])) {
                    $keyboard[] = $this->markButtons($person['assignment_id'], $person['name']);
                } elseif ($canMark && ! $isOrganizer && AssignmentStatus::SIGNED_UP === $person['status']) {
                    $keyboard[] = [$this->arrivedButton($person['assignment_id'], $person['name'])];
                }
            }
        }

        // The mark buttons carry only a symbol and a name, so say what each
        // symbol means whenever there are buttons to tap.
        if ([] !== $keyboard) {
            $lines[] = '';
            $lines[] = $isOrganizer
                ? __('✓ done · ⏰ late · ✗ no-show', 'eventcrew')
                : __('👋 arrived', 'eventcrew');
        }

        return ['text' => implode("\n", $lines), 'keyboard' => $keyboard];
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
     * The one button the sales desk gets per person still signed up: mark
     * them arrived, which earns no credit.
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
