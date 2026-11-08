<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * When an organizer deletes a whole task, every person who was signed up for
 * it gets exactly one email about it (Q263), in the languages HybridPolicy
 * picks: every site language when the email is hybrid, else the person's.
 *
 * Listens on the eventcrew/task_deleted action that TaskRepository::delete
 * fires with the people whose sign-up it cancelled. When the person's ticket
 * for the night was emailed, TicketMail::crewTasksChanged() sends it again
 * with the tasks left, or the "Your ticket is cancelled" email when none is
 * left; either one names the deleted task. When no ticket was emailed, the
 * person gets the "Task cancelled" email instead. A person who uses the bot
 * also gets the task_cancelled_dm, in their language, with the event's other
 * open tasks and the board, like the email. A task whose date is over,
 * or whose end time has passed, sends nothing: there is nobody left to tell.
 *
 * It is about a commitment the person made, so like the sign-up and cancel
 * confirmations it is not behind a notification switch.
 */
final class TaskCancelledNotice
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly ?TicketMail $ticketMail = null,
        private readonly ?TelegramClient $telegram = null,
        private readonly ?SlotFreedNotice $slots = null
    ) {
    }

    public function boot(): void
    {
        add_action('eventcrew/task_deleted', [$this, 'notify'], 10, 2);
    }

    /**
     * @param array<int, int> $personIds The people whose sign-up was cancelled.
     */
    public function notify(int $taskId, array $personIds): void
    {
        if ([] === $personIds) {
            return;
        }

        $task = $this->tasks->find($taskId);

        if (null === $task || self::isOver($task)) {
            return;
        }

        // The event's other open tasks, to pick another one: read once, and
        // each person gets the ones their board shows.
        $open = null === $this->slots ? [] : $this->slots->openOthers($task);

        foreach (array_unique(array_map('intval', $personIds)) as $personId) {
            $person = $this->people->find($personId);

            if (null === $person || $person->isAnonymized()) {
                continue;
            }

            $others = SlotFreedNotice::seenBy($open, $person);

            $this->dm($person, $task, $others);

            // One email per person (Q263). When their ticket for the night
            // was emailed: the ticket again with the tasks left, or the
            // red-band cancel email when no task is left, each naming the
            // deleted task. Otherwise the "Task cancelled" email.
            if (null !== $this->ticketMail && $this->ticketMail->ticketEmailed($person->id, $task->taskDate)) {
                $this->ticketMail->crewTasksChanged($person, $task->taskDate, $task);
                continue;
            }

            $this->send($person, $task, $others);
        }
    }

    /**
     * The DM, in the person's language. A commitment, like the sign-up and
     * cancel confirmations: sent to a muted account too.
     *
     * @param list<array{task: Task, free: int}> $others
     */
    private function dm(Person $person, Task $task, array $others): void
    {
        $telegram = $this->telegram;

        if (
            null === $telegram
            || null === $person->telegramChatId
            || ! (new NotificationPreferences())->dmAllowed($person, NotificationPreferences::SIGNUP)
        ) {
            return;
        }

        $chatId = $person->telegramChatId;

        Language::forPerson($person, function () use ($person, $task, $others, $telegram, $chatId): void {
            $context = ['person' => $person, 'task' => $task, 'other_rows' => $others];

            $telegram->sendMessage(
                $chatId,
                // An organizer's doing, so the DM opens and signs off (DmBody).
                DmBody::frame($person, MessageTemplates::body('task_cancelled_dm', $context)),
                MessageActions::replyMarkup(MessageActions::of('task_cancelled_dm', $context, $this->mailer))
            );
        });
    }

    /** True when the task's date is over, or its end time has passed. */
    private static function isOver(Task $task): bool
    {
        if ($task->isPast()) {
            return true;
        }

        return null !== $task->endsAt && $task->endsAt <= (string) current_time('mysql');
    }

    /** @param list<array{task: Task, free: int}> $others */
    private function send(Person $person, Task $task, array $others): void
    {
        // The subject and text are the Messages page template (MessageTemplates).
        $words = function () use ($person, $task, $others): array {
            $context = ['person' => $person, 'task' => $task, 'other_rows' => $others];

            return [
                'subject' => MessageTemplates::subject('task_cancelled', $context),
                'body' => MessageAiText::body('task_cancelled', $context, $task->eventPostId),
                // See open tasks: the crew page's board and the bot's board
                // (Owner 2026-11-07: the same one button as the other lists).
                'actions' => MessageActions::email(MessageActions::of('task_cancelled', $context, $this->mailer)),
            ];
        };

        $parts = HybridPolicy::inEach(HybridPolicy::languagesFor(HybridPolicy::TASK_CANCELLED, $person), $words);
        $both = static fn (string $key): string => HybridPolicy::join(HybridPolicy::field($parts, $key));
        $body = HybridPolicy::join(HybridPolicy::field($parts, 'body'), "\n\n————————\n\n");

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            $both('subject'),
            $body,
            self::joinedActions(array_values(array_map(static fn (array $part): array => (array) $part['actions'], $parts)))
        );
    }

    /**
     * The first language's email actions, each label in every language
     * ("Avoimet tehtävät / See open tasks"), as the subject.
     *
     * @param list<list<array{label: string, url: string, style?: string}>> $lists One list per language.
     * @return list<array{label: string, url: string, style?: string}>
     */
    private static function joinedActions(array $lists): array
    {
        $actions = $lists[0] ?? [];

        foreach ($actions as $index => $action) {
            $actions[$index]['label'] = HybridPolicy::join(
                array_map(static fn (array $list): string => (string) ($list[$index]['label'] ?? $action['label']), $lists)
            );
        }

        return $actions;
    }
}
