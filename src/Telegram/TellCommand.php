<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\DmBody;
use EventCrew\Support\EmailBody;
use EventCrew\Support\Language;
use EventCrew\Support\Mailer;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\PushNotifier;
use EventCrew\Support\RoleTaskService;

/**
 * /tell [date] <task> ; <message>: an organizer writes to everyone on one
 * task, in a private chat.
 *
 * - The task is found by name on the next event (or the date given), as
 *   /assign finds it; two or more matches become buttons.
 * - The organizer sees the message as it will go out and taps ✅.
 * - Each person on the task gets it in a DM framed like every message they
 *   did not ask for (DmBody), with one line naming the organizer and the
 *   task in their own language; the organizer's text goes as written.
 *   Someone without a DM chat gets it by email. The person signed up for the
 *   task, so it counts as a reminder: the message always reaches them.
 * - An organizer can send RATE_LIMIT messages per hour.
 */
final class TellCommand
{
    public const PREFIX = 'tl:';
    public const STATE_PREFIX = 'eventcrew_tg_tell_';
    public const COMMAND = 'tell';

    /** Messages one organizer may send in RATE_WINDOW. */
    public const RATE_LIMIT = 5;

    private const RATE_WINDOW = HOUR_IN_SECONDS;

    private const RATE_PREFIX = 'eventcrew_tg_tell_sent_';

    /** Longest message, in characters. */
    public const MAX_LENGTH = 1500;

    private CommandFlow $flow;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly Mailer $mailer,
        TelegramClient $telegram,
        private readonly NotificationPreferences $prefs = new NotificationPreferences()
    ) {
        $this->flow = new CommandFlow($people, $telegram, self::PREFIX, self::STATE_PREFIX);
    }

    public function onCommand(int $chatId, int $fromId, string $text, bool $isPrivate): void
    {
        if (! $this->flow->inPrivate($chatId, $isPrivate, '/' . self::COMMAND)) {
            return;
        }

        $organizer = $this->flow->organizerOrSay($chatId, $fromId, '/' . self::COMMAND);

        if (null === $organizer) {
            return;
        }

        $telegram = $this->flow->telegram();
        [$date, $args] = CommandFlow::takeDate(CommandFlow::args($text));
        [$taskQuery, $message] = array_map('trim', array_pad(explode(';', $args, 2), 2, ''));

        if ('' === $taskQuery || '' === $message) {
            $telegram->sendMessage($chatId, self::usage());

            return;
        }

        if (mb_strlen($message) > self::MAX_LENGTH) {
            /* translators: %d: the most characters a message may have. */
            $telegram->sendMessage($chatId, sprintf(__('That message is too long. At most %d characters.', 'eventcrew'), self::MAX_LENGTH));

            return;
        }

        if (! $this->underLimit($organizer)) {
            $telegram->sendMessage($chatId, self::limitText());

            return;
        }

        $pool = $this->tasks->upcoming();

        if ('' !== $date) {
            $pool = array_values(array_filter($pool, static fn (Task $t): bool => $t->taskDate === $date));
        }

        $found = AssignCommand::matchTasks($taskQuery, $pool);

        if ([] === $found) {
            $telegram->sendMessage(
                $chatId,
                '' !== $date && [] === $pool
                    /* translators: %s: a short day label, like "Sat 14 Nov". */
                    ? sprintf(__('There are no tasks on %s.', 'eventcrew'), Dates::dayLabel($date))
                    /* translators: %s: the task name the organizer typed. */
                    : sprintf(__('No task called “%s” was found.', 'eventcrew'), $taskQuery)
            );

            return;
        }

        $state = [
            'user' => $fromId,
            'tasks' => array_map(static fn (Task $t): int => $t->id, $found),
            'task' => 1 === count($found) ? $found[0]->id : 0,
            'message' => $message,
        ];
        $id = $this->flow->start($state);

        if (0 === $state['task']) {
            $options = [];

            foreach ($found as $task) {
                $options[$task->id] = CommandFlow::taskLabel($task);
            }

            $this->flow->choose($chatId, $id, 't', __('Which task?', 'eventcrew'), $options);

            return;
        }

        $this->confirm($chatId, $id, $state, $organizer);
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function onCallback(array $callbackQuery): void
    {
        $tap = $this->flow->tap($callbackQuery);

        if (null === $tap) {
            return;
        }

        $telegram = $this->flow->telegram();
        $organizer = $this->flow->organizerTap($tap);

        if (null === $organizer) {
            return;
        }

        $telegram->answerCallbackQuery($tap['callback']);
        $state = $tap['state'];

        if ('t' === $tap['step']) {
            if (! CommandFlow::offered($state, 'tasks', $tap['choice'])) {
                return;
            }

            $state['task'] = $tap['choice'];
            $this->flow->save($tap['id'], $state);
            $this->confirm($tap['chat'], $tap['id'], $state, $organizer);

            return;
        }

        if ('y' !== $tap['step']) {
            return;
        }

        $this->flow->drop($tap['id']);
        $task = $this->tasks->find((int) ($state['task'] ?? 0));

        if (null === $task) {
            $telegram->sendMessage($tap['chat'], RoleTaskService::text(RoleTaskService::NOT_RESTRICTED));

            return;
        }

        if (! $this->underLimit($organizer)) {
            $telegram->sendMessage($tap['chat'], self::limitText());

            return;
        }

        $this->countSend($organizer);
        $sent = $this->send($task, $organizer, (string) ($state['message'] ?? ''));
        $total = $sent['dm'] + $sent['email'];

        $telegram->sendMessage($tap['chat'], CommandFlow::result(
            $total > 0,
            CommandFlow::taskLabel($task),
            sprintf(
                /* translators: 1: people the message went to, 2: of them on Telegram, 3: of them by email. */
                _n('Sent to %1$d person (Telegram %2$d, email %3$d).', 'Sent to %1$d people (Telegram %2$d, email %3$d).', $total, 'eventcrew'),
                $total,
                $sent['dm'],
                $sent['email']
            )
        ));
    }

    public static function usage(): string
    {
        return __("Message everyone on a task: /tell task ; message\nExample: /tell Bar ; Please come 15 minutes early.\nA date first picks another event: /tell 21.11. Bar ; …", 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    }

    /**
     * The message as the people will see it, and ✅ / ✖️.
     *
     * @param array<string, mixed> $state
     */
    private function confirm(int $chatId, string $id, array $state, Person $organizer): void
    {
        $task = $this->tasks->find((int) $state['task']);
        $telegram = $this->flow->telegram();

        if (null === $task) {
            $this->flow->drop($id);
            $telegram->sendMessage($chatId, RoleTaskService::text(RoleTaskService::NOT_RESTRICTED));

            return;
        }

        $count = count($this->recipients($task, $organizer));

        if (0 === $count) {
            $this->flow->drop($id);
            /* translators: %s: a task with its day and time. */
            $telegram->sendMessage($chatId, sprintf(__('Nobody else is on %s.', 'eventcrew'), CommandFlow::taskLabel($task)));

            return;
        }

        $this->flow->confirm(
            $chatId,
            $id,
            sprintf(
                /* translators: 1: number of people, 2: a task with its day and time. */
                _n('Send this to %1$d person on %2$s?', 'Send this to %1$d people on %2$s?', $count, 'eventcrew'),
                $count,
                CommandFlow::taskLabel($task)
            ) . "\n\n" . self::header($organizer, $task) . "\n" . (string) $state['message'],
            __('Send', 'eventcrew')
        );
    }

    /**
     * Sends the message to everyone on the task but the organizer.
     *
     * @return array{dm: int, email: int}
     */
    private function send(Task $task, Person $organizer, string $message): array
    {
        $sent = ['dm' => 0, 'email' => 0];

        foreach ($this->recipients($task, $organizer) as $person) {
            $channel = Language::forPerson($person, function () use ($person, $task, $organizer, $message): string {
                $header = self::header($organizer, $task);

                // The app as well, on top of the one channel counted below.
                $pushed = PushNotifier::notify($person, NotificationPreferences::REMINDER, $header . "\n" . $message);
                $dmAllowed = $this->prefs->dmAllowed($person, NotificationPreferences::REMINDER);

                // Chose the app over Telegram (ChannelChoice) and the app
                // reached them: no DM, and no email in its place, as when the
                // DM goes. Counted with Telegram, the personal channel.
                if ($dmAllowed && ! $this->prefs->dmWanted($person, NotificationPreferences::REMINDER, $pushed)) {
                    return 'dm';
                }

                if ($dmAllowed) {
                    $done = $this->flow->telegram()->sendMessage(
                        (int) $person->telegramChatId,
                        EmailBody::forDm(DmBody::frame($person, $header . "\n" . $message))
                    );

                    if (null !== $done) {
                        return 'dm';
                    }
                }

                $mailed = $this->mailer->toPerson(
                    $person->id,
                    $person->email,
                    /* translators: 1: an organizer's name, 2: a task with its day. */
                    sprintf(__('Message from %1$s: %2$s', 'eventcrew'), $organizer->name(), $task->roleLabel() . ' · ' . Dates::dayLabel($task->taskDate)),
                    EmailBody::withGreeting($person->name(), $header . "\n\n" . $message)
                );

                return $mailed ? 'email' : '';
            });

            if ('' !== $channel) {
                $sent[$channel]++;
            }
        }

        return $sent;
    }

    /**
     * Everyone who still holds the task, the organizer left out.
     *
     * @return list<Person>
     */
    private function recipients(Task $task, Person $organizer): array
    {
        $people = [];

        foreach ($this->assignments->forTask($task->id) as $assignment) {
            if (! $assignment->isOccupying() || $assignment->personId === $organizer->id || isset($people[$assignment->personId])) {
                continue;
            }

            $person = $this->people->find($assignment->personId);

            if (null !== $person && ! $person->isAnonymized()) {
                $people[$person->id] = $person;
            }
        }

        return array_values($people);
    }

    /**
     * "Kim wrote to the crew of Bar · Sat 14 Nov 20:00–23:00:", in the
     * current language.
     */
    private static function header(Person $organizer, Task $task): string
    {
        /* translators: 1: an organizer's name, 2: a task with its day and time. */
        return sprintf(__('%1$s wrote to the crew of %2$s:', 'eventcrew'), $organizer->name(), CommandFlow::taskLabel($task));
    }

    private function underLimit(Person $organizer): bool
    {
        return count($this->recentSends($organizer)) < self::RATE_LIMIT;
    }

    private function countSend(Person $organizer): void
    {
        $sends = $this->recentSends($organizer);
        $sends[] = time();
        set_transient(self::RATE_PREFIX . $organizer->id, $sends, self::RATE_WINDOW);
    }

    /**
     * @return list<int>
     */
    private function recentSends(Person $organizer): array
    {
        $stored = get_transient(self::RATE_PREFIX . $organizer->id);
        $since = time() - self::RATE_WINDOW;

        return array_values(array_filter(
            array_map('intval', is_array($stored) ? $stored : []),
            static fn (int $at): bool => $at > $since
        ));
    }

    private static function limitText(): string
    {
        return sprintf(
            /* translators: %d: how many messages an organizer may send in an hour. */
            __('You can send %d messages an hour. Try again later.', 'eventcrew'),
            self::RATE_LIMIT
        );
    }
}
