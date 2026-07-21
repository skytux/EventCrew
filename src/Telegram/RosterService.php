<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\RosterAssembler;

/**
 * The bot side of the roster: /roster returns who is on what for the most
 * recent event day, read-only, to organizers.
 *
 * Marking stays in wp-admin; from chat this only shows. It is gated on the
 * sender being a linked organizer - a person marked is_organizer on the People
 * page whose Telegram is connected - so an ordinary member cannot pull the
 * whole crew's attendance out of the group.
 */
final class RosterService
{
    public function __construct(
        private readonly RosterAssembler $assembler,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onRosterCommand(int $telegramUserId, int $chatId): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->sendMessage(
                $chatId,
                __('Only organizers can see the roster.', 'eventcrew')
            );

            return;
        }

        $date = $this->defaultDate();

        if ('' === $date) {
            $this->telegram->sendMessage($chatId, __('No tasks are scheduled yet.', 'eventcrew'));

            return;
        }

        $this->telegram->sendMessage($chatId, $this->format($date, $this->assembler->forDate($date)));
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
     * @param array<int, array{task: \EventCrew\Models\Task, people: array<int, array{
     *     assignment_id: int, name: string, status: string,
     *     status_label: string, occupying: bool}>}> $roster
     */
    private function format(string $date, array $roster): string
    {
        $lines = ['📋 ' . $this->dateLabel($date)];

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
                $lines[] = sprintf('  %s %s (%s)', $mark, $person['name'], $person['status_label']);
            }
        }

        return implode("\n", $lines);
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
