<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Assignment;
use EventCrew\Models\Person;
use EventCrew\Models\Takeover;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TakeoverRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;

/**
 * Takeover requests: one member (the cover) asks to take over another
 * member's task (the holder's). Nothing moves until the holder agrees.
 *
 * 1. request() stores a pending request with an answer deadline (see
 *    TakeoverDeadline) and asks the holder: by a Telegram DM with two buttons
 *    when they have Telegram linked, and always by email with a signed link
 *    to a page with the same two buttons.
 * 2. answer() runs the holder's choice. Agreeing moves the task the way the
 *    old instant swap did: the holder is marked `replaced` and the cover is
 *    signed up in the same slot. Refusing leaves everything as it was.
 * 3. expireDue() (hourly, from the Scheduler) closes requests nobody answered
 *    in time: the holder keeps the task.
 *
 * The cover is told every outcome. A task has at most one pending request.
 */
final class TakeoverService
{
    /** SignedLink purpose of the email answer link. */
    public const PURPOSE = 'takeover';

    /** Telegram callback data: tko:y:<id> (let them take it) or tko:n:<id> (keep it). */
    public const CALLBACK_PREFIX = 'tko:';

    public function __construct(
        private readonly TakeoverRepository $takeovers,
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly ClaimNotifier $claims,
        private readonly BoardService $board,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer
    ) {
    }

    public function boot(): void
    {
        add_action('eventcrew/assignment_status_changed', [$this, 'onStatusChanged'], 10, 2);
    }

    public static function answerUrl(int $takeoverId): string
    {
        return add_query_arg(
            ['token' => SignedLink::sign(self::PURPOSE, $takeoverId)],
            rest_url('eventcrew/v1/takeover')
        );
    }

    /**
     * The cover asks for the holder's task. The caller has already checked
     * that the cover may take it (verified, not their own task, not already
     * on it, allowed the role). The returned text is in the current language,
     * for the cover.
     *
     * @return array{ok: bool, text: string}
     */
    public function request(Assignment $original, Task $task, Person $cover): array
    {
        $holder = $this->people->find($original->personId);

        if (null === $holder) {
            return ['ok' => false, 'text' => __('That task is no longer available.', 'eventcrew')];
        }

        $deadline = TakeoverDeadline::deadline(TakeoverDeadline::now(), TakeoverDeadline::startOf($task));

        if (null === $deadline) {
            return ['ok' => false, 'text' => self::tooLateText()];
        }

        $existing = $this->takeovers->pendingForTask($task->id);

        if (null !== $existing) {
            return ['ok' => false, 'text' => $this->alreadyPendingText($existing, $cover, $holder)];
        }

        $deadlineAt = TakeoverDeadline::toMysql($deadline);
        $id = $this->takeovers->create($original->id, $task->id, $holder->id, $cover->id, $deadlineAt);

        if (null === $id) {
            // Another request got in between the check and the insert.
            return ['ok' => false, 'text' => self::otherPendingText()];
        }

        Language::forPerson($holder, fn () => $this->askHolder($id, $holder, $cover, $task, $deadlineAt));

        return [
            'ok' => true,
            'text' => sprintf(
                /* translators: 1: holder's name, 2: role, 3: event, 4: date/time, 5: answer deadline */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __("📨 Request sent. %1\$s decides whether you take over their task: %2\$s at %3\$s, %4\$s.\n\nThey have until %5\$s to answer. If they do not answer by then, they keep the task.", 'eventcrew'),
                $holder->name(),
                $task->roleLabel(),
                $task->eventName(),
                self::whenText($task),
                TakeoverDeadline::label($deadlineAt)
            ),
        ];
    }

    /**
     * The holder's answer, from the bot buttons or the email page. Returns a
     * line for the holder, in the current language.
     *
     * @param bool $viaBot True when the holder answered in Telegram: the
     *        caller shows the outcome there, so no extra DM is sent.
     */
    public function answer(int $takeoverId, bool $agree, int $holderId, bool $viaBot = false): string
    {
        $takeover = $this->takeovers->find($takeoverId);

        if (null === $takeover || $takeover->holderId !== $holderId) {
            return __('This link is no longer valid.', 'eventcrew');
        }

        if (! $takeover->isPending()) {
            return self::closedText($takeover->status);
        }

        $original = $this->assignments->find($takeover->assignmentId);
        $task = $this->tasks->find($takeover->taskId);
        $cover = $this->people->find($takeover->coverId);
        $holder = $this->people->find($takeover->holderId);

        if (null === $original || null === $task || null === $cover || null === $holder) {
            $this->close($takeover, Takeover::CANCELLED, $task, $cover, $holder);

            return self::closedText(Takeover::CANCELLED);
        }

        if (! $original->isOccupying() || $task->hasStarted()) {
            $this->close($takeover, Takeover::CANCELLED, $task, $cover, $holder);

            return self::closedText(Takeover::CANCELLED);
        }

        if ($takeover->deadlineAt <= (string) current_time('mysql')) {
            $this->close($takeover, Takeover::EXPIRED, $task, $cover, $holder);

            return self::closedText(Takeover::EXPIRED);
        }

        if (! $agree) {
            if (! $this->takeovers->resolve($takeover->id, Takeover::REFUSED)) {
                return self::closedText('');
            }

            $this->tellCover($cover, $holder, $task, Takeover::REFUSED);

            return sprintf(
                /* translators: 1: role, 2: event, 3: date/time, 4: cover's name */
                __('You keep your task: %1$s at %2$s, %3$s. %4$s has been told.', 'eventcrew'),
                $task->roleLabel(),
                $task->eventName(),
                self::whenText($task),
                $cover->name()
            );
        }

        if (! $this->takeovers->resolve($takeover->id, Takeover::ACCEPTED)) {
            return self::closedText('');
        }

        return $this->move($takeover, $original, $task, $cover, $holder, $viaBot);
    }

    /**
     * Closes the pending requests nobody answered in time, and the ones whose
     * task went away. Runs hourly from the Scheduler.
     *
     * @return int How many requests were closed.
     */
    public function expireDue(int $limit): int
    {
        $closed = 0;
        $now = (string) current_time('mysql');

        foreach ($this->takeovers->pending($limit) as $takeover) {
            $original = $this->assignments->find($takeover->assignmentId);
            $task = $this->tasks->find($takeover->taskId);
            $stale = null === $original || null === $task || ! $original->isOccupying() || $task->hasStarted();

            if (! $stale && $takeover->deadlineAt > $now) {
                // Sorted by deadline: every later row is still open too.
                break;
            }

            $cover = $this->people->find($takeover->coverId);
            $holder = $this->people->find($takeover->holderId);

            if ($this->close($takeover, $stale ? Takeover::CANCELLED : Takeover::EXPIRED, $task, $cover, $holder)) {
                ++$closed;
            }
        }

        return $closed;
    }

    /**
     * The eventcrew/assignment_status_changed listener: when the holder's
     * place stops occupying the task (they cancelled, or an organizer changed
     * it), the request on it is closed and the cover is told.
     */
    public function onStatusChanged(int $assignmentId, string $status): void
    {
        if (in_array($status, AssignmentStatus::occupying(), true)) {
            return;
        }

        $takeover = $this->takeovers->pendingForAssignment($assignmentId);

        if (null === $takeover) {
            return;
        }

        $this->close(
            $takeover,
            Takeover::CANCELLED,
            $this->tasks->find($takeover->taskId),
            $this->people->find($takeover->coverId),
            $this->people->find($takeover->holderId)
        );
    }

    /**
     * What the email answer page says about a request, in the current
     * language; '' when the request or its people are gone.
     */
    public function summary(Takeover $takeover): string
    {
        $task = $this->tasks->find($takeover->taskId);
        $cover = $this->people->find($takeover->coverId);

        if (null === $task || null === $cover) {
            return '';
        }

        return self::askText($cover, $task, $takeover->deadlineAt);
    }

    public function find(int $takeoverId): ?Takeover
    {
        return $this->takeovers->find($takeoverId);
    }

    public function holderOf(Takeover $takeover): ?Person
    {
        return $this->people->find($takeover->holderId);
    }

    /** "The request is closed" in the words that fit why. */
    public static function closedText(string $status): string
    {
        return match ($status) {
            Takeover::ACCEPTED => __('You already let them take this task.', 'eventcrew'),
            Takeover::REFUSED => __('You already kept this task.', 'eventcrew'),
            Takeover::EXPIRED => __('The time to answer has passed, so you keep your task.', 'eventcrew'),
            Takeover::CANCELLED => __('This request is closed: the task changed or was cancelled.', 'eventcrew'),
            default => __('This request is already closed.', 'eventcrew'),
        };
    }

    /**
     * Moves the task from the holder to the cover, the same two steps as the
     * old instant swap: the holder's place becomes `replaced` (their credit
     * for arranging cover) and the cover joins the same slot. If the slot is
     * lost to a race in between, the holder is put back and the request is
     * closed.
     */
    private function move(
        Takeover $takeover,
        Assignment $original,
        Task $task,
        Person $cover,
        Person $holder,
        bool $viaBot
    ): string {
        $this->assignments->setStatus($original->id, AssignmentStatus::REPLACED, $cover->id, MarkedBy::PERSON);
        // The cover steps into the same slot, so it keeps the slot's answer
        // to "Count toward free-entry credit?".
        $outcome = $this->assignments->join($task->id, $cover->id, $original->countsCredit);

        if (! in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true)) {
            $this->assignments->setStatus($original->id, AssignmentStatus::SIGNED_UP, $cover->id, MarkedBy::PERSON);
            $this->takeovers->setStatus($takeover->id, Takeover::CANCELLED);
            Language::forPerson($cover, fn () => $this->sendToCover($cover, self::coverText('failed', $holder, $task)));

            return __('The task could not be moved, so you keep it.', 'eventcrew');
        }

        $this->claims->confirmSignup($cover, $task->id);

        $done = self::doneText($cover, $task);
        $this->tellHolderDone($holder, $cover, $task, $viaBot);
        // The holder no longer has this task: their emailed ticket for the
        // night is sent again with the tasks left, or cancelled.
        $this->claims->ticketTasksChanged($holder, $task->taskDate);

        $this->board->announce(self::groupText($cover, $holder, $task));
        $this->board->refresh();

        return $done;
    }

    /**
     * Closes a request that is still pending and tells the cover why. False
     * when someone else closed it first.
     */
    private function close(Takeover $takeover, string $status, ?Task $task, ?Person $cover, ?Person $holder): bool
    {
        if (! $this->takeovers->resolve($takeover->id, $status)) {
            return false;
        }

        if (null !== $cover && null !== $holder && null !== $task) {
            $this->tellCover($cover, $holder, $task, $status);
        }

        return true;
    }

    private function askHolder(int $id, Person $holder, Person $cover, Task $task, string $deadlineAt): void
    {
        $text = self::askText($cover, $task, $deadlineAt);

        // Transactional: the holder must be able to answer, so the person's
        // notification settings do not apply.
        if (null !== $holder->telegramChatId) {
            $this->telegram->sendMessage($holder->telegramChatId, $text, ['inline_keyboard' => [[
                ['text' => __('✅ Let them take it', 'eventcrew'), 'callback_data' => self::CALLBACK_PREFIX . 'y:' . $id],
                ['text' => __('❌ Keep my task', 'eventcrew'), 'callback_data' => self::CALLBACK_PREFIX . 'n:' . $id],
            ]]]);
        }

        $this->mailer->toPerson(
            $holder->id,
            $holder->email,
            sprintf(
                /* translators: %s: cover's name */
                __('%s asks to take over your task', 'eventcrew'),
                $cover->name()
            ),
            sprintf(
                /* translators: 1: name, 2: the cover line */
                __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
                $holder->name(),
                $text
            ),
            [['label' => __('Answer the request', 'eventcrew'), 'url' => self::answerUrl($id)]]
        );
    }

    private function tellHolderDone(Person $holder, Person $cover, Task $task, bool $viaBot): void
    {
        Language::forPerson($holder, function () use ($holder, $cover, $task, $viaBot): void {
            $text = self::doneText($cover, $task);

            if (! $viaBot && null !== $holder->telegramChatId) {
                $this->telegram->sendMessage($holder->telegramChatId, $text);
            }

            $this->mailer->toPerson(
                $holder->id,
                $holder->email,
                sprintf(
                    /* translators: %s: cover's name */
                    __('Your task moved to %s', 'eventcrew'),
                    $cover->name()
                ),
                sprintf(
                    /* translators: 1: name, 2: the cover line */
                    __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
                    $holder->name(),
                    $text
                )
            );
        });
    }

    private function tellCover(Person $cover, Person $holder, Task $task, string $status): void
    {
        Language::forPerson($cover, function () use ($cover, $holder, $task, $status): void {
            $this->sendToCover($cover, self::coverText($status, $holder, $task));
        });
    }

    /** A DM when the cover has Telegram linked, and always an email. */
    private function sendToCover(Person $cover, string $text): void
    {
        if (null !== $cover->telegramChatId) {
            $this->telegram->sendMessage($cover->telegramChatId, $text);
        }

        $this->mailer->toPerson(
            $cover->id,
            $cover->email,
            __('About your takeover request', 'eventcrew'),
            sprintf(
                /* translators: 1: name, 2: the cover line */
                __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
                $cover->name(),
                $text
            )
        );
    }

    private static function askText(Person $cover, Task $task, string $deadlineAt): string
    {
        return sprintf(
            /* translators: 1: cover's name, 2: role, 3: event, 4: date/time, 5: answer deadline */
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __("🔁 %1\$s asks to take over your task: %2\$s at %3\$s, %4\$s.\n\nYou decide: let them take it, or keep it. Please answer by %5\$s. If you do not answer by then, you keep the task.", 'eventcrew'),
            $cover->name(),
            $task->roleLabel(),
            $task->eventName(),
            self::whenText($task),
            TakeoverDeadline::label($deadlineAt)
        );
    }

    /**
     * The group's message about a takeover: everyone in the group reads the
     * same message, so it is in Finnish, then in English on the next line
     * (one line per site language), or in the main language alone when its
     * Hybrid tick is off (HybridPolicy).
     */
    private static function groupText(Person $cover, Person $holder, Task $task): string
    {
        $words = static fn (): string => sprintf(
            /* translators: 1: cover's name, 2: person being covered, 3: role, 4: event */
            __('%1$s is covering for %2$s: %3$s at %4$s.', 'eventcrew'),
            $cover->name(),
            $holder->name(),
            $task->roleLabel(),
            $task->eventName()
        );
        $languages = HybridPolicy::languagesFor(HybridPolicy::TAKEOVER_GROUP);

        return HybridPolicy::join(HybridPolicy::inEach($languages, $words), "\n");
    }

    private static function doneText(Person $cover, Task $task): string
    {
        return sprintf(
            /* translators: 1: cover's name, 2: role, 3: event, 4: date/time */
            __('✅ Done: %1$s now has your task: %2$s at %3$s, %4$s.', 'eventcrew'),
            $cover->name(),
            $task->roleLabel(),
            $task->eventName(),
            self::whenText($task)
        );
    }

    private static function coverText(string $status, Person $holder, Task $task): string
    {
        $args = [$holder->name(), $task->roleLabel(), $task->eventName(), self::whenText($task)];

        return match ($status) {
            Takeover::REFUSED => sprintf(
                /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                __('%1$s keeps their task: %2$s at %3$s, %4$s. Your request to take it over was declined.', 'eventcrew'),
                ...$args
            ),
            Takeover::EXPIRED => sprintf(
                /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                __('%1$s did not answer in time, so they keep their task: %2$s at %3$s, %4$s.', 'eventcrew'),
                ...$args
            ),
            'failed' => sprintf(
                /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                __('%1$s agreed, but their task could not be moved to you: %2$s at %3$s, %4$s. Nothing changed.', 'eventcrew'),
                ...$args
            ),
            default => sprintf(
                /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                __('Your request to take over the task of %1$s is closed, because the task changed or was cancelled: %2$s at %3$s, %4$s.', 'eventcrew'),
                ...$args
            ),
        };
    }

    private function alreadyPendingText(Takeover $existing, Person $cover, Person $holder): string
    {
        if ($existing->coverId !== $cover->id) {
            return self::otherPendingText();
        }

        return sprintf(
            /* translators: 1: holder's name, 2: answer deadline */
            __('You already asked to take over this task. %1$s has until %2$s to answer.', 'eventcrew'),
            $holder->name(),
            TakeoverDeadline::label($existing->deadlineAt)
        );
    }

    private static function otherPendingText(): string
    {
        return __('Someone else has already asked to take over this task. Wait until that request is answered.', 'eventcrew');
    }

    private static function tooLateText(): string
    {
        $hours = TakeoverDeadline::cutoffHours();

        if (0 === $hours) {
            return __('It is too late to ask: the task has already started.', 'eventcrew');
        }

        return sprintf(
            /* translators: %d: hours before the task starts */
            _n(
                'It is too late to ask: a takeover must be answered at least %d hour before the task starts.',
                'It is too late to ask: a takeover must be answered at least %d hours before the task starts.',
                $hours,
                'eventcrew'
            ),
            $hours
        );
    }

    private static function whenText(Task $task): string
    {
        $time = $task->timeRange();
        $day = Dates::dayLabel($task->taskDate);

        return '' === $time ? $day : $day . ' ' . $time;
    }
}
