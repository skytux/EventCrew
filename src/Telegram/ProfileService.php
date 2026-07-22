<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\StandingCalculator;

/**
 * /me: a person's own standing, credit balance and recent tasks, sent to their
 * private chat.
 *
 * The one self-service window into the reputation and credits the organizer
 * sees on the People list - read-only, so it exposes what someone has earned
 * without letting them change it.
 */
final class ProfileService
{
    private const RECENT_LIMIT = 3;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly StandingCalculator $standing,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onMe(int $telegramUserId, int $chatId): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage(
                $chatId,
                __('Set yourself up first with /start, then /me shows how you’re doing.', 'eventcrew')
            );

            return;
        }

        $standing = $this->standing->for($person->id);

        $lines = [
            sprintf(
                /* translators: 1: name, 2: standing level */
                __('%1$s — %2$s', 'eventcrew'),
                $person->name(),
                $standing->levelLabel()
            ),
            sprintf(
                /* translators: 1: completed count, 2: credit balance */
                __('Completed tasks: %1$d · Free-entry credits: %2$d', 'eventcrew'),
                $standing->completedCount,
                $standing->creditBalance
            ),
        ];

        $recent = $this->recentLines($person->id);

        if ([] !== $recent) {
            $lines[] = '';
            $lines[] = __('Recent:', 'eventcrew');
            $lines = array_merge($lines, $recent);
        }

        $this->telegram->sendMessage($chatId, implode("\n", $lines));
    }

    /**
     * The last few tasks as "• date — role (event): status" lines.
     *
     * @return array<int, string>
     */
    private function recentLines(int $personId): array
    {
        $lines = [];

        foreach (array_slice($this->assignments->historyFor($personId), 0, self::RECENT_LIMIT) as $entry) {
            $assignment = $entry['assignment'];
            $task = $this->tasks->find($assignment->taskId);

            $what = null === $task
                ? __('a task', 'eventcrew')
                : sprintf('%s (%s)', $task->roleLabel(), $task->eventName());

            $lines[] = sprintf(
                '• %s — %s: %s',
                (string) $entry['task_date'],
                $what,
                $assignment->statusLabel()
            );
        }

        return $lines;
    }
}
