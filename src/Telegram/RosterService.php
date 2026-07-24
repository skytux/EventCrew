<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\RosterAssembler;

/**
 * The bot side of the roster: /roster returns who is on what for the most
 * recent event day.
 *
 * The answer always goes to the asker's private chat, never back to the group,
 * so the crew's attendance is not pinned up in public. It is gated on the
 * sender being either a linked organizer - a person marked is_organizer on the
 * People page whose Telegram is connected - or someone rostered on that day
 * themselves, so a bystander cannot pull the whole crew's attendance out of the
 * bot. Organizers additionally get a pair of one-tap buttons per person to mark
 * them completed or no-show on the running event, the same write the wp-admin
 * roster offers.
 */
final class RosterService
{
    /** Callback data prefix for a marking tap: rm:<assignment_id>:<c|n>. */
    private const MARK_PREFIX = 'rm:';

    public function __construct(
        private readonly RosterAssembler $assembler,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly AssignmentRepository $assignments,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onRosterCommand(int $telegramUserId, int $chatId): void
    {
        // Everything about the roster is private, so the whole reply - refusals
        // included - goes to the asker's DM rather than the chat they typed in.
        $reply = $telegramUserId;

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person) {
            $this->telegram->sendMessage($reply, $this->deniedMessage());

            return;
        }

        $date = $this->defaultDate();

        if ('' === $date) {
            $this->telegram->sendMessage($reply, __('No tasks are scheduled yet.', 'eventcrew'));

            return;
        }

        if (! $person->isOrganizer && ! $this->isRosteredOn($person->id, $date)) {
            $this->telegram->sendMessage($reply, $this->deniedMessage());

            return;
        }

        $rendered = $this->render($date, $this->assembler->forDate($date), $person->isOrganizer);
        $this->telegram->sendMessage($reply, $rendered['text'], $this->markup($rendered['keyboard']));
    }

    /**
     * Handles a marking button tap: an organizer setting one person completed
     * or no-show. Anyone else is refused, and the roster message is edited in
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

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Only organizers can mark attendance.', 'eventcrew'),
                true
            );

            return;
        }

        $assignment = $this->assignments->find($assignmentId);

        if (null === $assignment) {
            $this->telegram->answerCallbackQuery($callbackId, __('That entry is gone.', 'eventcrew'), true);

            return;
        }

        $this->assignments->setStatus($assignmentId, $status);

        $this->telegram->answerCallbackQuery(
            $callbackId,
            AssignmentStatus::COMPLETED === $status
                ? __('Marked completed.', 'eventcrew')
                : __('Marked no-show.', 'eventcrew')
        );

        $this->refreshRosterMessage($callbackQuery, $assignment->taskId);
    }

    private function deniedMessage(): string
    {
        return __('Only organizers or people rostered on the day can see the roster.', 'eventcrew');
    }

    /**
     * Rebuilds the roster message the tapped button belongs to and edits it in
     * place, so a just-marked person drops out of the "still expected" buttons
     * and the taken counts move. The date comes from the marked task, not the
     * default, so it stays correct even if the default day has since rolled on.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function refreshRosterMessage(array $callbackQuery, int $taskId): void
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

        $rendered = $this->render($date, $this->assembler->forDate($date), true);
        // An empty inline_keyboard clears the buttons when nobody is left to
        // mark, rather than leaving stale taps on an already-closed event.
        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            $rendered['text'],
            ['inline_keyboard' => $rendered['keyboard']]
        );
    }

    /**
     * Splits "rm:<id>:<c|n>" into the assignment id and the status it maps to,
     * or [0, ''] when the data is not a marking tap.
     *
     * @return array{int, string}
     */
    private function parseMark(string $data): array
    {
        if (1 !== preg_match('#^' . preg_quote(self::MARK_PREFIX, '#') . '(\d+):([cn])$#', $data, $matches)) {
            return [0, ''];
        }

        return [
            (int) $matches[1],
            'c' === $matches[2] ? AssignmentStatus::COMPLETED : AssignmentStatus::NO_SHOW,
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
     * The roster as a message: the text everyone rostered sees, and - for an
     * organizer ($canMark) - a keyboard of per-person mark buttons.
     *
     * @param array<int, array{task: \EventCrew\Models\Task, people: array<int, array{
     *     assignment_id: int, name: string, status: string,
     *     status_label: string, occupying: bool}>}> $roster
     * @return array{text: string, keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    private function render(string $date, array $roster, bool $canMark): array
    {
        $lines = ['📋 ' . $this->dateLabel($date)];
        $keyboard = [];

        foreach ($roster as $row) {
            $task = $row['task'];
            $taken = count(array_filter($row['people'], static fn (array $p): bool => $p['occupying']));

            $lines[] = '';
            $lines[] = sprintf('%s — %s (%d/%d)', $task->roleDisplay(), $task->eventName(), $taken, $task->capacity);

            if ([] === $row['people']) {
                $lines[] = '  ' . __('— nobody yet', 'eventcrew');

                continue;
            }

            foreach ($row['people'] as $person) {
                $mark = $person['occupying'] ? '•' : '×';
                $standing = $person['standing'] ?? null;
                $reliability = null === $standing ? '' : ' — ' . $standing->ratedSummary();
                $lines[] = sprintf(
                    '  %s %s (%s)%s',
                    $mark,
                    $person['name'],
                    $person['status_label'],
                    $reliability
                );

                if ($canMark && $this->isMarkable($person['status'])) {
                    $keyboard[] = $this->markButtons($person['assignment_id'], $person['name']);
                }
            }
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
                'text' => sprintf(__('✗ %s', 'eventcrew'), $name),
                'callback_data' => self::MARK_PREFIX . $assignmentId . ':n',
            ],
        ];
    }

    private function dateLabel(string $date): string
    {
        $timestamp = strtotime($date . ' 12:00:00');

        if (false === $timestamp) {
            return $date;
        }

        return function_exists('wp_date')
            ? (string) wp_date('D j M', $timestamp)
            : gmdate('D j M', $timestamp);
    }
}
