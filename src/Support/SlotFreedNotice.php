<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * When a late cancellation frees a slot - close to the event, when it most needs
 * filling - this tells the crew, on both channels, so someone can step in.
 *
 * Listens on the eventcrew/slot_freed action that ClaimNotifier fires. The
 * audience is every active recipient except the person who just cancelled -
 * someone already working that day is deliberately still told, since they may
 * want a second, non-overlapping slot. It honours each person's open-task
 * preference so a fill-call and the scheduled open-task call share one switch.
 */
final class SlotFreedNotice
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    public function boot(): void
    {
        add_action('eventcrew/slot_freed', [$this, 'announce'], 10, 2);
    }

    public function announce(int $taskId, int $cancellerId): void
    {
        $task = $this->tasks->find($taskId);

        // A cancelled organize slot frees nothing: it has no limit, and it is
        // not something to announce to people who may not take it.
        if (null === $task || $task->isRestricted()) {
            return;
        }

        $prefs = new NotificationPreferences();

        foreach ($this->people->activeEmailRecipients() as $person) {
            // Skip only the person who just cancelled this slot.
            if ($person->id === $cancellerId) {
                continue;
            }

            Language::forPerson($person, fn () => $this->tell($person, $task, $prefs));
        }
    }

    /** One person, in the language they chose. */
    private function tell(Person $person, Task $task, NotificationPreferences $prefs): void
    {
        $when = '' === $task->timeRange() ? $task->taskDate : $task->taskDate . ' ' . $task->timeRange();
        $line = sprintf(
            /* translators: 1: role, 2: event, 3: date/time */
            __('A spot just opened: %1$s at %2$s, %3$s. Can you step in? Sign up on the board.', 'eventcrew'),
            $task->roleLabel(),
            $task->eventName(),
            $when
        );

        if ($prefs->dmAllowed($person, NotificationPreferences::OPEN_TASK)) {
            $this->telegram->sendMessage(
                (int) $person->telegramChatId,
                DmBody::frame($person, '⚡ ' . $line, true)
            );
        }

        if ($prefs->emailAllowed($person, NotificationPreferences::OPEN_TASK)) {
            $this->mailer->toPerson(
                $person->id,
                $person->email,
                __('A spot just opened up', 'eventcrew'),
                sprintf(
                    /* translators: 1: name, 2: the opening line */
                    __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
                    $person->name(),
                    $line
                ),
                [['label' => __('See open tasks', 'eventcrew'), 'url' => $this->mailer->boardUrl()]]
            );
        }
    }
}
