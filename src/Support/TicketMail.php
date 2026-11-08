<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\CrewTicket;
use EventCrew\Models\Guest;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Models\TicketBuyer;
use EventCrew\Models\TicketTransfer;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Repositories\TicketTransferRepository;
use EventCrew\Web\SignupBoardView;

/**
 * The ticket email: after an import, every imported ticket buyer with an email
 * address and a ticket code, and every guest from a mailed guest list with an
 * email address, is sent one email. It thanks them and carries the ticket: the
 * QR code the door scans, the code written out, and a link to the ticket page.
 *
 * The email is in the languages HybridPolicy picks, main language first, in
 * one message. Each language's block is composed inside Language::run(), so
 * the plugin's own strings come from that language's catalogue.
 *
 * The QR code is drawn on the server (TicketQr) as HTML tables of black and
 * white cells. It is not an image: Gmail does not show images written into
 * the HTML as data, the host answers image fetchers such as Gmail's image
 * proxy with a script challenge instead of the image, and an inline (cid:)
 * attachment breaks a mail plugin that reads every attachment from a file
 * (FluentSMTP's API providers). Only the site's icon in the middle of the
 * code is an ordinary linked image; when it does not load, its square stays
 * white and the code still scans. The email has no clock: the live clock is on
 * the ticket page the link opens, for a desk that checks tickets by eye.
 *
 * Sending is a WP-Cron event, scheduled by every import that adds rows. Each
 * run sends up to BATCH emails and schedules itself again while rows remain.
 * Each row's ticket_mail_status records what happened: '' (not tried), 'sent'
 * or 'failed'. The automatic run only tries '' rows, so a failed address is
 * not retried by itself; "Send the rest now" tries every row not 'sent'.
 *
 * Every send to ticket holders waits DELAY seconds first, whether an import
 * or "Send the rest now" started it, so an organizer can cancel it after a
 * mistake. The waiting send is one option (PENDING_OPTION) and one single
 * START_HOOK event. While it waits, a second start joins it instead of
 * starting another, and the batch runner sends nothing. cancel() deletes both;
 * no row's status changes, so a later send still reaches everyone.
 *
 * Crew get the same email, sent at once rather than by cron: a sign-up for a
 * task (sendCrewTicket()) and a free-entry credit spent on a night
 * (sendCreditTicket()). Its QR holds a short signed code of its own (crewCode(),
 * creditCode()), which the door desk checks the person in by. A buyer added at
 * the sales desk with an email address gets a code (deskBuyerCode()) and the
 * cron email like an imported buyer.
 *
 * When a ticket that was emailed stops being valid - the guest is removed, the
 * sign-up or the credit is cancelled - sendCancelled() tells the holder, so
 * they do not come to the door with a QR code that no longer works.
 */
final class TicketMail
{
    /** The single cron event that sends one batch. */
    public const HOOK = 'eventcrew_send_tickets';

    /** The single cron event that starts a waiting send when its time comes. */
    public const START_HOOK = 'eventcrew_send_tickets_start';

    /**
     * The waiting send: ['at' => unix time, 'rest' => list of "Y-m-d|kind"
     * that "Send the rest now" asked for]. Absent when nothing waits.
     */
    public const PENDING_OPTION = 'eventcrew_ticket_send_pending';

    /** The "Send the rest now" requests of a send that has started. */
    public const REST_OPTION = 'eventcrew_ticket_send_rest';

    /** How long a send waits before anything goes out: 10 minutes. */
    public const DELAY = 600;

    /** Emails per cron run or per "Send the rest now". */
    public const BATCH = 25;

    /**
     * How late a send's cron event may be before a page load runs it
     * (rescueStuckSend()): 5 minutes.
     */
    public const RESCUE_MARGIN = 300;

    /**
     * MySQL lock name that keeps two runs of a send apart: two page loads
     * rescuing a stuck send, or a page load and the hosting's cron (Q264).
     */
    private const RESCUE_LOCK = 'eventcrew_ticket_send_rescue';

    /**
     * When the cron finds the lock held, it schedules its event again this
     * many seconds later, so the send is not lost: 1 minute.
     */
    public const CRON_RETRY = 60;

    public const KIND_BUYERS = 'buyers';
    public const KIND_GUESTS = 'guests';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** sendTest()'s answers. */
    public const TEST_SENT = 'sent';
    public const TEST_FAILED = 'failed';
    public const TEST_NONE = 'none';

    /** The REST route of the ticket page (Telegram\TicketController). */
    private const PAGE_ROUTE = 'eventcrew/v1/ticket';

    /**
     * The ticket page's font (system-ui) with fallbacks for the mail apps
     * that do not know it, written as the start of a style attribute.
     */
    private const PAGE_FONT = "font-family:system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";

    /**
     * The widest the ticket email's QR code is drawn, quiet zone included,
     * in pixels: small, in the bottom-right corner. The email links to the
     * ticket page, which shows the big QR code; this one is for a phone with
     * no internet at the door.
     */
    private const QR_WIDTH = 150;

    /** The ticket email's heading font, with fallbacks. */
    private const HEAD_FONT = "'Platypi',Georgia,serif";

    /** The ticket email's colours: text, muted text, gold, rule and note box. */
    private const TEXT_COLOR = '#2a2233';
    private const MUTED_COLOR = '#6b5f73';
    private const GOLD_COLOR = '#a8641f';
    private const RULE_COLOR = '#ead9bf';
    private const NOTE_COLOR = '#f3eadb';

    public function __construct(
        private readonly TicketBuyerRepository $buyers,
        private readonly GuestRepository $guests,
        private readonly TaskRepository $tasks,
        private readonly Mailer $mailer,
        private readonly TicketQr $qr = new TicketQr(),
        private readonly ?AssignmentRepository $assignments = null,
        private readonly ?RedemptionRepository $redemptions = null,
        private readonly ?TicketTransferRepository $transfers = null
    ) {
    }

    public function boot(): void
    {
        add_action(self::HOOK, [$this, 'runFromCron']);
        add_action(self::START_HOOK, [$this, 'startFromCron']);
        // Late on init, as the cron fallback (CronFallbackTrigger) runs.
        add_action('init', [$this, 'rescueStuckSend'], 99);
    }

    /**
     * HOOK as the cron runs it: run() under the same lock as
     * rescueStuckSend(), so a page load and the cron never send the same
     * batch at the same time (Q264). Returns how many emails were tried.
     */
    public function runFromCron(): int
    {
        return $this->underCronLock(self::HOOK, fn (): int => $this->run());
    }

    /** START_HOOK as the cron runs it: start() under the same lock. */
    public function startFromCron(): int
    {
        return $this->underCronLock(self::START_HOOK, fn (): int => $this->start());
    }

    /**
     * Runs $work while holding RESCUE_LOCK. When the lock is held, a page
     * load is running the send right now: nothing runs here, and $hook is
     * scheduled again CRON_RETRY seconds later, so whatever that run leaves
     * is still sent. The lock is released even when $work throws.
     *
     * @param callable(): int $work
     */
    private function underCronLock(string $hook, callable $work): int
    {
        global $wpdb;

        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::RESCUE_LOCK))) {
            if (false === wp_next_scheduled($hook)) {
                wp_schedule_single_event(time() + self::CRON_RETRY, $hook);
            }

            return 0;
        }

        try {
            return $work();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::RESCUE_LOCK));
        }
    }

    /**
     * A page load after a send's planned time: when the hosting's cron missed
     * the send's event (the waiting send's START_HOOK, or the next batch's
     * HOOK) by more than RESCUE_MARGIN, the event is taken off the cron list
     * and run here. Under a MySQL lock, so two page loads at once run it only
     * once: the waiting send is gone and the sent rows are marked by the
     * time the second one gets the lock, so it sends nothing twice. Returns
     * how many emails were tried.
     *
     * Each page load only reads the cron list, which WordPress has in memory
     * already; nothing more is read unless an event is late.
     */
    public function rescueStuckSend(): int
    {
        if (! function_exists('wp_next_scheduled')) {
            return 0;
        }

        $late = time() - self::RESCUE_MARGIN;
        $start = wp_next_scheduled(self::START_HOOK);
        $batch = wp_next_scheduled(self::HOOK);
        $startLate = false !== $start && (int) $start < $late;
        $batchLate = false !== $batch && (int) $batch < $late;

        if (! $startLate && ! $batchLate) {
            return 0;
        }

        global $wpdb;

        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::RESCUE_LOCK))) {
            return 0;
        }

        try {
            // Read again under the lock: another page load may have run it
            // between the first look and the lock.
            if ($startLate && false !== wp_next_scheduled(self::START_HOOK)) {
                wp_clear_scheduled_hook(self::START_HOOK);

                return $this->start();
            }

            if ($batchLate && false !== wp_next_scheduled(self::HOOK)) {
                wp_clear_scheduled_hook(self::HOOK);

                return $this->run();
            }

            return 0;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::RESCUE_LOCK));
        }
    }

    /**
     * Starts a send of the tickets not tried yet, DELAY seconds from now,
     * unless a send is already waiting (it will send these too). Called after
     * an import adds rows.
     */
    public static function schedule(): void
    {
        self::request('');
    }

    /**
     * "Send the rest now" for $date and $kind: the same waiting send, which
     * then also tries that night's failed tickets of $kind. Returns when the
     * send goes out, and whether this call started it (false: it joined the
     * send already waiting, which keeps its time).
     *
     * @return array{at: int, started: bool}
     */
    public static function scheduleRest(string $date, string $kind): array
    {
        return self::request($date . '|' . (self::KIND_GUESTS === $kind ? self::KIND_GUESTS : self::KIND_BUYERS));
    }

    /**
     * The waiting send, or null when nothing waits.
     *
     * @return array{at: int, rest: list<string>}|null
     */
    public static function pending(): ?array
    {
        $value = function_exists('get_option') ? get_option(self::PENDING_OPTION, null) : null;

        if (! is_array($value) || ! isset($value['at']) || (int) $value['at'] <= 0) {
            return null;
        }

        return [
            'at' => (int) $value['at'],
            'rest' => array_values(array_filter(
                is_array($value['rest'] ?? null) ? $value['rest'] : [],
                'is_string'
            )),
        ];
    }

    /**
     * Cancels the waiting send: nothing of it goes out and no ticket's status
     * changes. Returns false when nothing was waiting.
     */
    public static function cancel(): bool
    {
        $pending = self::pending();

        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::START_HOOK);
        }

        if (function_exists('delete_option')) {
            delete_option(self::PENDING_OPTION);
        }

        return null !== $pending;
    }

    /**
     * Starts or joins the waiting send; $rest is a "Y-m-d|kind" to retry, or
     * '' for the automatic send only.
     *
     * @return array{at: int, started: bool}
     */
    private static function request(string $rest): array
    {
        $pending = self::pending();

        if (null !== $pending) {
            if ('' !== $rest && ! in_array($rest, $pending['rest'], true)) {
                $pending['rest'][] = $rest;
                update_option(self::PENDING_OPTION, $pending, false);
            }

            return ['at' => $pending['at'], 'started' => false];
        }

        $at = time() + self::DELAY;

        if (! function_exists('wp_schedule_single_event') || ! function_exists('update_option')) {
            return ['at' => $at, 'started' => false];
        }

        update_option(self::PENDING_OPTION, ['at' => $at, 'rest' => '' === $rest ? [] : [$rest]], false);
        wp_schedule_single_event($at, self::START_HOOK);

        return ['at' => $at, 'started' => true];
    }

    /**
     * START_HOOK: the waiting send's time has come. Its "Send the rest now"
     * requests are handed to the batch runner, and the first batch goes out.
     * Does nothing when the send was cancelled.
     */
    public function start(): int
    {
        $pending = self::pending();

        if (null === $pending) {
            return 0;
        }

        delete_option(self::PENDING_OPTION);

        if ([] !== $pending['rest']) {
            $rest = array_values(array_unique(array_merge(self::restRequests(), $pending['rest'])));
            update_option(self::REST_OPTION, $rest, false);
        }

        return $this->run();
    }

    /**
     * The batch runner (HOOK): sends up to BATCH tickets not tried yet, for
     * tonight and later nights, buyers before guests. When those are done, it
     * works through the started send's "Send the rest now" requests, one
     * batch at a time. It schedules the next batch while work remains. Sends
     * nothing while a send is waiting: that send's start runs it. Returns how
     * many emails were tried.
     */
    public function run(): int
    {
        if (null !== self::pending()) {
            return 0;
        }

        $today = (string) current_time('Y-m-d');
        $rows = $this->buyers->unsentFrom($today, self::BATCH);

        if (count($rows) < self::BATCH) {
            $rows = array_merge($rows, $this->guests->unsentFrom($today, self::BATCH - count($rows)));
        }

        foreach ($rows as $row) {
            $this->sendRow($row);
        }

        if (count($rows) >= self::BATCH) {
            self::nextBatch();

            return count($rows);
        }

        $rest = self::restRequests();

        if ([] === $rest) {
            return count($rows);
        }

        [$date, $kind] = array_pad(explode('|', $rest[0], 2), 2, '');
        $result = $this->sendRest($date, $kind);
        // Keep at this night while a batch still sends something, or rows
        // never tried are left; failed rows that fail again end it.
        $again = $result['more']
            && ($result['sent'] > 0 || $this->counts($date, $kind)['unsent'] > 0);

        if (! $again) {
            array_shift($rest);
        }

        if ([] === $rest) {
            delete_option(self::REST_OPTION);
        } else {
            update_option(self::REST_OPTION, $rest, false);
            self::nextBatch();
        }

        return count($rows) + $result['sent'] + $result['failed'];
    }

    /** The next batch of a send that has started: at once, no wait. */
    private static function nextBatch(): void
    {
        if (function_exists('wp_next_scheduled') && false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time(), self::HOOK);
        }
    }

    /** @return list<string> */
    private static function restRequests(): array
    {
        $value = get_option(self::REST_OPTION, []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Who the waiting send will email: the tickets not tried yet from today
     * on, plus the failed ones of each "Send the rest now" night. Null when
     * nothing waits.
     *
     * @return array{at: int, buyers: int, guests: int}|null
     */
    public function pendingSummary(): ?array
    {
        $pending = self::pending();

        if (null === $pending) {
            return null;
        }

        $today = (string) current_time('Y-m-d');
        $counts = [
            self::KIND_BUYERS => count($this->buyers->unsentFrom($today, 10000)),
            self::KIND_GUESTS => count($this->guests->unsentFrom($today, 10000)),
        ];

        foreach ($pending['rest'] as $entry) {
            [$date, $kind] = array_pad(explode('|', $entry, 2), 2, '');
            $kind = self::KIND_GUESTS === $kind ? self::KIND_GUESTS : self::KIND_BUYERS;
            $night = $this->counts($date, $kind);
            // A past night's untried rows are not in the count from today.
            $counts[$kind] += $night['failed'] + ($date < $today ? $night['unsent'] : 0);
        }

        return ['at' => $pending['at'], 'buyers' => $counts[self::KIND_BUYERS], 'guests' => $counts[self::KIND_GUESTS]];
    }

    /** "21:40": a unix time as the site's clock shows it. */
    public static function clock(int $at): string
    {
        return function_exists('wp_date') ? (string) wp_date('H:i', $at) : gmdate('H:i', $at);
    }

    /**
     * "Ticket emails go out at 21:40 to 12 buyers, 2 guests." for a
     * pendingSummary().
     *
     * @param array{at: int, buyers: int, guests: int} $summary
     */
    public static function pendingLine(array $summary): string
    {
        /* translators: %d: number of ticket buyers */
        $who = sprintf(_n('%d buyer', '%d buyers', $summary['buyers'], 'eventcrew'), $summary['buyers']);

        if ($summary['guests'] > 0) {
            /* translators: %d: number of guests */
            $who .= ', ' . sprintf(_n('%d guest', '%d guests', $summary['guests'], 'eventcrew'), $summary['guests']);
        }

        return sprintf(
            /* translators: 1: time of day, e.g. 21:40, 2: who gets them, e.g. "12 buyers, 2 guests" */
            __('Ticket emails go out at %1$s to %2$s.', 'eventcrew'),
            self::clock($summary['at']),
            $who
        );
    }

    /**
     * "Send the rest now" for $date: sends up to BATCH of the night's tickets
     * whose email has not gone out, the failed ones included. $kind is
     * KIND_BUYERS or KIND_GUESTS.
     *
     * @return array{sent: int, failed: int, more: bool}
     */
    public function sendRest(string $date, string $kind): array
    {
        $rows = self::KIND_GUESTS === $kind
            ? $this->guests->notSentOn($date, self::BATCH)
            : $this->buyers->notSentOn($date, self::BATCH);
        $result = ['sent' => 0, 'failed' => 0, 'more' => false];

        foreach ($rows as $row) {
            $result[$this->sendRow($row) ? 'sent' : 'failed']++;
        }

        // More is left when rows were never tried, or when this batch was full
        // and failed rows other than the ones it just tried remain.
        $counts = $this->counts($date, $kind);
        $result['more'] = $counts['unsent'] > 0
            || (count($rows) >= self::BATCH && $counts['failed'] > $result['failed']);

        return $result;
    }

    /**
     * Sends one sample ticket email to $to, built from the first ticket buyer
     * on $date that can be emailed (or, with none, the first guest with an
     * email address). No row's status changes. Returns TEST_SENT, TEST_FAILED,
     * or TEST_NONE when the night has nobody to build it from.
     */
    public function sendTest(string $date, string $to): string
    {
        $row = $this->buyers->firstMailableOn($date) ?? $this->guests->firstMailableOn($date);

        if (null === $row || ! is_email($to)) {
            return self::TEST_NONE;
        }

        return $this->deliver($row, $to) ? self::TEST_SENT : self::TEST_FAILED;
    }

    /**
     * How many of $date's tickets were sent, failed, and are not sent yet.
     *
     * @return array{sent: int, failed: int, unsent: int}
     */
    public function counts(string $date, string $kind): array
    {
        return self::KIND_GUESTS === $kind
            ? $this->guests->mailCounts($date)
            : $this->buyers->mailCounts($date);
    }

    /**
     * "38 sent · 2 failed · 2 not sent", or '' when there is nothing to email.
     *
     * @param array{sent: int, failed: int, unsent: int} $counts
     */
    public static function statusLine(array $counts): string
    {
        if (0 === $counts['sent'] + $counts['failed'] + $counts['unsent']) {
            return '';
        }

        return sprintf(
            /* translators: 1: tickets emailed, 2: tickets whose email failed, 3: tickets not emailed yet */
            __('Ticket emails: %1$d sent · %2$d failed · %3$d not sent', 'eventcrew'),
            $counts['sent'],
            $counts['failed'],
            $counts['unsent']
        );
    }

    /**
     * The token of a ticket buyer's ticket page. The ticket code is part of
     * what is signed, so the link stops working once the code is deleted.
     */
    public static function buyerToken(TicketBuyer $buyer): string
    {
        return SignedLink::sign('buyer_ticket|' . $buyer->ticketCode, $buyer->id);
    }

    /**
     * The token of a guest's ticket page. $rev is the guest's ticketRev:
     * after an organizer changes the guest's email address the token carries
     * the new rev, and the desk and the ticket page refuse older ones. Rev 0
     * is the token every guest got before ticketRev existed.
     */
    public static function guestToken(int $guestId, int $rev = 0): string
    {
        return $rev > 0
            ? SignedLink::signPayload('guest_ticket_rev', $guestId . '|' . $rev)
            : SignedLink::sign('guest_ticket', $guestId);
    }

    /**
     * The guest id and ticketRev a guestToken() carries, or null when it is
     * not one or its signature does not verify. Nothing is read from the
     * database: the caller compares the rev with the guest's.
     *
     * @return array{id: int, rev: int}|null
     */
    public static function guestTokenParts(string $token): ?array
    {
        $id = SignedLink::verify('guest_ticket', $token);

        if (null !== $id) {
            return ['id' => $id, 'rev' => 0];
        }

        $payload = SignedLink::verifyPayload('guest_ticket_rev', $token);

        if (null === $payload || 1 !== preg_match('/^([1-9]\d{0,18})\|([1-9]\d{0,4})$/', $payload, $match)) {
            return null;
        }

        return ['id' => (int) $match[1], 'rev' => (int) $match[2]];
    }

    public static function buyerUrl(TicketBuyer $buyer): string
    {
        return add_query_arg(['token' => self::buyerToken($buyer)], rest_url(self::PAGE_ROUTE));
    }

    public static function guestUrl(int $guestId, int $rev = 0): string
    {
        return add_query_arg(['token' => self::guestToken($guestId, $rev)], rest_url(self::PAGE_ROUTE));
    }

    /**
     * What the QR code holds: a buyer's ticket code, which the desk looks up
     * as it does the code in a Holvi ticket; a guest's short signed code
     * (guestCode()), which the desk verifies. The guest's code is short so
     * that its QR, drawn as table cells, keeps the email small.
     */
    public static function qrData(TicketBuyer|Guest|CrewTicket $row): string
    {
        if ($row instanceof CrewTicket) {
            if ($row->isTransfer()) {
                return self::transferCode($row->id);
            }

            return $row->isCredit() ? self::creditCode($row->id) : self::crewCode($row->id);
        }

        return $row instanceof Guest ? self::guestCode($row->id, $row->ticketRev) : $row->ticketCode;
    }

    /**
     * "EC-G12-0123456789abcdef": a guest's id and a short signature of it.
     * $rev is the guest's ticketRev: after an organizer changes the guest's
     * email address the code is "EC-G12r1-...", signed with the rev, and the
     * desk refuses the older code.
     */
    public static function guestCode(int $guestId, int $rev = 0): string
    {
        if ($rev <= 0) {
            return self::shortCode('G', 'guest_ticket', $guestId);
        }

        return 'EC-G' . $guestId . 'r' . $rev . '-' . SignedLink::shortSignature('guest_ticket|' . $rev, $guestId);
    }

    /**
     * The guest id in a guestCode() of any rev, or null when $text is not
     * one or its signature does not verify.
     */
    public static function guestIdFromCode(string $text): ?int
    {
        return self::guestCodeParts($text)['id'] ?? null;
    }

    /**
     * The guest id and ticketRev in a guestCode(), or null when $text is not
     * one or its signature does not verify. Nothing is read from the
     * database: the caller compares the rev with the guest's.
     *
     * @return array{id: int, rev: int}|null
     */
    public static function guestCodeParts(string $text): ?array
    {
        if (1 !== preg_match('/^EC-G([1-9]\d{0,18})(?:r([1-9]\d{0,4}))?-([0-9a-f]{16})$/', trim($text), $match)) {
            return null;
        }

        $id = (int) $match[1];
        $rev = '' === $match[2] ? 0 : (int) $match[2];
        $signature = SignedLink::shortSignature($rev > 0 ? 'guest_ticket|' . $rev : 'guest_ticket', $id);

        return hash_equals($signature, $match[3]) ? ['id' => $id, 'rev' => $rev] : null;
    }

    /**
     * "EC-C12-0123456789abcdef": the code in a crew sign-up's ticket email, an
     * assignment id signed with the same purpose as the sign-up's ticket link.
     */
    public static function crewCode(int $assignmentId): string
    {
        return self::shortCode('C', 'ticket', $assignmentId);
    }

    /** The assignment id in a crewCode(), or null. */
    public static function crewIdFromCode(string $text): ?int
    {
        return self::idFromShortCode('C', 'ticket', $text);
    }

    /**
     * "EC-F12-0123456789abcdef": the code in a free-entry credit's ticket
     * email, a redemption id signed like the credit's ticket link.
     */
    public static function creditCode(int $redemptionId): string
    {
        return self::shortCode('F', 'credit_ticket', $redemptionId);
    }

    /** The redemption id in a creditCode(), or null. */
    public static function creditIdFromCode(string $text): ?int
    {
        return self::idFromShortCode('F', 'credit_ticket', $text);
    }

    /**
     * "EC-T12-0123456789abcdef": the code in a given-away ticket's email, a
     * TicketTransfer id signed like that ticket's link (transferUrl()).
     */
    public static function transferCode(int $transferId): string
    {
        return self::shortCode('T', 'xfer_ticket', $transferId);
    }

    /** The TicketTransfer id in a transferCode(), or null. */
    public static function transferIdFromCode(string $text): ?int
    {
        return self::idFromShortCode('T', 'xfer_ticket', $text);
    }

    /** The ticket page of a given-away ticket (TicketTransfer id). */
    public static function transferUrl(int $transferId): string
    {
        return add_query_arg(['token' => SignedLink::sign('xfer_ticket', $transferId)], rest_url(self::PAGE_ROUTE));
    }

    /**
     * "EC-B12-0123456789abcdef": the ticket code given to a buyer added at the
     * sales desk with an email address, so their emailed ticket has a code.
     */
    public static function deskBuyerCode(int $buyerId): string
    {
        return self::shortCode('B', 'buyer_ticket', $buyerId);
    }

    /** The ticket buyer id in a deskBuyerCode(), or null. */
    public static function deskBuyerIdFromCode(string $text): ?int
    {
        return self::idFromShortCode('B', 'buyer_ticket', $text);
    }

    /**
     * The short code under the ticket's QR code (ShortCode): the same as on
     * the ticket page and on the ticket's row in the door app. '' for a crew
     * ticket whose holder is not known (a preview).
     */
    public static function shortCodeOf(TicketBuyer|Guest|CrewTicket $row): string
    {
        if ($row instanceof CrewTicket) {
            if ($row->isTransfer()) {
                return ShortCode::forTransfer($row->id);
            }

            if ($row->personId <= 0) {
                return '';
            }

            // A free-entry ticket has its own door row and short code.
            return $row->isCredit()
                ? ShortCode::forCredit($row->personId, $row->eventDate)
                : ShortCode::forPerson($row->personId, $row->eventDate);
        }

        return $row instanceof Guest ? ShortCode::forGuest($row->id) : ShortCode::forBuyer($row->id);
    }

    private static function shortCode(string $letter, string $purpose, int $id): string
    {
        return 'EC-' . $letter . $id . '-' . SignedLink::shortSignature($purpose, $id);
    }

    /**
     * The id in a code made by shortCode() with the same letter and purpose,
     * or null when $text is not such a code or its signature does not verify.
     */
    private static function idFromShortCode(string $letter, string $purpose, string $text): ?int
    {
        if (1 !== preg_match('/^EC-' . $letter . '([1-9]\d{0,18})-([0-9a-f]{16})$/', trim($text), $match)) {
            return null;
        }

        $id = (int) $match[1];

        return hash_equals(SignedLink::shortSignature($purpose, $id), $match[2]) ? $id : null;
    }

    /**
     * The link in a crew ticket email: the sign-up's or the credit's existing
     * ticket page, the same link the task email and the bot give.
     */
    public static function crewUrl(CrewTicket $ticket): string
    {
        if ($ticket->isTransfer()) {
            return self::transferUrl($ticket->id);
        }

        return add_query_arg(
            ['token' => SignedLink::sign($ticket->isCredit() ? 'credit_ticket' : 'ticket', $ticket->id)],
            rest_url(self::PAGE_ROUTE)
        );
    }

    /**
     * Emails a crew member their ticket for a task they signed up for, at
     * once, and records that it was sent. A sign-up's ticket is locked on, as
     * the sign-up confirmation is. Nothing is sent to a placeholder address.
     *
     * The email lists the person's tasks on that night. When their ticket for
     * the night was already emailed for an earlier task, the same ticket (the
     * same code, so the QR code they have keeps working) is sent again with
     * the new task list.
     */
    public function sendCrewTicket(Person $person, int $assignmentId, string $date): bool
    {
        $sent = $this->sendPersonTicket(new CrewTicket(
            CrewTicket::KIND_CREW,
            $this->emailedTicketId($person->id, $date) ?? $assignmentId,
            $date,
            $person->name(),
            $person->email,
            $this->tasksOn($person->id, $date),
            HybridPolicy::personLanguage($person),
            $person->id
        ));

        if ($sent && null !== $this->assignments) {
            $this->assignments->markTicketMailed($assignmentId);
        }

        return $sent;
    }

    /**
     * After one of the person's tasks on $date was taken away (they cancelled
     * it, an organizer deleted the task, or someone took it over). Only when
     * their ticket for that night was emailed: while they still hold a task
     * that night, the ticket email is sent again with the new task list and
     * the same code; when none is left, the "Your ticket is cancelled" email
     * is sent. Nothing
     * for a night already past. One email per change: there is no waiting.
     *
     * $deleted is the task when an organizer deleted it (Q263): the email,
     * ticket or cancel, then names that task, and it is the only email the
     * person gets about the deletion (TaskCancelledNotice).
     */
    public function crewTasksChanged(Person $person, string $date, ?Task $deleted = null): bool
    {
        $ticketId = $this->emailedTicketId($person->id, $date);

        if (null === $ticketId) {
            return false;
        }

        $tasks = $this->tasksOn($person->id, $date);

        if ([] === $tasks) {
            return $this->sendCancelled(
                $person->name(),
                $person->email,
                $date,
                $deleted,
                HybridPolicy::personLanguage($person),
                $this->creditTickets($person, $date)
            );
        }

        if ($date < (string) current_time('Y-m-d')) {
            return false;
        }

        $ticket = new CrewTicket(
            CrewTicket::KIND_CREW,
            $ticketId,
            $date,
            $person->name(),
            $person->email,
            $tasks,
            HybridPolicy::personLanguage($person),
            $person->id
        );

        return $this->sendPersonTicket($ticket, $deleted, array_merge([$ticket], $this->creditTickets($person, $date)));
    }

    /**
     * Every ticket the person holds for the night $date, the crew ticket
     * first, then each free-entry ticket. The crew ticket is there while they
     * hold a task that night, or always when $assignmentId (a sign-up just
     * made) is given; its code is the one emailed first for the night, so
     * the QR code they have keeps working, and it lists the night's tasks.
     * Several tasks on one night share one crew ticket.
     *
     * @return list<CrewTicket>
     */
    public function nightTickets(Person $person, string $date, ?int $assignmentId = null): array
    {
        $tickets = [];
        $tasks = $this->tasksOn($person->id, $date);
        $crewId = $this->emailedTicketId($person->id, $date) ?? $assignmentId ?? $this->firstOccupyingId($person->id, $date);

        if (null !== $crewId && ([] !== $tasks || null !== $assignmentId)) {
            $tickets[] = new CrewTicket(
                CrewTicket::KIND_CREW,
                $crewId,
                $date,
                $person->name(),
                $person->email,
                $tasks,
                HybridPolicy::personLanguage($person),
                $person->id
            );
        }

        return array_merge($tickets, $this->creditTickets($person, $date));
    }

    /**
     * The ticket area of the sign-up email, in the current language: every
     * ticket the person holds for the night (nightTickets()), each with its
     * label, its QR code and short code. HTML for the email and the same as
     * plain text. Empty when the person has no address that can get mail.
     *
     * @return array{html: string, text: string}
     */
    public function signupTickets(Person $person, int $assignmentId, string $date): array
    {
        if ('' === $person->email || Person::isPlaceholderEmail($person->email) || '' === $date) {
            return ['html' => '', 'text' => ''];
        }

        $say = static fn (string $key): string => self::areaWords()[$key] ?? '';

        return $this->area($this->nightTickets($person, $date, $assignmentId), $say, true);
    }

    /**
     * The person's free-entry tickets for the night $date that are not
     * cancelled, as tickets for the email.
     *
     * @return list<CrewTicket>
     */
    private function creditTickets(Person $person, string $date): array
    {
        $tickets = [];

        foreach (null === $this->redemptions ? [] : $this->redemptions->forPerson($person->id) as $redemption) {
            if ($date !== (string) $redemption->redeemedFor || $redemption->isCancelled()) {
                continue;
            }

            $tickets[] = new CrewTicket(
                CrewTicket::KIND_CREDIT,
                $redemption->id,
                $date,
                $person->name(),
                $person->email,
                language: HybridPolicy::personLanguage($person),
                personId: $person->id
            );
        }

        usort($tickets, static fn (CrewTicket $a, CrewTicket $b): int => $a->id <=> $b->id);

        // Tickets other people gave this person for the night come after
        // their own.
        foreach (null === $this->transfers ? [] : $this->transfers->heldBy($person->id) as $transfer) {
            if ($date === $transfer->eventDate) {
                $tickets[] = $this->transferTicket($transfer, $person);
            }
        }

        return $tickets;
    }

    /**
     * A given-away ticket as a ticket for the email: the receiver's name and
     * address, the giver's name as $from.
     */
    private function transferTicket(TicketTransfer $transfer, ?Person $receiver, string $from = ''): CrewTicket
    {
        return new CrewTicket(
            CrewTicket::KIND_TRANSFER,
            $transfer->id,
            $transfer->eventDate,
            null === $receiver ? $transfer->name : $receiver->name(),
            null === $receiver ? $transfer->email : $receiver->email,
            language: null === $receiver
                ? ('' === $transfer->language ? null : $transfer->language)
                : HybridPolicy::personLanguage($receiver),
            personId: null === $receiver ? 0 : $receiver->id,
            from: $from
        );
    }

    /**
     * Emails the receiver of a given-away ticket their ticket, at once, and
     * records that it was sent: a member ($receiver) as their other tickets,
     * following their "Free-entry ticket ready" email choice and with every
     * ticket they hold that night; someone reached by email alone. $from is
     * the giver's name.
     */
    public function sendTransferTicket(TicketTransfer $transfer, ?Person $receiver, string $from): bool
    {
        if (
            null !== $receiver
            && ! (new NotificationPreferences())->emailAllowed($receiver, NotificationPreferences::TICKET)
        ) {
            return false;
        }

        $ticket = $this->transferTicket($transfer, $receiver, $from);
        $night = [$ticket];

        if (null !== $receiver) {
            $night = array_map(
                static fn (CrewTicket $t): CrewTicket => $t->isTransfer() && $t->id === $transfer->id ? $ticket : $t,
                $this->nightTickets($receiver, $transfer->eventDate)
            );

            if ([] === array_filter($night, static fn (CrewTicket $t): bool => $t->isTransfer() && $t->id === $transfer->id)) {
                $night[] = $ticket;
            }
        }

        $sent = $this->sendPersonTicket($ticket, null, $night);

        if ($sent && null !== $this->transfers) {
            $this->transfers->markTicketMailed($transfer->id);
        }

        return $sent;
    }

    /**
     * The email to someone who is not a member, offered a ticket: "$from is
     * giving you their free-entry ticket", with an Accept button to $acceptUrl.
     * In the ticket email's languages (the receiver's are not known). Not
     * sent to a placeholder or invalid address, or for a night already past.
     */
    public function sendTransferOffer(string $email, string $from, string $date, string $acceptUrl): bool
    {
        if ('' === $email || Person::isPlaceholderEmail($email) || ! is_email($email)) {
            return false;
        }

        if ('' === $date || $date < (string) current_time('Y-m-d')) {
            return false;
        }

        $mail = $this->composeTransferOffer($from, $date, $acceptUrl);

        return $this->mailer->toTicketHolder(
            $email,
            $mail['subject'],
            $mail['html'],
            $mail['text'],
            self::offerFooter(),
            true,
            '',
            [],
            HybridPolicy::languagesFor(HybridPolicy::TICKET, null)
        );
    }

    /**
     * The offer email: subject, HTML and plain text, in each language
     * HybridPolicy picks for a ticket whose reader is not known.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function composeTransferOffer(string $from, string $date, string $acceptUrl): array
    {
        $short = self::shortDate($date);
        [$event, , $eventId] = $this->event($date);
        $words = static function () use ($from, $event, $eventId, $short): array {
            $night = trim(self::eventIn($event, $eventId, EventName::lang()) . ' ' . $short);

            return [
                'body' => __('Hi,', 'eventcrew') . "\n\n" . sprintf(
                    /* translators: 1: the giver's name, 2: the night, e.g. "Club night 14.11.2026" */
                    __('%1$s is giving you their free-entry ticket for %2$s.', 'eventcrew'),
                    $from,
                    $night
                ) . "\n\n" . __(
                    'Accept it and write your name, and we will email you the ticket. The offer ends when the doors open.',
                    'eventcrew'
                ),
                'subject' => __('A free-entry ticket for you', 'eventcrew'),
                'accept' => __('Accept the ticket', 'eventcrew'),
            ];
        };
        $parts = HybridPolicy::inEach(HybridPolicy::languagesFor(HybridPolicy::TICKET, null), $words);
        $both = static fn (string $key, string $glue = ' / '): string => HybridPolicy::join(
            HybridPolicy::field($parts, $key),
            $glue
        );
        $bodies = HybridPolicy::field($parts, 'body');
        $tags = HybridPolicy::tags($bodies);
        $html = [];

        foreach ($bodies as $lang => $body) {
            $html[] = EmailBody::toHtml($body, [], EmailTemplate::accent(), $tags[$lang]);
        }

        $title = '' === $event ? $short : $event . "\n" . $short;

        return [
            'subject' => $both('subject') . ' – ' . $short,
            'html' => EmailBody::title($title)
                . implode('<hr style="border:0;border-top:1px solid #ead9bf;margin:22px 0">', $html)
                . EmailBody::centred(EmailBody::toHtml(
                    '',
                    [['label' => $both('accept', "\n"), 'url' => $acceptUrl]],
                    EmailTemplate::accent()
                )),
            'text' => implode("\n\n", $bodies) . "\n\n" . $both('accept') . ': ' . $acceptUrl,
        ];
    }

    /** The footer line of the offer email. */
    private static function offerFooter(): string
    {
        return HybridPolicy::join(HybridPolicy::inEach(
            HybridPolicy::languagesFor(HybridPolicy::TICKET, null),
            static fn (): string => __('You get this email because someone gave you a ticket to this event.', 'eventcrew')
        ));
    }

    /** The person's first sign-up on the night that holds a task, or null. */
    private function firstOccupyingId(int $personId, string $date): ?int
    {
        foreach (null === $this->assignments ? [] : $this->assignments->forPersonOn($personId, $date) as $assignment) {
            if ($assignment->isOccupying()) {
                return $assignment->id;
            }
        }

        return null;
    }

    /** True when the person's ticket for the night $date was emailed. */
    public function ticketEmailed(int $personId, string $date): bool
    {
        return null !== $this->emailedTicketId($personId, $date);
    }

    /**
     * The sentence that names a task an organizer deleted, in the current
     * language, for the ticket and cancel emails.
     */
    private static function deletedSentence(Task $task): string
    {
        $time = $task->timeRange();

        return sprintf(
            /* translators: %s: the task, e.g. "Bar, 21:00–23:00" */
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('The organizers have cancelled the task %s. You are no longer signed up for it. This does not count against your standing.', 'eventcrew'),
            $task->roleDisplay() . ('' === $time ? '' : ', ' . $time)
        );
    }

    /**
     * The sign-up whose ticket the person was emailed first for the night,
     * whatever its status now: its code is the person's ticket for that
     * night. Null when no ticket for the night was emailed.
     */
    private function emailedTicketId(int $personId, string $date): ?int
    {
        foreach (null === $this->assignments ? [] : $this->assignments->forPersonOn($personId, $date) as $assignment) {
            if (null !== $assignment->ticketMailedAt) {
                return $assignment->id;
            }
        }

        return null;
    }

    /**
     * The tasks the person holds on the night, earliest first.
     *
     * @return list<Task>
     */
    private function tasksOn(int $personId, string $date): array
    {
        $tasks = [];

        foreach (null === $this->assignments ? [] : $this->assignments->forPersonOn($personId, $date) as $assignment) {
            $task = $assignment->isOccupying() ? $this->tasks->find($assignment->taskId) : null;

            if (null !== $task) {
                $tasks[] = $task;
            }
        }

        usort(
            $tasks,
            static fn (Task $a, Task $b): int => [(string) $a->startsAt, $a->id] <=> [(string) $b->startsAt, $b->id]
        );

        return $tasks;
    }

    /**
     * Emails a crew member the ticket a free-entry credit bought, at once, and
     * records that it was sent. Follows the person's "Free-entry ticket ready"
     * email choice.
     */
    public function sendCreditTicket(Person $person, int $redemptionId, string $date): bool
    {
        if (! (new NotificationPreferences())->emailAllowed($person, NotificationPreferences::TICKET)) {
            return false;
        }

        $ticket = new CrewTicket(
            CrewTicket::KIND_CREDIT,
            $redemptionId,
            $date,
            $person->name(),
            $person->email,
            language: HybridPolicy::personLanguage($person),
            personId: $person->id
        );
        $night = $this->nightTickets($person, $date);

        // The new ticket is in the list even before its row can be read back.
        if ([] === array_filter($night, static fn (CrewTicket $t): bool => $t->isCredit() && $t->id === $redemptionId)) {
            $night[] = $ticket;
        }

        $sent = $this->sendPersonTicket($ticket, null, $night);

        if ($sent && null !== $this->redemptions) {
            $this->redemptions->markTicketMailed($redemptionId);
        }

        return $sent;
    }

    /**
     * @param list<CrewTicket> $night every ticket the person holds that night, for the ticket area
     */
    private function sendPersonTicket(CrewTicket $ticket, ?Task $deleted = null, array $night = []): bool
    {
        if ('' === $ticket->email || Person::isPlaceholderEmail($ticket->email) || '' === $ticket->eventDate) {
            return false;
        }

        return $this->deliver($ticket, $ticket->email, $deleted, $night);
    }

    /**
     * Tells a removed guest that their emailed ticket is cancelled. Only when
     * their ticket email was sent, and the night is today or later.
     */
    public function guestCancelled(Guest $guest): bool
    {
        if (self::STATUS_SENT !== $guest->mailStatus) {
            return false;
        }

        return $this->sendCancelled($guest->name, $guest->email, $guest->eventDate);
    }

    /**
     * Tells a crew member that the ticket of a cancelled sign-up is cancelled.
     * Only when that ticket was emailed.
     */
    public function crewCancelled(Person $person, ?string $ticketMailedAt, string $date): bool
    {
        if (null === $ticketMailedAt || '' === $ticketMailedAt) {
            return false;
        }

        return $this->sendCancelled(
            $person->name(),
            $person->email,
            $date,
            null,
            HybridPolicy::personLanguage($person),
            $this->nightTickets($person, $date)
        );
    }

    /**
     * Tells a guest's old address that the ticket moved to another address,
     * after an organizer changed the guest's email. Only when the ticket was
     * sent to the old address, and the night is today or later. $guest is the
     * row as it was before the change. The new address is not in the email.
     */
    public function guestMoved(Guest $guest): bool
    {
        if (self::STATUS_SENT !== $guest->mailStatus) {
            return false;
        }

        return $this->sendNotice(
            $guest->email,
            $guest->eventDate,
            fn (): array => $this->composeMoved($guest->name, $guest->eventDate)
        );
    }

    /**
     * The short "Your ticket is cancelled" email, in the languages HybridPolicy
     * picks ($language is the holder's stored language, null when not known).
     * $deleted, when an organizer deleted the task, is named first. Not sent
     * to an empty or placeholder address, or for a night already past.
     * $remaining, the holder's other tickets for that night that still work,
     * are shown under the sign-off with their QR codes.
     *
     * @param list<CrewTicket> $remaining
     */
    public function sendCancelled(
        string $name,
        string $email,
        string $date,
        ?Task $deleted = null,
        ?string $language = null,
        array $remaining = []
    ): bool {
        return $this->sendNotice(
            $email,
            $date,
            fn (): array => $this->composeCancelled($name, $date, $deleted, $language, $remaining),
            $language
        );
    }

    /**
     * Sends the email $compose returns (a cancel or a moved email) to $email.
     * Not sent to an empty or placeholder address, or for a night already
     * past.
     *
     * @param callable(): array{subject: string, html: string, text: string, corner?: string} $compose
     */
    private function sendNotice(string $email, string $date, callable $compose, ?string $language = null): bool
    {
        if ('' === $email || Person::isPlaceholderEmail($email) || ! is_email($email)) {
            return false;
        }

        if ('' === $date || $date < (string) current_time('Y-m-d')) {
            return false;
        }

        $mail = $compose();

        return $this->mailer->toTicketHolder(
            $email,
            $mail['subject'],
            $mail['html'],
            $mail['text'],
            self::noticeFooter($language),
            true,
            (string) ($mail['corner'] ?? ''),
            [],
            HybridPolicy::languagesFor(HybridPolicy::TICKET_CANCELLED, $language)
        );
    }

    /** The footer line of the cancel and moved emails, in their languages (HybridPolicy). */
    public static function noticeFooter(?string $language = null): string
    {
        $footer = static fn (): string => __(
            'You get this email because you had a ticket to this event.',
            'eventcrew'
        );
        $languages = HybridPolicy::languagesFor(HybridPolicy::TICKET_CANCELLED, $language);

        return HybridPolicy::join(HybridPolicy::inEach($languages, $footer));
    }

    /**
     * The cancel email for one ticket: subject, HTML content and plain text.
     * The HTML is in the v4 look (composeNotice()): the event as the gold
     * title, the texts, and a box with the holder's name and a red line that
     * says the ticket is cancelled. There is no QR code, since the cancelled
     * code no longer gets anyone in.
     * $deleted, when an organizer deleted the task, is named first.
     * $remaining, the holder's other tickets for the night that still work,
     * are said in a sentence and drawn under the sign-off ('corner'), each
     * with its QR code, as in the ticket email.
     *
     * @param list<CrewTicket> $remaining
     * @return array{subject: string, html: string, text: string, corner: string}
     */
    public function composeCancelled(
        string $name,
        string $date,
        ?Task $deleted = null,
        ?string $language = null,
        array $remaining = []
    ): array {
        $others = [] !== $remaining;
        $mail = $this->composeNotice($name, $date, $language, static fn (string $event, string $short): array => [
            'sentence' => (null === $deleted ? '' : self::deletedSentence($deleted) . "\n\n") . sprintf(
                /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('Your ticket to %1$s on %2$s is cancelled. The QR code in the ticket email no longer works at the door.', 'eventcrew'),
                $event,
                $short
            ) . ($others ? "\n\n" . __('Your other tickets for this night still work. They are below.', 'eventcrew') : ''),
            'subject' => __('Your ticket is cancelled', 'eventcrew'),
            'band' => __('TICKET CANCELLED', 'eventcrew'),
        ]);

        $mail['corner'] = '';

        if ($others) {
            $languages = HybridPolicy::languagesFor(HybridPolicy::TICKET_CANCELLED, $language);
            $words = HybridPolicy::inEach($languages, static fn (): array => self::areaWords());
            $say = static fn (string $key): string => HybridPolicy::join(HybridPolicy::field($words, $key), "\n");
            $area = $this->area($remaining, $say, true);
            $mail['corner'] = $area['html'];
            $mail['text'] .= "\n\n" . $area['text'];
        }

        return $mail;
    }

    /**
     * The email to a guest's old address after an organizer changed the
     * guest's email: the ticket moved to another address and the old code no
     * longer works. Drawn like the cancel email, with its own red band. The
     * new address is not in it.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function composeMoved(string $name, string $date): array
    {
        return $this->composeNotice($name, $date, null, static fn (string $event, string $short): array => [
            'sentence' => sprintf(
                /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('Your ticket to %1$s on %2$s was moved to another email address. The QR code in your earlier ticket email no longer works at the door.', 'eventcrew'),
                $event,
                $short
            ),
            'subject' => __('Your ticket moved to another address', 'eventcrew'),
            'band' => __('TICKET MOVED', 'eventcrew'),
        ]);
    }

    /**
     * A cancel or moved email in the v4 look, without a QR code: the event
     * and date as the gold title, the greeting and $texts' sentence in each
     * language HybridPolicy picks with a thin rule between them, then the
     * box with the holder's name and the red status line. $texts gets the event's name and
     * the short date and returns, in the current language, the sentence, the
     * subject and the status line's text.
     *
     * @param callable(string, string): array{sentence: string, subject: string, band: string} $texts
     * @return array{subject: string, html: string, text: string}
     */
    private function composeNotice(string $name, string $date, ?string $language, callable $texts): array
    {
        $short = self::shortDate($date);
        [$event, , $eventId] = $this->event($date);
        $words = static function () use ($name, $event, $eventId, $short, $texts): array {
            $first = DoorDesk::firstName($name);
            $own = $texts(self::eventIn($event, $eventId, EventName::lang()), $short);

            return [
                'body' => ('' === $first
                        ? __('Hi,', 'eventcrew')
                        /* translators: %s: the ticket holder's first name */
                        : sprintf(__('Hi %s,', 'eventcrew'), $first))
                    . "\n\n"
                    . $own['sentence'],
                'subject' => $own['subject'],
                'kicker' => __('Your ticket', 'eventcrew'),
                'band' => $own['band'],
            ];
        };
        $parts = HybridPolicy::inEach(HybridPolicy::languagesFor(HybridPolicy::TICKET_CANCELLED, $language), $words);
        $both = static fn (string $key): string => HybridPolicy::join(HybridPolicy::field($parts, $key));
        $bodies = HybridPolicy::field($parts, 'body');
        // Each language's text starts with its language tag (FI, EN), when they differ.
        $tags = HybridPolicy::tags($bodies);
        $html = [];

        foreach ($bodies as $lang => $body) {
            $html[] = EmailBody::toHtml($body, [], EmailTemplate::accent(), $tags[$lang]);
        }

        // The event and the date, each on its own row (Owner, 2026-11-06).
        $title = '' === $event ? $short : $event . "\n" . $short;

        return [
            'subject' => $both('subject') . ' – ' . $short,
            'html' => EmailBody::title($title)
                . implode('<hr style="border:0;border-top:1px solid #ead9bf;margin:22px 0">', $html)
                . $this->cancelledBand($both('band'), $both('kicker'), trim($name)),
            'text' => implode("\n\n", $bodies) . "\n\n" . $both('band'),
        ];
    }

    /**
     * The box at the end of a cancel or moved email: $kicker ("Your ticket")
     * small and muted, the holder's name in the serif (left out when there is
     * none), and $text as a band of white capitals on red, as the ticket page
     * draws its DISABLED band. The red has bgcolor and a one-colour gradient
     * behind it, so a mail app in dark mode does not repaint it.
     */
    private function cancelledBand(string $text, string $kicker, string $holder): string
    {
        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3eadb"'
                . ' style="margin:26px 0 6px;background:#f3eadb;border-radius:14px;border-collapse:separate">'
                . '<tr><td align="center" style="padding:18px 18px 8px;text-align:center">'
                . '<p style="margin:0 0 6px;%1$sfont-size:12px;font-weight:700;line-height:1.4;letter-spacing:.12em;'
                . 'text-transform:uppercase;color:%2$s">%3$s</p>%4$s</td></tr>'
                . '<tr><td align="center" style="padding:6px 18px 18px">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>'
                . '<td align="center" bgcolor="#b32d2e" style="padding:9px 18px;border-radius:8px;text-align:center;'
                . 'background-color:#b32d2e;background-image:linear-gradient(#b32d2e,#b32d2e)">'
                . '<p style="margin:0;%1$sfont-size:15px;font-weight:700;line-height:1.4;letter-spacing:.15em;'
                . 'color:#ffffff">%5$s</p></td></tr></table></td></tr></table>',
            self::PAGE_FONT,
            EmailBody::MUTED_COLOR,
            esc_html($kicker),
            '' === $holder ? '' : sprintf(
                '<p style="margin:0;font-family:%s;font-size:24px;font-weight:700;line-height:1.2;color:%s">%s</p>',
                EmailBody::HEAD_FONT,
                EmailBody::TEXT_COLOR,
                esc_html($holder)
            ),
            esc_html($text)
        );
    }

    /**
     * The email for one ticket, the same layout for every kind of ticket:
     * subject, the HTML content that goes into the email template, the
     * corner that goes under the template's sign-off, and the plain-text
     * alternative. $deleted, when an organizer deleted one of a crew
     * member's tasks, is named in the text instead of the thank-you line.
     *
     * The content: the "Your ticket" line, the event, the date and the
     * start time as the heading, the ticket text (TicketText) in each
     * language HybridPolicy picks, each with its greeting on its own line in
     * italics, the ticket note,
     * the "Open my ticket" button, right under it the event's picture and its
     * short DJ or host text (pictureBlock()), and the "See you on the
     * dancefloor" line. The corner (ticketCorner()): the holder's name, a buyer's code, the
     * offline hint and the small QR code in the bottom-right corner. For a
     * crew member with more than one ticket that night ($night, every ticket
     * they hold then), the corner is the ticket area (area()): one block per
     * ticket, each with its label, QR code, short code and link.
     *
     * @param list<CrewTicket> $night
     * @return array{subject: string, html: string, corner: string, text: string}
     */
    public function compose(TicketBuyer|Guest|CrewTicket $row, ?Task $deleted = null, array $night = []): array
    {
        $date = self::shortDate($row->eventDate);
        [$event, , $eventId] = $this->event($row->eventDate);
        // A crew member's ticket follows their stored language when the
        // ticket email is not hybrid; everyone else's is not known.
        $parts = [];

        foreach (
            HybridPolicy::languagesFor(HybridPolicy::TICKET, $row instanceof CrewTicket ? $row->language : null) as $lang
        ) {
            $parts[$lang] = Language::run(
                $lang,
                fn (): array => $this->words(
                    $row,
                    self::eventIn($event, $eventId, $lang),
                    $date,
                    $lang,
                    $deleted,
                    $eventId
                )
            );
        }

        $both = static fn (string $key, string $glue = ' / '): string => HybridPolicy::join(
            HybridPolicy::field($parts, $key),
            $glue
        );

        if ($row instanceof CrewTicket) {
            $url = self::crewUrl($row);
        } else {
            $url = $row instanceof Guest ? self::guestUrl($row->id, $row->ticketRev) : self::buyerUrl($row);
        }

        // Only a ticket buyer's code is written out: it is the code the buyer
        // knows from Holvi. A guest's or a crew member's code is in the QR only.
        $code = $row instanceof TicketBuyer ? $row->ticketCode : '';
        $short = self::shortCodeOf($row);
        $holder = '' === trim($row->name) ? '' : $row->name;

        if ($row instanceof Guest && $row->plusOnes > 0) {
            $holder = trim($holder . ' +' . $row->plusOnes);
        }

        // The same ticket note as the web ticket (door code, address), in
        // the languages of the rest of this email.
        $noteValue = SiteTexts::value('ticket_note');
        $noteTags = TicketNote::values($eventId);
        $note = implode("\n\n", array_unique(array_filter(array_map(
            static fn (string $lang): string => TicketNote::fill(
                Language::run($lang, static fn (): string => TicketNote::orBuiltIn(
                    TranslatedText::forReader($noteValue, $lang)
                )),
                $noteTags
            ),
            array_keys($parts)
        ))));

        // Each language's part starts with its language tag (FI, EN), when they differ.
        $tags = HybridPolicy::tags(HybridPolicy::field($parts, 'text'));
        $partsHtml = [];

        foreach ($parts as $lang => $words) {
            $partsHtml[] = $this->ticketPart($words, $tags[$lang]);
        }

        // Right under the "Open my ticket" button: the event's picture and
        // the short DJ or host text under it (Owner, 2026-11-08).
        $picture = $this->pictureBlock($eventId, array_keys($parts), $event);

        // The heading: the event, the date and the time (when the event has
        // one), each on its own row (Owner, 2026-11-06).
        $html = $this->ticketTop($both('subject', "\n"), $event, $date, EventTimes::start($eventId))
            . implode('<hr style="border:0;border-top:1px solid ' . self::RULE_COLOR . ';margin:20px 0">', $partsHtml)
            . ('' === $note ? '' : sprintf(
                '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0"'
                    . ' style="margin:6px 0 24px"><tr><td bgcolor="%1$s" style="padding:14px 16px;border-radius:14px;'
                    . 'background-color:%1$s;font-family:%2$s;font-size:15px;line-height:1.6;color:%3$s;'
                    . 'white-space:pre-line">%4$s</td></tr></table>',
                self::NOTE_COLOR,
                EmailBody::FONT,
                self::TEXT_COLOR,
                esc_html($note)
            ))
            . EmailBody::centred(EmailBody::toHtml(
                '',
                [['label' => $both('open', "\n"), 'url' => $url]],
                EmailTemplate::accent()
            ))
            . $picture['html']
            . sprintf(
                '<p style="margin:26px 0 0;font-family:%1$s;font-size:20px;font-style:italic;line-height:1.4;'
                    . 'color:%2$s;text-align:center">%3$s</p>',
                self::HEAD_FONT,
                self::GOLD_COLOR,
                str_replace("\n", '<br>', esc_html($both('closing', "\n")))
            );

        $offline = implode('<br>', array_map('esc_html', array_unique(array_values(HybridPolicy::field($parts, 'offline')))));

        if ($row instanceof CrewTicket && count($night) > 1) {
            $area = $this->area($night, static fn (string $key): string => $both($key, "\n"), true);
            $text = implode("\n\n", HybridPolicy::field($parts, 'text')) . "\n\n"
                . ('' === $note ? '' : $note . "\n\n")
                . $both('open') . ': ' . $url . "\n\n"
                . $picture['text']
                . $area['text'] . "\n\n"
                . $both('closing', "\n");

            return [
                'subject' => $both('subject') . ' – ' . $date,
                'html' => $html,
                'corner' => $area['html'],
                'text' => $text,
            ];
        }

        $text = implode("\n\n", HybridPolicy::field($parts, 'text')) . "\n\n"
            . ('' === $note ? '' : $note . "\n\n")
            . $both('open') . ': ' . $url . "\n\n"
            . $picture['text']
            . ('' === $holder ? '' : $holder . "\n")
            . ('' === $short ? '' : $both('short') . ': ' . $short . "\n")
            . ('' === $code ? '' : $both('code') . ': ' . $code . "\n")
            . "\n" . $both('closing', "\n");

        return [
            'subject' => $both('subject') . ' – ' . $date,
            'html' => $html,
            'corner' => $this->ticketCorner(self::qrData($row), $holder, $code, $offline, $short),
            'text' => $text,
        ];
    }

    /**
     * What goes right under the ticket email's "Open my ticket" button: the
     * picture of event $eventId (EventPicture), its alt text in the email's
     * first language, and under it the short DJ or host text in each of
     * $langs (EventPicture::djTexts()), each with its language tag when they
     * differ. No picture: only the text. No event: nothing. The plain-text
     * part gets the text alone, followed by an empty line.
     *
     * @param list<string> $langs
     * @return array{html: string, text: string}
     */
    private function pictureBlock(?int $eventId, array $langs, string $event): array
    {
        if (null === $eventId || $eventId <= 0) {
            return ['html' => '', 'text' => ''];
        }

        $url = EventPicture::url($eventId);
        $html = '' === $url
            ? ''
            : EventPicture::html($url, EventPicture::alt($eventId, $langs[0] ?? Language::ENGLISH, $event), '26px 0 0');
        $texts = EventPicture::djTexts($eventId, $langs);
        $tags = HybridPolicy::tags($texts);

        foreach ($texts as $lang => $text) {
            $html .= sprintf(
                '<p style="margin:14px 0 0;font-family:%1$s;font-size:15px;line-height:1.6;color:%2$s;'
                    . 'text-align:center">%3$s%4$s</p>',
                EmailBody::FONT,
                self::TEXT_COLOR,
                '' === $tags[$lang] ? '' : EmailBody::langTag($tags[$lang]),
                esc_html($text)
            );
        }

        return [
            'html' => $html,
            'text' => [] === $texts ? '' : implode("\n\n", $texts) . "\n\n",
        ];
    }

    /**
     * The footer line, in the ticket email's languages (HybridPolicy):
     * $language is the holder's stored language, null when not known.
     */
    public function footer(?string $language = null): string
    {
        return HybridPolicy::join(HybridPolicy::inEach(
            HybridPolicy::languagesFor(HybridPolicy::TICKET, $language),
            static fn (): string => __('You get this email because you have a ticket to this event.', 'eventcrew')
        ));
    }

    /**
     * Sends one row's ticket to its own address and records the outcome.
     */
    private function sendRow(TicketBuyer|Guest $row): bool
    {
        $sent = $this->deliver($row, $row->email);
        $status = $sent ? self::STATUS_SENT : self::STATUS_FAILED;

        if ($row instanceof Guest) {
            $this->guests->setMailStatus($row->id, $status);
        } else {
            $this->buyers->setMailStatus($row->id, $status);
        }

        return $sent;
    }

    /**
     * @param list<CrewTicket> $night
     */
    private function deliver(TicketBuyer|Guest|CrewTicket $row, string $to, ?Task $deleted = null, array $night = []): bool
    {
        if ('' === $to) {
            return false;
        }

        $mail = $this->compose($row, $deleted, $night);

        return $this->mailer->toTicketHolder(
            $to,
            $mail['subject'],
            $mail['html'],
            $mail['text'],
            $this->footer($row instanceof CrewTicket ? $row->language : null),
            true,
            $mail['corner'],
            [],
            HybridPolicy::languagesFor(HybridPolicy::TICKET, $row instanceof CrewTicket ? $row->language : null)
        );
    }

    /**
     * One language's part of the ticket email, in the current language: the
     * ticket text (TicketText) for $lang with its placeholders filled, split
     * into the greeting (a first paragraph written in *stars*, '' when there
     * is none) and the rest; the same as plain text; and the short words.
     *
     * @return array<string, string> greeting, body, text, subject, open, code,
     *                               short, closing, offline and the area's words (areaWords())
     */
    private function words(
        TicketBuyer|Guest|CrewTicket $row,
        string $event,
        string $date,
        string $lang,
        ?Task $deleted = null,
        ?int $eventId = null
    ): array {
        if (null !== $deleted) {
            $reason = self::deletedSentence($deleted);
        } elseif ($row instanceof CrewTicket && $row->isTransfer()) {
            $reason = '' === $row->from
                ? __('Someone gave you their free-entry ticket.', 'eventcrew')
                /* translators: %s: the name of the person who gave the ticket */
                : sprintf(__('%s gave you their free-entry ticket.', 'eventcrew'), $row->from);
        } elseif ($row instanceof CrewTicket) {
            $reason = TicketReasons::text($row->isCredit() ? TicketReasons::CREDIT : TicketReasons::TASK, $lang);
        } else {
            $reason = TicketReasons::text($row instanceof Guest ? TicketReasons::GUEST : TicketReasons::BOUGHT, $lang);
        }

        // A crew ticket lists the person's tasks on the night.
        $tasks = '';

        if ($row instanceof CrewTicket && ! $row->isCredit() && ! $row->isTransfer()) {
            $tasks = implode("\n", array_map(
                static function (Task $task): string {
                    $time = $task->timeRange();

                    return '- ' . $task->roleDisplay() . ('' === $time ? '' : ', ' . $time);
                },
                $row->tasks
            ));
        }

        $plusOnes = '';

        if ($row instanceof Guest && $row->plusOnes > 0) {
            /* translators: %d: how many people, e.g. "2 people" */
            $plusOnes = sprintf(_n('%d person', '%d people', $row->plusOnes, 'eventcrew'), $row->plusOnes);
        }

        $filled = TicketText::fill(TicketText::text($lang), [
            TicketText::FIRST_NAME_TAG => DoorDesk::firstName($row->name),
            TicketText::EVENT_TAG => $event,
            TicketText::DATE_TAG => $date,
            TicketText::START_TIME_TAG => EventTimes::start($eventId),
            TicketText::DOORS_CLOSE_TAG => EventTimes::doorsClose($eventId),
            TicketText::VENUE_TAG => EventVenue::line($eventId),
            TicketText::REASON_TAG => $reason,
            TicketText::TASKS_TAG => $tasks,
            TicketText::PLUS_ONES_TAG => $plusOnes,
            TicketText::ORGANIZATION_TAG => Organization::name(),
        ]);

        // A first paragraph that is one line in *stars* is the greeting.
        $greeting = '';
        $body = $filled;
        $paragraphs = explode("\n\n", $filled, 2);

        if (1 === preg_match('/^\*([^*\n]+)\*$/', $paragraphs[0], $match)) {
            $greeting = trim($match[1]);
            $body = $paragraphs[1] ?? '';
        }

        return array_merge(self::areaWords(), [
            'greeting' => $greeting,
            'body' => $body,
            'text' => trim($greeting . "\n\n" . $body),
            'subject' => __('Your ticket', 'eventcrew'),
            'code' => __('Ticket code', 'eventcrew'),
            'closing' => __('See you on the dancefloor', 'eventcrew'),
        ]);
    }

    /**
     * The words of the ticket area (area()), in the current language.
     *
     * @return array{crew: string, credit: string, transfer: string, open: string, short: string,
     *     offline: string, offline_all: string}
     */
    private static function areaWords(): array
    {
        return [
            'crew' => __('Crew ticket', 'eventcrew'),
            'credit' => __('Free-entry ticket', 'eventcrew'),
            'transfer' => __('Transferred ticket', 'eventcrew'),
            'open' => __('Open my ticket', 'eventcrew'),
            'short' => __('Short code', 'eventcrew'),
            'offline' => __('No internet? Show this QR code at the door.', 'eventcrew'),
            'offline_all' => __('No internet? Show these QR codes at the door.', 'eventcrew'),
        ];
    }

    /**
     * The ticket area: one block per ticket in $tickets, stacked, each the
     * ticket corner (ticketCorner()) with the ticket's label ("Crew ticket",
     * "Free-entry ticket") when $labels, the crew ticket's tasks for the night,
     * its QR code and short code. With more than one ticket each block has its
     * own "Open my ticket" link; the offline hint is said once, under the
     * last. $say gives each word (areaWords()'s keys) in the email's
     * languages. Also the same as plain text: label, link and short code per
     * ticket.
     *
     * @param list<CrewTicket>         $tickets
     * @param callable(string): string $say
     * @return array{html: string, text: string}
     */
    private function area(array $tickets, callable $say, bool $labels): array
    {
        $html = '';
        $text = [];
        $count = count($tickets);
        $last = $count - 1;

        foreach (array_values($tickets) as $i => $ticket) {
            $label = $labels ? $say($ticket->isTransfer() ? 'transfer' : ($ticket->isCredit() ? 'credit' : 'crew')) : '';
            $url = self::crewUrl($ticket);
            $short = self::shortCodeOf($ticket);
            $tasks = array_map(
                static function (Task $task): string {
                    $time = $task->timeRange();

                    return $task->roleDisplay() . ('' === $time ? '' : ', ' . $time);
                },
                $ticket->isCredit() || $ticket->isTransfer() ? [] : $ticket->tasks
            );
            $extra = implode('', array_map(
                static fn (string $line): string => sprintf(
                    '<p style="margin:0 0 4px;font-family:%1$s;font-size:14px;line-height:1.4;color:%2$s">%3$s</p>',
                    EmailBody::FONT,
                    self::TEXT_COLOR,
                    esc_html($line)
                ),
                $tasks
            ));

            if ($count > 1) {
                $extra .= sprintf(
                    '<p style="margin:0 0 8px;font-family:%1$s;font-size:14px;line-height:1.4">'
                        . '<a href="%2$s" style="color:%3$s;text-decoration:underline">%4$s</a></p>',
                    EmailBody::FONT,
                    esc_url($url),
                    esc_attr(EmailTemplate::accent()),
                    str_replace("\n", ' / ', esc_html($say('open')))
                );
            }

            $offline = $i === $last
                ? str_replace("\n", '<br>', esc_html($say($count > 1 ? 'offline_all' : 'offline')))
                : '';
            $html .= $this->ticketCorner(self::qrData($ticket), trim($ticket->name), '', $offline, $short, $label, $extra);

            $text[] = implode("\n", array_filter([
                str_replace("\n", ' / ', $label),
                implode("\n", $tasks),
                str_replace("\n", ' / ', $say('open')) . ': ' . $url,
                '' === $short ? '' : str_replace("\n", ' / ', $say('short')) . ': ' . $short,
            ], 'strlen'));
        }

        return ['html' => $html, 'text' => implode("\n\n", $text)];
    }

    /**
     * The top of the ticket email: a small gold star, $kicker ("Your
     * ticket", one language per row) small and in capitals, then the event,
     * the date and the time ('' for none), each on its own row, as the
     * shining heading in gold, with a short gold line under it.
     */
    private function ticketTop(string $kicker, string $event, string $date, string $start = ''): string
    {
        return sprintf(
            '<p style="margin:0 0 10px;font-size:16px;line-height:1;color:#c9802f;text-align:center">✦</p>'
                . '<p style="margin:0 0 6px;font-family:%1$s;font-size:12px;line-height:1.4;letter-spacing:.14em;'
                . 'text-transform:uppercase;color:%2$s;text-align:center">%3$s</p>'
                . '<h1 class="ec-shimmer" style="margin:0;font-family:%4$s;font-size:27px;font-weight:700;'
                . 'line-height:1.25;color:%5$s;text-align:center">%6$s</h1>'
                . '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:18px auto 24px"><tr><td width="48" height="2" bgcolor="#d9b27a"'
                . ' style="width:48px;height:2px;line-height:2px;font-size:0;background-color:#d9b27a">&nbsp;</td>'
                . '</tr></table>',
            EmailBody::FONT,
            self::MUTED_COLOR,
            str_replace("\n", '<br>', esc_html($kicker)),
            self::HEAD_FONT,
            self::GOLD_COLOR,
            implode('<br>', array_map('esc_html', array_filter([$event, $date, $start], 'strlen')))
        );
    }

    /**
     * One language's part: its FI / EN tag ('' for none), the greeting on its
     * own line in italics, then the text.
     *
     * @param array{greeting: string, body: string} $words
     */
    private function ticketPart(array $words, string $tag): string
    {
        $html = '';

        if ('' !== $tag) {
            $html .= '<p style="margin:0 0 8px">' . EmailBody::langTag($tag) . '</p>';
        }

        if ('' !== $words['greeting']) {
            $html .= sprintf(
                '<p style="margin:0 0 12px;font-family:%1$s;font-size:18px;font-style:italic;line-height:1.4;'
                    . 'color:%2$s">%3$s</p>',
                self::HEAD_FONT,
                self::TEXT_COLOR,
                esc_html($words['greeting'])
            );
        }

        return $html . ('' === $words['body'] ? '' : EmailBody::toHtml($words['body'], [], EmailTemplate::accent()));
    }

    /**
     * The corner under the email's sign-off: on the left the holder's name,
     * a buyer's code and the "No internet?" line ($offline, already escaped
     * HTML); on the right the small QR code in a white box, no logo, with
     * the short code ($short, ShortCode) right under it, as on the ticket
     * page and the door app's row. $label, when given, is the ticket's kind
     * ("Crew ticket") small and in capitals above the name; $extra is HTML
     * under the name (the ticket area's task lines and link).
     */
    private function ticketCorner(
        string $data,
        string $holder,
        string $code,
        string $offline,
        string $short = '',
        string $label = '',
        string $extra = ''
    ): string {
        $qr = $this->qr->table($data, $this->qr->fitCell($data, self::QR_WIDTH, '', 4));

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="margin:0 0 16px;border-top:1px solid ' . self::RULE_COLOR . '"><tr>'
            . '<td valign="bottom" style="padding:16px 12px 0 0;vertical-align:bottom">'
            . ('' === $label ? '' : sprintf(
                '<p style="margin:0 0 4px;font-family:%1$s;font-size:12px;font-weight:700;line-height:1.4;'
                    . 'letter-spacing:.12em;text-transform:uppercase;color:%2$s">%3$s</p>',
                EmailBody::FONT,
                self::GOLD_COLOR,
                str_replace("\n", '<br>', esc_html($label))
            ))
            . ('' === $holder ? '' : sprintf(
                '<p style="margin:0 0 4px;font-family:%1$s;font-size:18px;font-weight:700;line-height:1.3;'
                    . 'color:%2$s">%3$s</p>',
                self::HEAD_FONT,
                self::TEXT_COLOR,
                esc_html($holder)
            ))
            . ('' === $code ? '' : sprintf(
                '<p style="margin:0 0 8px;font-family:Menlo,Consolas,\'Courier New\',monospace;font-size:15px;'
                    . 'font-weight:700;line-height:1.4;letter-spacing:.06em;color:%1$s">%2$s</p>',
                self::TEXT_COLOR,
                esc_html($code)
            ))
            . $extra
            . ('' === $offline ? '' : sprintf(
                '<p style="margin:0;font-family:%1$s;font-size:13px;line-height:1.5;color:%2$s">%3$s</p>',
                EmailBody::FONT,
                self::MUTED_COLOR,
                $offline
            ))
            . '</td>'
            . '<td width="156" align="right" valign="bottom" style="width:156px;padding:16px 0 0;'
            . 'vertical-align:bottom">'
            . '<table role="presentation" align="right" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td bgcolor="#ffffff" style="padding:4px;background-color:#ffffff;border-radius:10px;'
            . 'box-shadow:0 0 0 1px ' . self::RULE_COLOR . '">' . $qr . '</td></tr>'
            . ('' === $short ? '' : sprintf(
                '<tr><td align="center" style="padding:6px 0 0;font-family:Menlo,Consolas,\'Courier New\',monospace;'
                    . 'font-size:17px;font-weight:700;line-height:1.3;letter-spacing:.2em;color:%1$s;'
                    . 'text-align:center">%2$s</td></tr>',
                self::TEXT_COLOR,
                esc_html($short)
            ))
            . '</table>'
            . '</td></tr></table>';
    }

    /**
     * The ticket as the ticket page draws it (Telegram\TicketController): a
     * white card with rounded corners on the page's grey, the dark header on
     * top and $body centred under it. $band, when given, is a cell drawn
     * between the two (the cancel email's red band). Tables and inline
     * styles only, so mail apps that drop a style block or flex layout draw
     * it the same.
     */
    private function ticketCard(string $head, string $body, string $band = ''): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#e8e6e3"'
            . ' style="margin:24px 0 8px;background-color:#e8e6e3;border-radius:12px">'
            . '<tr><td align="center" style="padding:16px 6px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff"'
            . ' style="width:100%;max-width:416px;margin:0 auto;background-color:#ffffff;border-radius:16px;'
            . 'box-shadow:0 2px 12px rgba(0,0,0,.18)">'
            . '<tr>' . $head . '</tr>'
            . ('' === $band ? '' : '<tr>' . $band . '</tr>')
            . '<tr><td align="center" style="padding:19px 16px;text-align:center;border-radius:0 0 16px 16px">'
            . $body
            . '</td></tr></table></td></tr></table>';
    }

    /**
     * The card's header cell, as on the ticket page: $kicker small and in
     * capitals, the event's name large, the date, white on dark. With the
     * event's picture, the picture is behind it under the page's dark
     * layer; a mail app that does not load it shows the plain dark.
     *
     * The dark is also a one-colour background image, which the mail apps
     * that recolour in dark mode leave alone, as the QR code's cells do. The
     * picture URL goes through esc_url() inside &quot; quotes, as on the page.
     */
    private function ticketHead(string $kicker, string $event, string $date, string $image): string
    {
        $layers = '' === $image
            ? 'linear-gradient(#2b2b30,#2b2b30)'
            : 'linear-gradient(rgba(0,0,0,.2),rgba(0,0,0,.72)),url(&quot;' . esc_url($image) . '&quot;)';
        $shadow = 'text-shadow:0 1px 3px rgba(0,0,0,.6)';

        return sprintf(
            '<td align="center" bgcolor="#2b2b30" style="padding:%1$s;text-align:center;background-color:#2b2b30;'
                . 'background-image:%2$s;background-position:center 25%%;background-size:cover;'
                . 'background-repeat:no-repeat;border-radius:16px 16px 0 0">'
                . '<p style="margin:0;%3$sfont-size:12px;line-height:1.4;letter-spacing:.08em;text-transform:uppercase;'
                . 'color:#dcdcde;%4$s">%5$s</p>'
                . '<p style="margin:3px 0;%3$sfont-size:24px;font-weight:700;line-height:1.2;color:#ffffff;%4$s">'
                . '%6$s</p>'
                . '<p style="margin:0;%3$sfont-size:15px;line-height:1.4;color:#ffffff;%4$s">%7$s</p></td>',
            '' === $image ? '19px' : '76px 19px 19px',
            $layers,
            self::PAGE_FONT,
            $shadow,
            esc_html($kicker),
            esc_html($event),
            esc_html($date)
        );
    }

    /**
     * The event on $date: [its name, the URL of its picture or '', its
     * event post or null], from its first task with an event name, or [the
     * site's name, '', null] when the night has no tasks.
     *
     * @return array{0: string, 1: string, 2: ?int}
     */
    private function event(string $date): array
    {
        foreach ($this->tasks->forDate($date) as $task) {
            $name = trim($task->eventName());

            if ('' !== $name) {
                return [$name, SignupBoardView::eventImage($task->eventPostId), $task->eventPostId];
            }
        }

        return [(string) get_bloginfo('name'), '', null];
    }

    /** $event's clean name in $lang (EventName) when it is a linked event, else $event. */
    private static function eventIn(string $event, ?int $eventId, string $lang): string
    {
        return null === $eventId ? $event : EventName::name($eventId, $lang, $event);
    }

    /** "14.11.2026": the date as written in Finnish and read in English. */
    public static function shortDate(string $ymd): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return false === $day ? $ymd : $day->format('j.n.Y');
    }
}
