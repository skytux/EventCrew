<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Models\Redemption;
use EventCrew\Models\Task;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\ChannelChoice;
use EventCrew\Support\Dates;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\TicketFile;
use EventCrew\Support\TicketTransferService;
use EventCrew\Web\CrewAdminRoster;
use EventCrew\Web\DoorOrganizer;

/**
 * The Crew app actions that had no typed form in the bot (parityB), in a
 * private chat:
 *
 * Organizers, each asked with ✅ / ✖️ first, through the same services as
 * the Admin tab's Roster screen (CrewAdminRoster, DoorOrganizer):
 * - /markall [date] <task> ; <arrived|done>: "Mark all" on a task;
 * - /buyers [date] add <name ; name ...>, /buyers [date] reset: the Holvi
 *   ticket list, pasted or emptied; the ticket file itself is sent to the
 *   bot as a document (caption: the date, optional);
 * - /testmail tickets [date], /testmail followup [date]: "Send a test to
 *   me", to the organizer's own address only;
 * - /checkmail: "Check the mailbox now".
 *
 * Everyone with a verified account:
 * - /giveaway [date] [name or email] [; their name]: 🎁 Give away
 *   (TransferBot), /giveaway cancel [date]: ✖️ Cancel offer;
 * - /notify <type> <telegram|email|app> <on|off>: one switch of 🔔
 *   Notifications, as its toggle sets it; /notify <app|telegram|both>:
 *   "Where do you want them?".
 *
 * Without a date the night the Roster screen opens on is meant (the next
 * night with tasks for /buyers and /testmail). The Crew app's helper box
 * runs the organizer ones through prepareWeb(), chooseWeb() and runWeb().
 */
final class ParityCommands
{
    public const PREFIX = 'pb:';
    public const STATE_PREFIX = 'eventcrew_tg_pb_';

    public const MARK_ALL = 'markall';
    public const BUYERS = 'buyers';
    public const TEST_MAIL = 'testmail';
    public const CHECK_MAIL = 'checkmail';
    public const GIVE_AWAY = 'giveaway';
    public const NOTIFY = 'notify';

    /** Organizer commands. */
    public const ORGANIZER = [self::MARK_ALL, self::BUYERS, self::TEST_MAIL, self::CHECK_MAIL];

    /** The commands this class answers. */
    public const COMMANDS = [...self::ORGANIZER, self::GIVE_AWAY, self::NOTIFY];

    /** The words for the marks /markall sets. */
    private const MARKS = [
        'arrived' => AssignmentStatus::ARRIVED,
        'arrive' => AssignmentStatus::ARRIVED,
        'here' => AssignmentStatus::ARRIVED,
        'done' => AssignmentStatus::COMPLETED,
        'completed' => AssignmentStatus::COMPLETED,
        'complete' => AssignmentStatus::COMPLETED,
    ];

    /** The channel words of /notify. */
    public const CHANNELS = [
        'telegram' => 'dm',
        'dm' => 'dm',
        'email' => 'email',
        'app' => 'push',
        'push' => 'push',
    ];

    /** The sign of each channel, as on the 🔔 Notifications buttons. */
    private const SIGNS = ['dm' => '📱', 'email' => '✉', 'push' => '🔔'];

    private CommandFlow $flow;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly CrewAdminRoster $roster,
        private readonly DoorOrganizer $door,
        private readonly RedemptionRepository $redemptions,
        private readonly TicketTransferService $transfers,
        private readonly TransferBot $transfer,
        TelegramClient $telegram
    ) {
        $this->flow = new CommandFlow($people, $telegram, self::PREFIX, self::STATE_PREFIX);
    }

    /**
     * Answers one of COMMANDS. False when $text is none of them.
     */
    public function onCommand(int $chatId, int $fromId, string $text, bool $isPrivate): bool
    {
        $command = self::commandOf($text);

        if ('' === $command) {
            return false;
        }

        if (! $this->flow->inPrivate($chatId, $isPrivate, '/' . $command)) {
            return true;
        }

        $args = CommandFlow::args($text);

        if (self::GIVE_AWAY === $command || self::NOTIFY === $command) {
            $member = $this->flow->member($fromId);

            if (null === $member) {
                $this->flow->telegram()->sendMessage($chatId, SetupNudge::text());

                return true;
            }

            self::GIVE_AWAY === $command
                ? $this->giveAway($member, $chatId, $fromId, $args)
                : $this->flow->telegram()->sendMessage($chatId, $this->notify($member, $args)['text']);

            return true;
        }

        if (null === $this->flow->organizerOrSay($chatId, $fromId, '/' . $command)) {
            return true;
        }

        $this->ask($chatId, $this->question($command, $args, $fromId));

        return true;
    }

    /** The command $text starts with when it is one of COMMANDS, or ''. */
    public static function commandOf(string $text): string
    {
        if (1 !== preg_match('#^/(\w+)(?:@\w+)?(?:\s|$)#u', trim($text), $m)) {
            return '';
        }

        $command = strtolower($m[1]);

        return in_array($command, self::COMMANDS, true) ? $command : '';
    }

    /**
     * A file sent in a private chat: an organizer's Holvi ticket file is
     * added to the ticket list after ✅, as the Roster screen's upload adds
     * it. False when it is not that (not a ticket file, or not from an
     * organizer), so the message goes on as before.
     *
     * @param array<string, mixed> $message
     */
    public function onDocument(int $chatId, int $fromId, array $message): bool
    {
        $document = is_array($message['document'] ?? null) ? $message['document'] : [];
        $name = sanitize_file_name((string) ($document['file_name'] ?? ''));
        $fileId = (string) ($document['file_id'] ?? '');

        if ('' === $fileId || ! TicketFile::isTicketFile($name) || null === $this->flow->organizer($fromId)) {
            return false;
        }

        if ((int) ($document['file_size'] ?? 0) > DoorOrganizer::MAX_FILE_BYTES) {
            $this->flow->telegram()->sendMessage($chatId, __('That file is too big for a ticket file.', 'eventcrew'));

            return true;
        }

        [$date] = CommandFlow::takeDate(trim((string) ($message['caption'] ?? '')));
        $date = $this->nextNightOr($date);

        if ('' === $date) {
            $this->flow->telegram()->sendMessage($chatId, __('There are no upcoming tasks.', 'eventcrew'));

            return true;
        }

        $this->ask($chatId, self::asked(
            ['user' => $fromId, 'op' => 'upload', 'date' => $date, 'file' => $fileId, 'name' => $name],
            sprintf(
                /* translators: 1: a file name, 2: a short day label, like "Sat 14 Nov". */
                __('Add the ticket buyers in %1$s to the list of %2$s?', 'eventcrew'),
                $name,
                Dates::dayLabel($date)
            ) . "\n" . __('Send the file with a date as its caption (21.11.) for another night.', 'eventcrew'),
            __('Add', 'eventcrew')
        ));

        return true;
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
        $state = $tap['state'];

        // /giveaway: the night was chosen.
        if (in_array($tap['step'], ['g', 'c'], true)) {
            $telegram->answerCallbackQuery($tap['callback']);
            $this->flow->drop($tap['id']);
            $giver = $this->flow->member($tap['from']);

            if (null === $giver || ! CommandFlow::offered($state, 'offered', $tap['choice'])) {
                $telegram->sendMessage($tap['chat'], __('This request has expired. Please send it again.', 'eventcrew'));

                return;
            }

            'g' === $tap['step']
                ? $this->transfer->begin($giver, $tap['chat'], $tap['choice'], (string) ($state['who'] ?? ''), (string) ($state['their'] ?? ''))
                : $this->askCancel($giver, $tap['chat'], $tap['from'], $tap['choice']);

            return;
        }

        // /giveaway cancel: ✅ takes the offer back.
        if ('y' === $tap['step'] && self::GIVE_AWAY === ($state['op'] ?? '')) {
            $telegram->answerCallbackQuery($tap['callback']);
            $this->flow->drop($tap['id']);
            $giver = $this->flow->member($tap['from']);

            if (null !== $giver) {
                $this->transfer->cancelFor($giver, $tap['chat'], (int) ($state['rid'] ?? 0));
            }

            return;
        }

        $organizer = $this->flow->organizerTap($tap);

        if (null === $organizer) {
            return;
        }

        $telegram->answerCallbackQuery($tap['callback']);

        // /markall: the task was chosen.
        if ('t' === $tap['step']) {
            if (! CommandFlow::offered($state, 'offered', $tap['choice'])) {
                $this->flow->drop($tap['id']);
                $telegram->sendMessage($tap['chat'], __('This request has expired. Please send it again.', 'eventcrew'));

                return;
            }

            $this->flow->drop($tap['id']);
            $this->ask($tap['chat'], $this->askMarkAll(['task' => $tap['choice']] + $state));

            return;
        }

        if ('y' !== $tap['step']) {
            return;
        }

        $this->flow->drop($tap['id']);
        $telegram->sendMessage($tap['chat'], $this->change($organizer, $state)['text']);
    }

    /**
     * The Crew app's helper box: an organizer command with its arguments,
     * read as the bot reads them. The question to show with Confirm and the
     * state to run, the tasks to choose from, or (ok false) why not.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    public function prepareWeb(string $command, string $args): array
    {
        if (! in_array($command, self::ORGANIZER, true)) {
            return ['ok' => false, 'text' => __('That can no longer be done.', 'eventcrew'), 'state' => []];
        }

        return self::forWeb($this->question($command, $args, 0));
    }

    /**
     * A task chosen in the helper box (/markall).
     *
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    public function chooseWeb(array $state): array
    {
        if (self::MARK_ALL !== ($state['op'] ?? '')) {
            return ['ok' => false, 'text' => __('That can no longer be done.', 'eventcrew'), 'state' => []];
        }

        return self::forWeb($this->askMarkAll($state));
    }

    /**
     * Confirm in the helper box: the state prepareWeb() gave, run as ✅ runs
     * it. "changed" only when the change went through.
     *
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string}
     */
    public function runWeb(Person $organizer, array $state): array
    {
        if ('upload' === ($state['op'] ?? '')) {
            return ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')];
        }

        $done = $this->change($organizer, $state);

        return ['changed' => $done['done'], 'text' => $done['text']];
    }

    /**
     * /notify: sets one switch of the person's notifications, as its toggle
     * does. "done" only when it was set.
     *
     * @return array{done: bool, text: string}
     */
    public function notify(Person $person, string $args): array
    {
        $choice = ChannelChoice::parse(strtolower(trim($args)));

        // "Where do you want them?": app, telegram or both.
        if (null !== $choice) {
            $this->people->addRoleNotices($person->id, ChannelChoice::entriesFor($choice, (string) current_time('mysql')));

            return ['done' => true, 'text' => '✅ ' . ChannelChoice::confirmation($choice)];
        }

        $change = self::notifyChange($person, $args);

        if (null === $change) {
            return ['done' => false, 'text' => self::usage(self::NOTIFY)];
        }

        [$type, $channel, $on] = $change;
        $map = (new NotificationPreferences())->asMap($person);
        $map[$type][$channel] = $on;
        $this->people->setNotifyPrefs($person->id, $map);

        return [
            'done' => true,
            'text' => '✅ ' . __('Saved.', 'eventcrew') . "\n" . self::switchLine($person, $type, $channel, $on),
        ];
    }

    /**
     * "<type> <channel> <on|off>" read for $person: the type (one of the rows
     * they see), the channel (one the row offers) and on or off; null when it
     * is not that.
     *
     * @return array{0: string, 1: string, 2: bool}|null
     */
    public static function notifyChange(Person $person, string $args): ?array
    {
        $words = preg_split('/\s+/u', strtolower(trim($args)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (3 !== count($words) || ! in_array($words[2], ['on', 'off'], true)) {
            return null;
        }

        $type = str_replace('-', '_', $words[0]);
        $channel = self::CHANNELS[$words[1]] ?? '';

        if (
            ! array_key_exists($type, NotificationPreferences::labelsFor($person))
            || ! in_array($channel, NotificationPreferences::channelsFor($type), true)
        ) {
            return null;
        }

        return [$type, $channel, 'on' === $words[2]];
    }

    /**
     * The question the Crew app's helper box asks before /notify runs, or
     * '' when $args is not a /notify line for $person.
     */
    public static function notifyQuestion(Person $person, string $args): string
    {
        $choice = ChannelChoice::parse(strtolower(trim($args)));

        if (null !== $choice) {
            /* translators: %s: where notifications go, like "App only". */
            return sprintf(__('Get your notifications here: %s?', 'eventcrew'), ChannelChoice::labels()[$choice]);
        }

        $change = self::notifyChange($person, $args);

        if (null === $change) {
            return '';
        }

        [$type, $channel, $on] = $change;

        return ($on ? __('Turn this notification on?', 'eventcrew') : __('Turn this notification off?', 'eventcrew'))
            . "\n" . self::switchLine($person, $type, $channel, $on);
    }

    /** "Free-entry ticket ready: ✉ ❌", one switch as the 🔔 menu shows it. */
    public static function switchLine(Person $person, string $type, string $channel, bool $on): string
    {
        return (NotificationPreferences::labelsFor($person)[$type] ?? $type) . ': '
            . (self::SIGNS[$channel] ?? '') . ' ' . ($on ? '✅' : '❌');
    }

    public static function usage(string $command): string
    {
        return match ($command) {
            self::MARK_ALL => __("Mark everyone on a task: /markall Bar ; done (or arrived)\nA date first picks another night: /markall 21.11. Bar ; done", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::BUYERS => __("Add ticket buyers by name: /buyers add Anna ; Ben\nEmpty the list: /buyers reset (a date first picks another night)\nOr send the ticket file from Holvi here.", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::TEST_MAIL => __("A test email to you: /testmail tickets or /testmail followup\nA date after it picks another night: /testmail tickets 21.11.", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::NOTIFY => __("Turn one notification on or off: /notify ticket email off\nChannels: telegram, email, app. Where they go: /notify app (or telegram, both)", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            default => '',
        };
    }

    /**
     * @param array{ok: bool, text: string, button: string, state: array<string, mixed>, choices?: array<int, string>} $asked
     */
    private function ask(int $chatId, array $asked): void
    {
        if (isset($asked['choices'])) {
            $id = $this->flow->start($asked['state'] + ['offered' => array_keys($asked['choices'])]);
            $this->flow->choose($chatId, $id, 't', $asked['text'], $asked['choices']);

            return;
        }

        if (! $asked['ok']) {
            $this->flow->telegram()->sendMessage($chatId, $asked['text']);

            return;
        }

        $id = $this->flow->start($asked['state']);
        $this->flow->confirm($chatId, $id, $asked['text'], $asked['button']);
    }

    /**
     * @param array{ok: bool, text: string, button: string, state: array<string, mixed>, choices?: array<int, string>} $asked
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    private static function forWeb(array $asked): array
    {
        $answer = ['ok' => $asked['ok'], 'text' => $asked['text'], 'state' => $asked['ok'] ? $asked['state'] : []];

        if (! isset($asked['choices'])) {
            return $answer;
        }

        if (count($asked['choices']) > \EventCrew\Web\WebChanges::MAX_CHOICES) {
            return ['ok' => false, 'text' => \EventCrew\Web\AssistantWebChanges::several(array_values($asked['choices'])), 'state' => []];
        }

        $answer['choices'] = [];

        foreach ($asked['choices'] as $taskId => $label) {
            $answer['choices'][] = ['label' => $label, 'state' => ['task' => $taskId] + $asked['state']];
        }

        return $answer;
    }

    /**
     * The question before an organizer command changes anything.
     *
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>, choices?: array<int, string>}
     */
    private function question(string $command, string $args, int $fromId): array
    {
        $state = ['user' => $fromId, 'op' => $command];

        return match ($command) {
            self::MARK_ALL => $this->questionMarkAll($state, $args),
            self::BUYERS => $this->questionBuyers($state, $args),
            self::TEST_MAIL => $this->questionTestMail($state, $args),
            self::CHECK_MAIL => '' === $args
                ? self::asked($state, __('Check the mailbox for ticket and guest lists now?', 'eventcrew'), __('Check', 'eventcrew'))
                : self::refused(__('Check the mailbox now: /checkmail', 'eventcrew')),
            default => self::refused(''),
        };
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>, choices?: array<int, string>}
     */
    private function questionMarkAll(array $state, string $args): array
    {
        [$date, $args] = CommandFlow::takeDate($args);
        [$query, $word] = array_map('trim', array_pad(explode(';', $args, 2), 2, ''));
        $status = self::MARKS[strtolower($word)] ?? '';

        if ('' === $query || '' === $status) {
            return self::refused(self::usage(self::MARK_ALL));
        }

        $date = '' !== $date ? $date : (string) $this->roster->dates('')['date'];
        $pool = '' === $date ? [] : $this->tasks->forDate($date);
        $found = AssignCommand::matchTasks($query, $pool);

        if ([] === $found) {
            return self::refused(
                [] === $pool
                    /* translators: %s: a short day label, like "Sat 14 Nov". */
                    ? sprintf(__('There are no tasks on %s.', 'eventcrew'), Dates::dayLabel($date))
                    /* translators: %s: the task name the organizer typed. */
                    : sprintf(__('No task called “%s” was found.', 'eventcrew'), $query)
            );
        }

        $state += ['date' => $date, 'status' => $status, 'task' => 0];

        if (1 === count($found)) {
            return $this->askMarkAll(['task' => $found[0]->id] + $state);
        }

        $choices = [];

        foreach ($found as $task) {
            $choices[$task->id] = CommandFlow::taskLabel($task);
        }

        return ['ok' => false, 'text' => __('Which task?', 'eventcrew'), 'button' => '', 'state' => $state, 'choices' => $choices];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private function askMarkAll(array $state): array
    {
        $task = $this->tasks->find((int) ($state['task'] ?? 0));
        $status = (string) ($state['status'] ?? '');

        if (! $task instanceof Task || ! in_array($status, self::MARKS, true)) {
            return self::refused(__('That task is no longer available.', 'eventcrew'));
        }

        $state['task'] = $task->id;
        $state['date'] = $task->taskDate;
        unset($state['offered']);

        return self::asked(
            $state,
            sprintf(
                /* translators: 1: a task with its day and time, 2: a mark, like "Arrived". */
                __('Mark everyone still on %1$s as %2$s?', 'eventcrew'),
                CommandFlow::taskLabel($task),
                AssignmentStatus::label($status)
            ),
            __('Mark', 'eventcrew')
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private function questionBuyers(array $state, string $args): array
    {
        [$date, $args] = CommandFlow::takeDate($args);
        $parts = preg_split('/\s+/u', $args, 2) ?: [];
        $word = strtolower((string) ($parts[0] ?? ''));
        $rest = trim((string) ($parts[1] ?? ''));

        // "/buyers add 21.11. Anna": the date after the word.
        if ('' === $date && '' !== $rest) {
            [$date, $rest] = CommandFlow::takeDate($rest);
        }

        $date = $this->nextNightOr($date);
        $names = array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            preg_split('/[;\n]+/u', $rest) ?: []
        ), static fn (string $name): bool => '' !== $name));

        if (! in_array($word, ['add', 'reset'], true) || ('add' === $word) !== ([] !== $names)) {
            return self::refused(self::usage(self::BUYERS));
        }

        if ('' === $date) {
            return self::refused(__('There are no upcoming tasks.', 'eventcrew'));
        }

        $day = Dates::dayLabel($date);
        $state['date'] = $date;

        if ('reset' === $word) {
            $state['what'] = 'reset';

            return self::asked(
                $state,
                /* translators: %s: a short day label, like "Sat 14 Nov". */
                sprintf(__('Delete every imported ticket buyer of %s? Ticket emails already sent stay sent.', 'eventcrew'), $day),
                __('Delete', 'eventcrew')
            );
        }

        $state['what'] = 'add';
        $state['names'] = $names;

        return self::asked(
            $state,
            sprintf(
                /* translators: 1: number of names, 2: a short day label, like "Sat 14 Nov", 3: the names. */
                _n('Add %1$d ticket buyer to the list of %2$s?', 'Add %1$d ticket buyers to the list of %2$s?', count($names), 'eventcrew'),
                count($names),
                $day
            ) . "\n" . implode(', ', $names),
            __('Add', 'eventcrew')
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private function questionTestMail(array $state, string $args): array
    {
        $words = preg_split('/\s+/u', strtolower(trim($args)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kind = $words[0] ?? '';
        $date = '';

        if (isset($words[1])) {
            [$date] = CommandFlow::takeDate($words[1]);
        }

        if (! in_array($kind, ['tickets', 'followup'], true) || count($words) > 2 || (isset($words[1]) && '' === $date)) {
            return self::refused(self::usage(self::TEST_MAIL));
        }

        $date = $this->nextNightOr($date);

        if ('' === $date) {
            return self::refused(__('There are no upcoming tasks.', 'eventcrew'));
        }

        $state['date'] = $date;
        $state['what'] = $kind;
        $day = Dates::dayLabel($date);

        return self::asked(
            $state,
            'tickets' === $kind
                /* translators: %s: a short day label, like "Sat 14 Nov". */
                ? sprintf(__('Send a test ticket email for %s to your own address?', 'eventcrew'), $day)
                /* translators: %s: a short day label, like "Sat 14 Nov". */
                : sprintf(__('Send a test of the post-event email for %s to your own address?', 'eventcrew'), $day),
            __('Send', 'eventcrew')
        );
    }

    /**
     * ✅: the change through the Roster screen's own methods, and the answer.
     * Test emails go to $organizer's own address only.
     *
     * @param array<string, mixed> $state
     * @return array{done: bool, text: string}
     */
    private function change(Person $organizer, array $state): array
    {
        $date = (string) ($state['date'] ?? '');
        $head = '' === $date ? '' : Dates::dayLabel($date);

        switch ((string) ($state['op'] ?? '')) {
            case self::MARK_ALL:
                $task = $this->tasks->find((int) ($state['task'] ?? 0));
                $code = null === $task
                    ? 'admin_roster_bulk_failed'
                    : $this->roster->markAll($organizer, $task->taskDate, $task->id, (string) ($state['status'] ?? ''));
                $done = str_starts_with($code, 'admin_roster_updated_');

                return [
                    'done' => $done,
                    'text' => CommandFlow::result($done, null === $task ? '' : CommandFlow::taskLabel($task), CrewAdminRoster::noticeText($code)),
                ];

            case self::BUYERS:
                $code = 'reset' === ($state['what'] ?? '')
                    ? $this->roster->resetBuyers($date)
                    : $this->roster->importBuyers($date, implode("\n", array_map('strval', (array) ($state['names'] ?? []))));
                $done = str_starts_with($code, 'admin_buyers_added_') || str_starts_with($code, 'admin_buyers_deleted_');

                return ['done' => $done, 'text' => CommandFlow::result($done, $head, CrewAdminRoster::noticeText($code))];

            case self::TEST_MAIL:
                $code = 'followup' === ($state['what'] ?? '')
                    ? $this->roster->sendFollowupTest($date, $organizer)
                    : $this->roster->sendTicketTest($date, $organizer);
                $done = str_ends_with($code, '_sent');

                return ['done' => $done, 'text' => CommandFlow::result($done, $head, CrewAdminRoster::noticeText($code))];

            case self::CHECK_MAIL:
                $code = $this->roster->checkMail();
                $done = 'admin_mail_checked' === $code;

                return ['done' => $done, 'text' => CommandFlow::result($done, '', CrewAdminRoster::noticeText($code))];

            case 'upload':
                $bytes = $this->flow->telegram()->downloadFile((string) ($state['file'] ?? ''), DoorOrganizer::MAX_FILE_BYTES);

                if (null === $bytes) {
                    return ['done' => false, 'text' => CommandFlow::result(false, $head, __('The file did not arrive. Try again.', 'eventcrew'))];
                }

                $result = $this->door->importBytes($organizer, $date, (string) ($state['name'] ?? ''), $bytes);

                return ['done' => $result['ok'], 'text' => CommandFlow::result($result['ok'], $head, $result['message'])];
        }

        return ['done' => false, 'text' => CommandFlow::result(false, '', CrewAdminRoster::noticeText('admin_change_failed'))];
    }

    /**
     * /giveaway [date] [name or email] [; their name], /giveaway cancel [date].
     */
    private function giveAway(Person $giver, int $chatId, int $fromId, string $args): void
    {
        ['date' => $date, 'cancel' => $cancel, 'who' => $who, 'their' => $their] = self::giveAwayArgs($args);
        $tickets = $this->tickets($giver, $date, $cancel);

        if ([] === $tickets) {
            $this->flow->telegram()->sendMessage(
                $chatId,
                $cancel
                    ? __('No offer of your tickets is waiting.', 'eventcrew')
                    : TicketTransferService::message(TicketTransferService::isOn() ? TicketTransferService::NOT_GIVABLE : TicketTransferService::DISABLED)
            );

            return;
        }

        if (1 === count($tickets)) {
            $cancel
                ? $this->askCancel($giver, $chatId, $fromId, $tickets[0]->id)
                : $this->transfer->begin($giver, $chatId, $tickets[0]->id, $who, $their);

            return;
        }

        $options = [];

        foreach ($tickets as $ticket) {
            $options[$ticket->id] = $this->transfers->nightLabel((string) $ticket->redeemedFor);
        }

        $id = $this->flow->start(['user' => $fromId, 'op' => self::GIVE_AWAY, 'who' => $who, 'their' => $their, 'offered' => array_keys($options)]);
        $this->flow->choose($chatId, $id, $cancel ? 'c' : 'g', __('Which night?', 'eventcrew'), $options);
    }

    /**
     * "/giveaway [date] [name or email] [; their name]" and "/giveaway cancel
     * [date]" read: the night (Y-m-d or ''), whether it takes an offer back,
     * who gets the ticket and the name of someone who is not a member.
     *
     * @return array{date: string, cancel: bool, who: string, their: string}
     */
    public static function giveAwayArgs(string $args): array
    {
        [$date, $args] = CommandFlow::takeDate(trim($args));
        $cancel = 1 === preg_match('/^cancel(?:\s+(.*))?$/iu', $args, $m);

        if ($cancel) {
            $args = trim((string) ($m[1] ?? ''));

            if ('' === $date) {
                [$date, $args] = CommandFlow::takeDate($args);
            }
        }

        [$who, $their] = array_map('trim', array_pad(explode(';', $args, 2), 2, ''));

        return ['date' => $date, 'cancel' => $cancel, 'who' => $cancel ? '' : $who, 'their' => $cancel ? '' : $their];
    }

    /** "Cancel the offer of your ticket for %s? It is yours again." */
    public static function cancelQuestion(string $night): string
    {
        /* translators: %s: the night, like "Sat 14 Nov · Dance Night" */
        return sprintf(__('Cancel the offer of your ticket for %s? It is yours again.', 'eventcrew'), $night);
    }

    /** /giveaway cancel: "Cancel the offer …?" with ✅ / ✖️. */
    private function askCancel(Person $giver, int $chatId, int $fromId, int $redemptionId): void
    {
        $ticket = $this->transfers->redemption($redemptionId);

        if (null === $ticket || $ticket->personId !== $giver->id) {
            $this->flow->telegram()->sendMessage($chatId, __('No offer of your tickets is waiting.', 'eventcrew'));

            return;
        }

        $id = $this->flow->start(['user' => $fromId, 'op' => self::GIVE_AWAY, 'rid' => $ticket->id]);
        $this->flow->confirm(
            $chatId,
            $id,
            self::cancelQuestion($this->transfers->nightLabel((string) $ticket->redeemedFor)),
            __('Cancel offer', 'eventcrew')
        );
    }

    /**
     * The person's own credit tickets that can be given now (or, with
     * $offered, that have an offer waiting), on $date when given.
     *
     * @return list<Redemption>
     */
    public function tickets(Person $giver, string $date, bool $offered): array
    {
        $wanted = $offered ? TicketTransferService::PENDING : '';

        return array_values(array_filter(
            $this->redemptions->forPerson($giver->id),
            fn (Redemption $r): bool => ('' === $date || $r->redeemedFor === $date)
                && $wanted === $this->transfers->refusal($r, $giver->id)
        ));
    }

    /** $date, or the next night with tasks when none was given. */
    private function nextNightOr(string $date): string
    {
        return '' !== $date ? $date : (string) ($this->tasks->upcomingDates(1)[0] ?? '');
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private static function asked(array $state, string $text, string $button): array
    {
        return ['ok' => true, 'text' => $text, 'button' => $button, 'state' => $state];
    }

    /**
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private static function refused(string $text): array
    {
        return ['ok' => false, 'text' => $text, 'button' => '', 'state' => []];
    }
}
