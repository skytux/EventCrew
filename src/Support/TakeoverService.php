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
use EventCrew\Telegram\BoardRenderer;
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

    /** The email's "Open in Telegram" link: t.me/<bot>?start=tov-<id> (MessageActions::startUrl()). */
    public const START_VERB = 'tov';

    public function __construct(
        private readonly TakeoverRepository $takeovers,
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly ClaimNotifier $claims,
        private readonly BoardService $board,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer,
        private readonly ?StandingCalculator $standing = null
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
     * The cover asks for the task behind one assignment, from any channel:
     * the bot's rep:<id> button and the crew page's Take someone's task
     * (Owner 2026-11-08). Runs the checks request() leaves to its caller,
     * then request(). The caller has checked the cover's email is verified.
     *
     * @return array{ok: bool, text: string}
     */
    public function requestFor(int $assignmentId, Person $cover): array
    {
        $original = $this->assignments->find($assignmentId);
        $task = null === $original ? null : $this->tasks->find($original->taskId);

        // Someone without the role is never offered a restricted task, so it
        // is worded like a task that is gone.
        if (
            null === $original || null === $task || ! $original->isOccupying() || $task->isPast()
            || SignupService::blocksRestrictedSlot($task, $cover)
            || BoardVisibility::blocksGuestSignup($task, $cover)
        ) {
            return ['ok' => false, 'text' => __('That task is no longer available.', 'eventcrew')];
        }

        if ($cover->id === $original->personId) {
            return ['ok' => false, 'text' => __('That’s your own task.', 'eventcrew')];
        }

        $existing = $this->assignments->findFor($task->id, $cover->id);

        // No overlap check: covering someone is exactly the case where a
        // clash on paper is somebody's own business. Taking the same task
        // twice is still stopped.
        if (null !== $existing && $existing->isOccupying()) {
            return ['ok' => false, 'text' => __('You’re already signed up for that one.', 'eventcrew')];
        }

        return $this->request($original, $task, $cover);
    }

    /**
     * Whose places on a task the cover could ask to take over, for the
     * board's Take someone's task on any task someone is on (Owner
     * 2026-11-10) and the web page's, likewise since 2026-11-11: one
     * entry per holder, by first name, or "Person 2" where the role hides
     * names from the cover (Anonymous display). Empty when the cover may not
     * take the task, is on it already, or it is too late to ask.
     *
     * @return list<array{assignment_id: int, name: string}>
     */
    public function holdersFor(Task $task, Person $cover): array
    {
        if (
            $task->isPast() || SignupService::blocksRestrictedSlot($task, $cover)
            || BoardVisibility::blocksGuestSignup($task, $cover)
            || null === TakeoverDeadline::deadline(TakeoverDeadline::now(), TakeoverDeadline::startOf($task))
        ) {
            return [];
        }

        $named = BoardVisibility::showsNames($task, $cover);
        $holders = [];

        foreach ($this->assignments->forTask($task->id) as $assignment) {
            if (! $assignment->isOccupying()) {
                continue;
            }

            if ($assignment->personId === $cover->id) {
                return [];
            }

            $holder = $named ? $this->people->find($assignment->personId) : null;
            $name = null === $holder ? '' : BoardRenderer::firstNames([$holder->name()])[0];

            $holders[] = [
                'assignment_id' => $assignment->id,
                /* translators: %d: 1, 2, ... for each person on a task whose names are hidden */
                'name' => '' !== $name ? $name : sprintf(__('Person %d', 'eventcrew'), count($holders) + 1),
            ];
        }

        return $holders;
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

        // A newcomer without the team's OK cannot take a task this way either.
        $approval = SignupService::approvalRefusal($cover);

        if ('' !== $approval) {
            $text = SignupService::WAITING === $approval
                ? SignupService::waitingText()
                : SignupService::turnedDownText();

            return ['ok' => false, 'text' => $text];
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
        // The history gets one line for the move ("Sam took over from Kim"),
        // not the holder's status change and the cover's sign-up apart.
        $outcome = History::muted(function () use ($original, $task, $cover): string {
            $this->assignments->setStatus($original->id, AssignmentStatus::REPLACED, $cover->id, MarkedBy::PERSON);

            // The cover steps into the same slot, so it keeps the slot's answer
            // to "Count toward free-entry credit?".
            return $this->assignments->join($task->id, $cover->id, $original->countsCredit);
        });

        if (! in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true)) {
            History::muted(fn () => $this->assignments->setStatus(
                $original->id,
                AssignmentStatus::SIGNED_UP,
                $cover->id,
                MarkedBy::PERSON
            ));
            $this->takeovers->setStatus($takeover->id, Takeover::CANCELLED);
            Language::forPerson($cover, fn () => $this->sendToCover($cover, $holder, $task, 'failed'));

            return __('The task could not be moved, so you keep it.', 'eventcrew');
        }

        History::record(History::REPLACED, [
            'person_id' => $holder->id,
            'other_person_id' => $cover->id,
            'task_id' => $task->id,
            'event_date' => $task->taskDate,
        ]);

        $this->claims->confirmSignup($cover, $task->id);

        // In the bot this line is the holder's "done" message (no DM is sent),
        // so it is the takeover_done_dm template there.
        $done = $viaBot
            ? MessageTemplates::body('takeover_done_dm', ['person' => $holder, 'cover' => $cover, 'task' => $task])
            : self::doneText($cover, $task);
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
        $context = $this->askContext($holder, $cover, $task, $deadlineAt);

        // Transactional: the holder must be able to answer, so the person's
        // notification settings do not apply.
        // Someone else asks, so the DM opens and signs off (DmBody).
        if (null !== $holder->telegramChatId) {
            $this->sendAsk($holder->telegramChatId, $id, $context, true);
        }

        $actions = [['label' => __('Answer the request', 'eventcrew'), 'url' => self::answerUrl($id)]];
        // The same question in the bot, with its two buttons, for a holder
        // who uses it (t.me/<bot>?start=tov-<id>). Opening it answers nothing.
        $bot = null === $holder->telegramChatId ? '' : MessageActions::startUrl(self::START_VERB, $id);

        if ('' !== $bot) {
            $actions[] = ['label' => __('Answer the request', 'eventcrew'), 'url' => $bot, 'style' => EmailBody::STYLE_TELEGRAM];
        }

        $this->mailer->toPerson(
            $holder->id,
            $holder->email,
            MessageTemplates::subject('takeover_ask', $context),
            MessageTemplates::body('takeover_ask', $context),
            $actions
        );
    }

    /**
     * The request again in the holder's private chat, from the email's
     * "Open in Telegram" link (tov-<id>): the same text and two buttons as
     * the DM. Only the holder sees it, only while it is open; anyone else,
     * or a closed request, gets a line saying so. Nothing is answered here.
     */
    public function showRequest(int $takeoverId, ?Person $reader, int $chatId): void
    {
        $takeover = $this->takeovers->find($takeoverId);

        if (null === $takeover || null === $reader || $takeover->holderId !== $reader->id) {
            $this->telegram->sendMessage($chatId, __('This link is no longer valid.', 'eventcrew'));

            return;
        }

        $task = $this->tasks->find($takeover->taskId);
        $cover = $this->people->find($takeover->coverId);

        if (! $takeover->isPending() || null === $task || null === $cover) {
            $this->telegram->sendMessage($chatId, self::closedText($takeover->isPending() ? '' : $takeover->status));

            return;
        }

        $this->sendAsk($chatId, $takeover->id, $this->askContext($reader, $cover, $task, $takeover->deadlineAt));
    }

    /**
     * "Sam has done 5 tasks." for the takeover request, so the holder knows
     * who is asking.
     */
    public static function coverRecord(string $coverName, int $done): string
    {
        return 0 === $done
            ? sprintf(
                /* translators: %s: the first name of the person who asks */
                __('%s has not done a task yet.', 'eventcrew'),
                $coverName
            )
            : sprintf(
                /* translators: 1: the first name of the person who asks, 2: tasks done */
                _n('%1$s has done %2$d task.', '%1$s has done %2$d tasks.', $done, 'eventcrew'),
                $coverName,
                $done
            );
    }

    /** @return array<string, mixed> */
    private function askContext(Person $holder, Person $cover, Task $task, string $deadlineAt): array
    {
        return [
            'person' => $holder,
            'cover' => $cover,
            'task' => $task,
            'deadline' => $deadlineAt,
            'cover_record' => null === $this->standing
                ? ''
                : self::coverRecord($cover->name(), $this->standing->for($cover->id)->completedCount),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param bool $framed True when the request just arrives; false when the
     *     holder opened it from the email's link, a reply to their own tap.
     */
    private function sendAsk(int $chatId, int $id, array $context, bool $framed = false): void
    {
        $text = MessageTemplates::body('takeover_ask_dm', $context);
        $text = $framed ? DmBody::frame($context['person'], $text) : $text;
        $this->telegram->sendMessage($chatId, $text, ['inline_keyboard' => [[
            ['text' => __('✅ Let them take it', 'eventcrew'), 'callback_data' => self::CALLBACK_PREFIX . 'y:' . $id],
            ['text' => __('❌ Keep my task', 'eventcrew'), 'callback_data' => self::CALLBACK_PREFIX . 'n:' . $id],
        ]]]);
    }

    private function tellHolderDone(Person $holder, Person $cover, Task $task, bool $viaBot): void
    {
        Language::forPerson($holder, function () use ($holder, $cover, $task, $viaBot): void {
            $context = ['person' => $holder, 'cover' => $cover, 'task' => $task];
            $actions = MessageActions::of('takeover_done', $context, $this->mailer);

            if (! $viaBot && null !== $holder->telegramChatId) {
                $this->telegram->sendMessage(
                    $holder->telegramChatId,
                    MessageTemplates::body('takeover_done_dm', $context),
                    MessageActions::replyMarkup($actions)
                );
            }

            $this->mailer->toPerson(
                $holder->id,
                $holder->email,
                MessageTemplates::subject('takeover_done', $context),
                MessageTemplates::body('takeover_done', $context),
                MessageActions::email($actions)
            );
        });
    }

    private function tellCover(Person $cover, Person $holder, Task $task, string $status): void
    {
        Language::forPerson($cover, function () use ($cover, $holder, $task, $status): void {
            $this->sendToCover($cover, $holder, $task, $status);
        });
    }

    /**
     * The outcome of the request, $status (a Takeover status or 'failed'): a
     * DM when the cover has Telegram linked, and always an email.
     */
    private function sendToCover(Person $cover, Person $holder, Task $task, string $status): void
    {
        $kind = match ($status) {
            Takeover::REFUSED => 'takeover_refused',
            Takeover::EXPIRED => 'takeover_expired',
            'failed' => 'takeover_failed',
            default => 'takeover_closed',
        };
        // Other open tasks of the same event the cover can sign up for.
        $rows = array_values(array_filter(
            (new OpenTaskList($this->tasks, $this->assignments))->forPerson($cover, $task->eventPostId, OpenTaskList::MAX_TASKS + 1),
            static fn (array $row): bool => $row['task']->id !== $task->id
        ));
        $context = [
            'person' => $cover,
            'holder' => $holder,
            'task' => $task,
            OpenTaskList::CONTEXT_KEY => array_slice($rows, 0, OpenTaskList::MAX_TASKS),
        ];
        $actions = MessageActions::of($kind, $context, $this->mailer);

        if (null !== $cover->telegramChatId) {
            // The holder's answer, the clock or a change on the task, not
            // the cover's own doing: the DM opens and signs off (DmBody).
            $this->telegram->sendMessage(
                $cover->telegramChatId,
                DmBody::frame($cover, MessageTemplates::body($kind . '_dm', $context)),
                MessageActions::replyMarkup($actions)
            );
        }

        $this->mailer->toPerson(
            $cover->id,
            $cover->email,
            MessageTemplates::subject($kind, $context),
            MessageTemplates::body($kind, $context),
            MessageActions::email($actions)
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
