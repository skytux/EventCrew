<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\Dates;
use EventCrew\Support\Language;
use EventCrew\Support\Mailer;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\SignupService;

/**
 * "I'll cover for someone" - the person stepping in drives it, and it completes
 * itself with no organizer in the loop.
 *
 * The cover taps 🔁 Take over a task under /me, names the person they are covering, and the bot
 * lists that person's upcoming tasks. Picking one swaps the two in a single
 * step: the original is marked `replaced` (which frees the slot and earns them
 * the reputation credit for arranging cover), and the cover is signed up in
 * their place. Both are told, and the group is told.
 */
final class ReplacementService
{
    use GroupBreadcrumb;

    private const AWAIT_PREFIX = 'eventcrew_tg_await_replace_target_';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly BoardService $board,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer
    ) {
    }

    /**
     * 🔁 Take over a task: asks who the cover is standing in for. The exchange is personal
     * (it lists another member's tasks), so it runs in the DM - asked in a group
     * the prompt goes to the DM and a breadcrumb points there.
     */
    public function start(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $cover = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $cover || ! $cover->isEmailVerified()) {
            // A harmless nudge, so it goes back to wherever they asked.
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        set_transient(self::AWAIT_PREFIX . $telegramUserId, true, 15 * MINUTE_IN_SECONDS);
        $this->telegram->sendMessage(
            $telegramUserId,
            __('Whose task are you covering? Send their name, or @mention them.', 'eventcrew'),
            MenuButtons::cancel()
        );
        $this->sentDmNote($chatId, $isPrivate);
    }

    public function isAwaitingTarget(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_PREFIX . $telegramUserId);
    }

    /**
     * The Cancel button: forgets the "whose task?" question. True when one was waiting.
     */
    public function cancelPending(int $telegramUserId): bool
    {
        $waiting = $this->isAwaitingTarget($telegramUserId);
        delete_transient(self::AWAIT_PREFIX . $telegramUserId);

        return $waiting;
    }

    /**
     * The reply naming the person being covered. Resolves them - by a Telegram
     * text-mention if there is one, otherwise by name - and lists their
     * upcoming tasks to pick from.
     *
     * @param array<int, array<string, mixed>> $entities Telegram message entities.
     */
    public function captureTarget(int $telegramUserId, int $chatId, string $text, array $entities): void
    {
        delete_transient(self::AWAIT_PREFIX . $telegramUserId);

        $buttons = [];
        $cover = false;

        foreach (PersonResolver::matching($this->people, $text, $entities) as $person) {
            foreach ($this->upcomingSlots($person->id) as $entry) {
                $task = $entry['task'];

                // Someone who may not take a restricted task (Organize, DJ, ...)
                // is not offered it to cover either. The cover is only looked
                // up when such a task is in the list.
                if ($task->isRestricted()) {
                    if (false === $cover) {
                        $cover = $this->people->findByTelegramUserId($telegramUserId);
                    }

                    if (SignupService::blocksRestrictedSlot($task, $cover)) {
                        continue;
                    }
                }

                $buttons[] = [[
                    // Name · date · task · event, so the button says exactly whose
                    // slot on which day you are stepping into before you tap.
                    'text' => sprintf(
                        '%s · %s · %s · %s',
                        $person->name(),
                        Dates::dayLabel($task->taskDate),
                        $task->roleLabel(),
                        $task->eventName()
                    ),
                    'callback_data' => 'rep:' . $entry['assignmentId'],
                ]];
            }
        }

        if ([] === $buttons) {
            $this->telegram->sendMessage(
                $chatId,
                __('I couldn’t find anyone by that name with an upcoming task to cover.', 'eventcrew')
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('Which of their tasks are you covering?', 'eventcrew'),
            ['inline_keyboard' => $buttons]
        );
    }

    /**
     * A task was picked (callback rep:<assignmentId>): swap the two.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $coverTelegramId = (int) ($callbackQuery['from']['id'] ?? 0);
        $assignmentId = (int) preg_replace('/\D/', '', (string) ($callbackQuery['data'] ?? ''));

        $original = $this->assignments->find($assignmentId);
        $cover = $this->people->findByTelegramUserId($coverTelegramId);

        if (null === $cover || ! $cover->isEmailVerified()) {
            $this->telegram->answerCallbackQuery($callbackId, SetupNudge::text(), true);

            return;
        }

        $task = null === $original ? null : $this->tasks->find($original->taskId);

        if (null === $original || null === $task || ! $original->isOccupying() || $task->isPast()) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That task is no longer available.', 'eventcrew'),
                true
            );

            return;
        }

        // Worded like a task that is gone: someone without the role is never offered it.
        if (SignupService::blocksRestrictedSlot($task, $cover)) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That task is no longer available.', 'eventcrew'),
                true
            );

            return;
        }

        $refusal = $this->refusalFor($cover, $original->personId, $task->id);

        if (null !== $refusal) {
            $this->telegram->answerCallbackQuery($callbackId, $refusal, true);

            return;
        }

        $this->swap($callbackId, $original->id, $original->personId, $task->id, $cover, $original->countsCredit);
    }

    private function refusalFor(Person $cover, int $originalPersonId, int $taskId): ?string
    {
        if ($cover->id === $originalPersonId) {
            return __('That’s your own task.', 'eventcrew');
        }

        $existing = $this->assignments->findFor($taskId, $cover->id);

        if (null !== $existing && $existing->isOccupying()) {
            return __('You’re already signed up for that one.', 'eventcrew');
        }

        // No overlap check: covering someone is exactly the case where a
        // clash on paper is somebody's own business, and it was refused here
        // for the same reason it was refused on the board - which is no longer
        // a reason. Taking the same task twice is still stopped, above.
        return null;
    }

    private function swap(
        string $callbackId,
        int $originalAssignmentId,
        int $originalPersonId,
        int $taskId,
        Person $cover,
        bool $countsCredit = true
    ): void {
        // Free the original's slot as `replaced` (their credit for arranging
        // cover), then sign the cover up. If the freed slot is lost to a race
        // before the cover can take it, put the original back and say so.
        $this->assignments->setStatus($originalAssignmentId, AssignmentStatus::REPLACED, $cover->id, MarkedBy::PERSON);
        // The cover steps into the same slot, so it keeps the slot's answer to
        // "Count toward free-entry credit?".
        $outcome = $this->assignments->join($taskId, $cover->id, $countsCredit);

        if (! in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true)) {
            $this->assignments->setStatus(
                $originalAssignmentId,
                AssignmentStatus::SIGNED_UP,
                $cover->id,
                MarkedBy::PERSON
            );
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That slot filled up before I could swap it.', 'eventcrew'),
                true
            );

            return;
        }

        $task = $this->tasks->find($taskId);
        $original = $this->people->find($originalPersonId);
        $originalName = null === $original ? __('someone', 'eventcrew') : $original->name();
        $role = null === $task ? '' : $task->roleLabel();
        $event = null === $task ? '' : $task->eventName();

        $this->telegram->answerCallbackQuery(
            $callbackId,
            sprintf(
                /* translators: 1: person being covered, 2: role */
                __('Done — you’re covering %1$s’s %2$s.', 'eventcrew'),
                $originalName,
                $role
            ),
            true
        );

        if (null !== $original) {
            Language::forPerson($original, function () use ($original, $cover, $task, $event): void {
                $this->tellOriginal($original, $cover, null === $task ? '' : $task->roleLabel(), $event);
            });
        }

        // The group reads one message, so it is always in English, whatever
        // language the cover or the person covered chose.
        $this->board->announce(Language::run(Language::ENGLISH, static fn (): string => sprintf(
            /* translators: 1: cover's name, 2: person being covered, 3: role, 4: event */
            __('%1$s is covering for %2$s: %3$s at %4$s.', 'eventcrew'),
            $cover->name(),
            null === $original ? __('someone', 'eventcrew') : $original->name(),
            null === $task ? '' : $task->roleLabel(),
            $event
        )));
        $this->board->refresh();
    }

    /**
     * Tells the person being covered, by DM and email as they allow, in the
     * language the caller has switched to for them.
     */
    private function tellOriginal(Person $original, Person $cover, string $role, string $event): void
    {
        $line = sprintf(
            /* translators: 1: cover's name, 2: role, 3: event */
            __('%1$s is now covering your %2$s at %3$s. Thanks for arranging it!', 'eventcrew'),
            $cover->name(),
            $role,
            $event
        );
        $prefs = new NotificationPreferences();

        if ($prefs->dmAllowed($original, NotificationPreferences::COVER)) {
            $this->telegram->sendMessage($original->telegramChatId, $line);
        }

        if ($prefs->emailAllowed($original, NotificationPreferences::COVER)) {
            $this->mailer->toPerson(
                $original->id,
                $original->email,
                __('Your task is covered', 'eventcrew'),
                sprintf(
                    /* translators: 1: name, 2: the cover line */
                    __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
                    $original->name(),
                    $line
                )
            );
        }
    }

    /**
     * A person's occupying assignments whose task is still upcoming.
     *
     * @return array<int, array{assignmentId: int, task: \EventCrew\Models\Task}>
     */
    private function upcomingSlots(int $personId): array
    {
        $slots = [];

        foreach ($this->assignments->forPerson($personId) as $assignment) {
            if (! $assignment->isOccupying()) {
                continue;
            }

            $task = $this->tasks->find($assignment->taskId);

            if (null !== $task && ! $task->isPast()) {
                $slots[] = ['assignmentId' => $assignment->id, 'task' => $task];
            }
        }

        return $slots;
    }
}
