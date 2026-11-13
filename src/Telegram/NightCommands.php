<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Admin\RosterPage;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Newsletter;
use EventCrew\Support\TicketMail;
use EventCrew\Web\CrewAdminController;
use EventCrew\Web\CrewAdminNewsletter;
use EventCrew\Web\CrewAdminRoster;

/**
 * Organizer commands about a night or a send, in a private chat:
 *
 * - /freeentry [date] [close|open]: close or reopen free entry (FreeEntryGate);
 * - /stop announcement, /stop email [date]: stop the next announcement, or a
 *   night's post-event email;
 * - /newboard: post a new board in the group;
 * - /sendtickets [date], /sendtickets cancel: send the night's ticket emails
 *   now, or cancel the waiting send (TicketMail).
 *
 * Without a date the next night with tasks is meant. Each runs through the
 * same service as its button on the crew page, after ✅; organizers only,
 * checked again at the tap.
 */
final class NightCommands
{
    public const PREFIX = 'ng:';
    public const STATE_PREFIX = 'eventcrew_tg_night_';

    public const FREE_ENTRY = 'freeentry';
    public const STOP = 'stop';
    public const NEW_BOARD = 'newboard';
    public const SEND_TICKETS = 'sendtickets';

    /** The commands this class answers. */
    public const COMMANDS = [self::FREE_ENTRY, self::STOP, self::NEW_BOARD, self::SEND_TICKETS];

    private const CLOSE = 'close';
    private const OPEN = 'open';
    private const ANNOUNCEMENT = 'announcement';
    private const EMAIL = 'email';
    private const CANCEL = 'cancel';
    private const SEND = 'send';
    private const POST = 'post';

    /** Words taken for each choice. */
    private const WORDS = [
        'close' => self::CLOSE,
        'closed' => self::CLOSE,
        'open' => self::OPEN,
        'reopen' => self::OPEN,
        'announcement' => self::ANNOUNCEMENT,
        'newsletter' => self::ANNOUNCEMENT,
        'email' => self::EMAIL,
        'followup' => self::EMAIL,
        'cancel' => self::CANCEL,
    ];

    private CommandFlow $flow;

    public function __construct(
        PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly FreeEntryGate $freeEntry,
        private readonly Newsletter $newsletter,
        private readonly FollowupMail $followup,
        private readonly BoardService $board,
        private readonly CrewAdminRoster $roster,
        private readonly TicketMail $ticketMail,
        TelegramClient $telegram
    ) {
        $this->flow = new CommandFlow($people, $telegram, self::PREFIX, self::STATE_PREFIX);
    }

    public function onCommand(int $chatId, int $fromId, string $text, bool $isPrivate, string $command): void
    {
        if (! $this->flow->inPrivate($chatId, $isPrivate, '/' . $command) || null === $this->flow->organizerOrSay($chatId, $fromId, '/' . $command)) {
            return;
        }

        $asked = $this->question($command, CommandFlow::args($text), $fromId);

        if (! $asked['ok']) {
            $this->flow->telegram()->sendMessage($chatId, $asked['text']);

            return;
        }

        $id = $this->flow->start($asked['state']);
        $this->flow->confirm($chatId, $id, $asked['text'], $asked['button']);
    }

    /**
     * The Crew app's helper box (AssistantWebChanges): the command with its
     * arguments, read as the bot reads them. The question to show with
     * Confirm and the state to run, or (ok false) why it cannot be done.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    public function prepareWeb(string $command, string $args): array
    {
        if (! in_array($command, self::COMMANDS, true)) {
            return ['ok' => false, 'text' => __('That can no longer be done.', 'eventcrew'), 'state' => []];
        }

        $asked = $this->question($command, $args, 0);

        return ['ok' => $asked['ok'], 'text' => $asked['text'], 'state' => $asked['state']];
    }

    /**
     * Confirm in the helper box: the state prepareWeb() gave, run as ✅ runs
     * it. "changed" only when the change went through.
     *
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string}
     */
    public function runWeb(array $state): array
    {
        $done = $this->change($state);

        return ['changed' => $done['done'], 'text' => $done['text']];
    }

    /**
     * The question before the command changes anything, with its ✅ label
     * and the state ✅ runs, or (ok false) the answer that ends it.
     *
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private function question(string $command, string $args, int $fromId): array
    {
        [$date, $args] = CommandFlow::takeDate($args);
        $words = preg_split('/\s+/u', mb_strtolower($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $word = self::WORDS[$words[0] ?? ''] ?? '';

        $known = '' === $word ? 0 : 1;

        // "/stop email 14.11.": the date after the word.
        if ('' === $date && isset($words[1])) {
            [$date] = CommandFlow::takeDate($words[1]);
            $known += '' === $date ? 0 : 1;
        }

        $state = ['user' => $fromId, 'op' => $command, 'date' => $date, 'what' => $word];
        $stray = count($words) > $known;

        return match ($command) {
            self::FREE_ENTRY => $this->askFreeEntry($state, $stray),
            self::STOP => $this->askStop($state),
            self::NEW_BOARD => $this->askBoard($state),
            self::SEND_TICKETS => $this->askTickets($state, $stray),
            default => self::refuse(''),
        };
    }

    /**
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private static function refuse(string $text): array
    {
        return ['ok' => false, 'text' => $text, 'button' => '', 'state' => []];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private static function ask(array $state, string $text, string $button): array
    {
        return ['ok' => true, 'text' => $text, 'button' => $button, 'state' => $state];
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

        if (null === $this->flow->organizerTap($tap)) {
            return;
        }

        $telegram->answerCallbackQuery($tap['callback']);

        if ('y' !== $tap['step']) {
            return;
        }

        $this->flow->drop($tap['id']);
        $telegram->sendMessage($tap['chat'], $this->change($tap['state'])['text']);
    }

    /**
     * ✅: the change through the same service as the crew page's button,
     * and the answer. "done" only when it went through.
     *
     * @param array<string, mixed> $state
     * @return array{done: bool, text: string}
     */
    private function change(array $state): array
    {
        $date = (string) ($state['date'] ?? '');

        switch ((string) ($state['what'] ?? '')) {
            case self::CLOSE:
                $this->freeEntry->close($date);

                return ['done' => true, 'text' => CommandFlow::result(true, Dates::dayLabel($date), CrewAdminRoster::noticeText('admin_door_closed_now'))];

            case self::OPEN:
                $this->freeEntry->open($date);

                return ['done' => true, 'text' => CommandFlow::result(true, Dates::dayLabel($date), CrewAdminRoster::noticeText('admin_door_reopened'))];

            case self::ANNOUNCEMENT:
                $answer = $this->newsletter->stop((int) ($state['plan'] ?? 0));
                $done = Newsletter::STOPPED === $answer;

                return ['done' => $done, 'text' => CommandFlow::result($done, '', CrewAdminNewsletter::noticeText('admin_newsletter_' . $answer))];

            case self::EMAIL:
                $answer = $this->followup->stop($date);
                $done = FollowupMail::STOPPED === $answer;

                return ['done' => $done, 'text' => CommandFlow::result($done, Dates::dayLabel($date), RosterPage::followupStopMessage($answer))];

            case self::POST:
                $ok = $this->flow->telegram()->isConfigured() && $this->board->hasHome();

                if ($ok) {
                    $this->board->repost();
                }

                return ['done' => $ok, 'text' => CommandFlow::result($ok, '', CrewAdminController::noticeText($ok ? 'admin_board_posted' : 'admin_board_no_group'))];

            case self::SEND:
                $this->roster->sendTicketsRest($date, TicketMail::KIND_BUYERS);
                $code = $this->roster->sendTicketsRest($date, TicketMail::KIND_GUESTS);
                $done = 'admin_change_failed' !== $code;

                return ['done' => $done, 'text' => CommandFlow::result($done, Dates::dayLabel($date), CrewAdminRoster::noticeText($code))];

            case self::CANCEL:
                $code = $this->roster->cancelTicketSend();
                $done = 'admin_tickets_cancelled' === $code;

                return ['done' => $done, 'text' => CommandFlow::result($done, '', CrewAdminRoster::noticeText($code))];
        }

        return ['done' => false, 'text' => CommandFlow::result(false, '', CrewAdminRoster::noticeText('admin_change_failed'))];
    }

    public static function usage(string $command): string
    {
        return match ($command) {
            self::FREE_ENTRY => __("Close or reopen free entry: /freeentry close or /freeentry open\nA date first picks another night: /freeentry 21.11. close", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::STOP => __("Stop the next announcement: /stop announcement\nStop the post-event email: /stop email (or /stop email 14.11.)", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::SEND_TICKETS => __("Send the night’s ticket emails now: /sendtickets (or /sendtickets 21.11.)\nCancel the waiting send: /sendtickets cancel", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private function askFreeEntry(array $state, bool $stray): array
    {
        if ($stray || ! in_array($state['what'], ['', self::CLOSE, self::OPEN], true)) {
            return self::refuse(self::usage(self::FREE_ENTRY));
        }

        $date = $this->nightOr((string) $state['date']);

        if ('' === $date) {
            return self::refuse(__('There are no upcoming tasks.', 'eventcrew'));
        }

        $day = Dates::dayLabel($date);
        $byHand = $this->freeEntry->isClosedByHand($date);
        $what = '' === $state['what'] ? ($byHand ? self::OPEN : self::CLOSE) : $state['what'];

        if (self::CLOSE === $what && $byHand) {
            return self::refuse($day . "\n" . CrewAdminRoster::noticeText('admin_door_closed'));
        }

        if (self::OPEN === $what && ! $byHand) {
            return self::refuse(
                $day . "\n" . ('' !== $this->freeEntry->eventReason($date)
                    ? __('Free entry is closed by the event itself (sold out, hidden or off). Change it in the event.', 'eventcrew')
                    : __('Free entry is open for this date.', 'eventcrew'))
            );
        }

        $state['date'] = $date;
        $state['what'] = $what;

        if (self::CLOSE === $what) {
            /* translators: %s: a short day label, like "Sat 14 Nov". */
            return self::ask($state, sprintf(__('Close free entry for %s? Nobody can use a credit for that night.', 'eventcrew'), $day), __('Close', 'eventcrew'));
        }

        /* translators: %s: a short day label, like "Sat 14 Nov". */
        return self::ask($state, sprintf(__('Reopen free entry for %s?', 'eventcrew'), $day), __('Reopen', 'eventcrew'));
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private function askStop(array $state): array
    {
        if (self::ANNOUNCEMENT === $state['what']) {
            $plan = $this->newsletter->status()['plan'];

            if (! is_array($plan)) {
                return self::refuse(__('No announcement is planned.', 'eventcrew'));
            }

            if (Newsletter::PLAN_SENDING === ($plan['status'] ?? '')) {
                return self::refuse(CrewAdminNewsletter::noticeText('admin_newsletter_too_late'));
            }

            $state['plan'] = (int) ($plan['id'] ?? 0);

            return self::ask(
                $state,
                /* translators: %s: a short day label, like "Sat 14 Nov". */
                sprintf(__('Stop the announcement planned for %s? It will not be sent.', 'eventcrew'), Dates::dayLabel((string) ($plan['send_on'] ?? ''))),
                __('Stop', 'eventcrew')
            );
        }

        if (self::EMAIL !== $state['what']) {
            return self::refuse(self::usage(self::STOP));
        }

        $date = '' !== $state['date'] ? (string) $state['date'] : $this->followup->nextNight();

        if ('' === $date) {
            return self::refuse(__('No post-event email is planned.', 'eventcrew'));
        }

        $day = Dates::dayLabel($date);

        if (! $this->followup->canStop($date)) {
            $status = $this->followup->nightStatus($date);

            return self::refuse($day . "\n" . match ($status) {
                'stopped' => RosterPage::followupStopMessage(FollowupMail::ALREADY),
                'sending', 'sent' => RosterPage::followupStopMessage(FollowupMail::TOO_LATE),
                default => __('No post-event email is planned.', 'eventcrew'),
            });
        }

        $state['date'] = $date;

        return self::ask(
            $state,
            /* translators: %s: a short day label, like "Sat 14 Nov". */
            sprintf(__('Stop the post-event email for %s? It will not be sent.', 'eventcrew'), $day),
            __('Stop', 'eventcrew')
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private function askBoard(array $state): array
    {
        if (! $this->flow->telegram()->isConfigured() || ! $this->board->hasHome()) {
            return self::refuse(CrewAdminController::noticeText('admin_board_no_group'));
        }

        $state['what'] = self::POST;

        return self::ask($state, __('Post a new board in the group? The old one is removed.', 'eventcrew'), __('Post', 'eventcrew'));
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, button: string, state: array<string, mixed>}
     */
    private function askTickets(array $state, bool $stray): array
    {
        if ($stray || ! in_array($state['what'], ['', self::CANCEL], true)) {
            return self::refuse(self::usage(self::SEND_TICKETS));
        }

        if (self::CANCEL === $state['what']) {
            $summary = $this->ticketMail->pendingSummary();

            if (null === $summary) {
                return self::refuse(RosterPage::ticketsCancelMessage(false));
            }

            return self::ask($state, TicketMail::pendingLine($summary) . "\n" . __('Cancel this send?', 'eventcrew'), __('Cancel send', 'eventcrew'));
        }

        $date = $this->nightOr((string) $state['date']);

        if ('' === $date) {
            return self::refuse(__('There are no upcoming tasks.', 'eventcrew'));
        }

        $state['date'] = $date;
        $state['what'] = self::SEND;

        return self::ask(
            $state,
            sprintf(
                /* translators: %s: a short day label, like "Sat 14 Nov". */
                __('Send the ticket emails for %s that have not gone out? They go out in about 10 minutes; until then you can cancel them.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                Dates::dayLabel($date)
            ),
            __('Send', 'eventcrew')
        );
    }

    /**
     * $date, or the next night with tasks when none was given.
     */
    private function nightOr(string $date): string
    {
        return '' !== $date ? $date : (string) ($this->tasks->upcomingDates(1)[0] ?? '');
    }
}
