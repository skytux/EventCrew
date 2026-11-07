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

        [$date, $args] = CommandFlow::takeDate(CommandFlow::args($text));
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

        match ($command) {
            self::FREE_ENTRY => $this->askFreeEntry($chatId, $state, $stray),
            self::STOP => $this->askStop($chatId, $state),
            self::NEW_BOARD => $this->askBoard($chatId, $state),
            self::SEND_TICKETS => $this->askTickets($chatId, $state, $stray),
            default => null,
        };
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
        $state = $tap['state'];
        $date = (string) ($state['date'] ?? '');
        $chatId = $tap['chat'];

        switch ((string) ($state['what'] ?? '')) {
            case self::CLOSE:
                $this->freeEntry->close($date);
                $telegram->sendMessage($chatId, CommandFlow::result(true, Dates::dayLabel($date), CrewAdminRoster::noticeText('admin_door_closed_now')));

                return;

            case self::OPEN:
                $this->freeEntry->open($date);
                $telegram->sendMessage($chatId, CommandFlow::result(true, Dates::dayLabel($date), CrewAdminRoster::noticeText('admin_door_reopened')));

                return;

            case self::ANNOUNCEMENT:
                $answer = $this->newsletter->stop((int) ($state['plan'] ?? 0));
                $telegram->sendMessage($chatId, CommandFlow::result(
                    Newsletter::STOPPED === $answer,
                    '',
                    CrewAdminNewsletter::noticeText('admin_newsletter_' . $answer)
                ));

                return;

            case self::EMAIL:
                $answer = $this->followup->stop($date);
                $telegram->sendMessage($chatId, CommandFlow::result(
                    FollowupMail::STOPPED === $answer,
                    Dates::dayLabel($date),
                    RosterPage::followupStopMessage($answer)
                ));

                return;

            case self::POST:
                $ok = $telegram->isConfigured() && $this->board->hasHome();

                if ($ok) {
                    $this->board->repost();
                }

                $telegram->sendMessage($chatId, CommandFlow::result(
                    $ok,
                    '',
                    CrewAdminController::noticeText($ok ? 'admin_board_posted' : 'admin_board_no_group')
                ));

                return;

            case self::SEND:
                $this->roster->sendTicketsRest($date, TicketMail::KIND_BUYERS);
                $code = $this->roster->sendTicketsRest($date, TicketMail::KIND_GUESTS);
                $telegram->sendMessage($chatId, CommandFlow::result(
                    'admin_change_failed' !== $code,
                    Dates::dayLabel($date),
                    CrewAdminRoster::noticeText($code)
                ));

                return;

            case self::CANCEL:
                $code = $this->roster->cancelTicketSend();
                $telegram->sendMessage($chatId, CommandFlow::result(
                    'admin_tickets_cancelled' === $code,
                    '',
                    CrewAdminRoster::noticeText($code)
                ));

                return;
        }
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
     */
    private function askFreeEntry(int $chatId, array $state, bool $stray): void
    {
        $telegram = $this->flow->telegram();

        if ($stray || ! in_array($state['what'], ['', self::CLOSE, self::OPEN], true)) {
            $telegram->sendMessage($chatId, self::usage(self::FREE_ENTRY));

            return;
        }

        $date = $this->nightOr((string) $state['date']);

        if ('' === $date) {
            $telegram->sendMessage($chatId, __('There are no upcoming tasks.', 'eventcrew'));

            return;
        }

        $day = Dates::dayLabel($date);
        $byHand = $this->freeEntry->isClosedByHand($date);
        $what = '' === $state['what'] ? ($byHand ? self::OPEN : self::CLOSE) : $state['what'];

        if (self::CLOSE === $what && $byHand) {
            $telegram->sendMessage($chatId, $day . "\n" . CrewAdminRoster::noticeText('admin_door_closed'));

            return;
        }

        if (self::OPEN === $what && ! $byHand) {
            $telegram->sendMessage(
                $chatId,
                $day . "\n" . ('' !== $this->freeEntry->eventReason($date)
                    ? __('Free entry is closed by the event itself (sold out, hidden or off). Change it in the event.', 'eventcrew')
                    : __('Free entry is open for this date.', 'eventcrew'))
            );

            return;
        }

        $state['date'] = $date;
        $state['what'] = $what;
        $id = $this->flow->start($state);

        if (self::CLOSE === $what) {
            /* translators: %s: a short day label, like "Sat 14 Nov". */
            $this->flow->confirm($chatId, $id, sprintf(__('Close free entry for %s? Nobody can use a credit for that night.', 'eventcrew'), $day), __('Close', 'eventcrew'));

            return;
        }

        /* translators: %s: a short day label, like "Sat 14 Nov". */
        $this->flow->confirm($chatId, $id, sprintf(__('Reopen free entry for %s?', 'eventcrew'), $day), __('Reopen', 'eventcrew'));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function askStop(int $chatId, array $state): void
    {
        $telegram = $this->flow->telegram();

        if (self::ANNOUNCEMENT === $state['what']) {
            $plan = $this->newsletter->status()['plan'];

            if (! is_array($plan)) {
                $telegram->sendMessage($chatId, __('No announcement is planned.', 'eventcrew'));

                return;
            }

            if (Newsletter::PLAN_SENDING === ($plan['status'] ?? '')) {
                $telegram->sendMessage($chatId, CrewAdminNewsletter::noticeText('admin_newsletter_too_late'));

                return;
            }

            $state['plan'] = (int) ($plan['id'] ?? 0);
            $id = $this->flow->start($state);
            $this->flow->confirm(
                $chatId,
                $id,
                /* translators: %s: a short day label, like "Sat 14 Nov". */
                sprintf(__('Stop the announcement planned for %s? It will not be sent.', 'eventcrew'), Dates::dayLabel((string) ($plan['send_on'] ?? ''))),
                __('Stop', 'eventcrew')
            );

            return;
        }

        if (self::EMAIL !== $state['what']) {
            $telegram->sendMessage($chatId, self::usage(self::STOP));

            return;
        }

        $date = '' !== $state['date'] ? (string) $state['date'] : $this->followup->nextNight();

        if ('' === $date) {
            $telegram->sendMessage($chatId, __('No post-event email is planned.', 'eventcrew'));

            return;
        }

        $day = Dates::dayLabel($date);

        if (! $this->followup->canStop($date)) {
            $status = $this->followup->nightStatus($date);
            $telegram->sendMessage($chatId, $day . "\n" . match ($status) {
                'stopped' => RosterPage::followupStopMessage(FollowupMail::ALREADY),
                'sending', 'sent' => RosterPage::followupStopMessage(FollowupMail::TOO_LATE),
                default => __('No post-event email is planned.', 'eventcrew'),
            });

            return;
        }

        $state['date'] = $date;
        $id = $this->flow->start($state);
        $this->flow->confirm(
            $chatId,
            $id,
            /* translators: %s: a short day label, like "Sat 14 Nov". */
            sprintf(__('Stop the post-event email for %s? It will not be sent.', 'eventcrew'), $day),
            __('Stop', 'eventcrew')
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function askBoard(int $chatId, array $state): void
    {
        if (! $this->flow->telegram()->isConfigured() || ! $this->board->hasHome()) {
            $this->flow->telegram()->sendMessage($chatId, CrewAdminController::noticeText('admin_board_no_group'));

            return;
        }

        $state['what'] = self::POST;
        $id = $this->flow->start($state);
        $this->flow->confirm(
            $chatId,
            $id,
            __('Post a new board in the group? The old one is removed.', 'eventcrew'),
            __('Post', 'eventcrew')
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function askTickets(int $chatId, array $state, bool $stray): void
    {
        $telegram = $this->flow->telegram();

        if ($stray || ! in_array($state['what'], ['', self::CANCEL], true)) {
            $telegram->sendMessage($chatId, self::usage(self::SEND_TICKETS));

            return;
        }

        if (self::CANCEL === $state['what']) {
            $summary = $this->ticketMail->pendingSummary();

            if (null === $summary) {
                $telegram->sendMessage($chatId, RosterPage::ticketsCancelMessage(false));

                return;
            }

            $id = $this->flow->start($state);
            $this->flow->confirm(
                $chatId,
                $id,
                TicketMail::pendingLine($summary) . "\n" . __('Cancel this send?', 'eventcrew'),
                __('Cancel send', 'eventcrew')
            );

            return;
        }

        $date = $this->nightOr((string) $state['date']);

        if ('' === $date) {
            $telegram->sendMessage($chatId, __('There are no upcoming tasks.', 'eventcrew'));

            return;
        }

        $state['date'] = $date;
        $state['what'] = self::SEND;
        $id = $this->flow->start($state);
        $this->flow->confirm(
            $chatId,
            $id,
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
