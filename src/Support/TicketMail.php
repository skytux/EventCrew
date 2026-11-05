<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\CrewTicket;
use EventCrew\Models\Guest;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Models\TicketBuyer;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Telegram\TicketController;
use EventCrew\Web\SignupBoardView;

/**
 * The ticket email: after an import, every imported ticket buyer with an email
 * address and a ticket code, and every guest from a mailed guest list with an
 * email address, is sent one email. It thanks them and carries the ticket: the
 * QR code the door scans, the code written out, and a link to the ticket page.
 *
 * The email is in Finnish and in English, Finnish first, in one message. Both
 * texts are the plugin's own English strings: the Finnish block is composed
 * inside Language::run('fi'), so it comes from languages/eventcrew-fi.mo.
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
     * The widest the QR code is drawn, quiet zone included, in pixels. On a
     * 360 pixel wide phone the default email template leaves about 270
     * pixels for the message; the ticket's grey surround and the card's
     * padding take 46 of them.
     */
    private const QR_WIDTH = 224;

    public function __construct(
        private readonly TicketBuyerRepository $buyers,
        private readonly GuestRepository $guests,
        private readonly TaskRepository $tasks,
        private readonly Mailer $mailer,
        private readonly TicketQr $qr = new TicketQr(),
        private readonly ?AssignmentRepository $assignments = null,
        private readonly ?RedemptionRepository $redemptions = null
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
            $this->tasksOn($person->id, $date)
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
     */
    public function crewTasksChanged(Person $person, string $date): bool
    {
        $ticketId = $this->emailedTicketId($person->id, $date);

        if (null === $ticketId) {
            return false;
        }

        $tasks = $this->tasksOn($person->id, $date);

        if ([] === $tasks) {
            return $this->sendCancelled($person->name(), $person->email, $date);
        }

        if ($date < (string) current_time('Y-m-d')) {
            return false;
        }

        return $this->sendPersonTicket(new CrewTicket(
            CrewTicket::KIND_CREW,
            $ticketId,
            $date,
            $person->name(),
            $person->email,
            $tasks
        ));
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

        $sent = $this->sendPersonTicket(
            new CrewTicket(CrewTicket::KIND_CREDIT, $redemptionId, $date, $person->name(), $person->email)
        );

        if ($sent && null !== $this->redemptions) {
            $this->redemptions->markTicketMailed($redemptionId);
        }

        return $sent;
    }

    private function sendPersonTicket(CrewTicket $ticket): bool
    {
        if ('' === $ticket->email || Person::isPlaceholderEmail($ticket->email) || '' === $ticket->eventDate) {
            return false;
        }

        return $this->deliver($ticket, $ticket->email);
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

        return $this->sendCancelled($person->name(), $person->email, $date);
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
     * The short "Your ticket is cancelled" email, in Finnish and English. Not
     * sent to an empty or placeholder address, or for a night already past.
     */
    public function sendCancelled(string $name, string $email, string $date): bool
    {
        return $this->sendNotice($email, $date, fn (): array => $this->composeCancelled($name, $date));
    }

    /**
     * Sends the email $compose returns (a cancel or a moved email) to $email.
     * Not sent to an empty or placeholder address, or for a night already
     * past.
     *
     * @param callable(): array{subject: string, html: string, text: string} $compose
     */
    private function sendNotice(string $email, string $date, callable $compose): bool
    {
        if ('' === $email || Person::isPlaceholderEmail($email) || ! is_email($email)) {
            return false;
        }

        if ('' === $date || $date < (string) current_time('Y-m-d')) {
            return false;
        }

        $mail = $compose();
        $footer = static fn (): string => __(
            'You get this email because you had a ticket to this event.',
            'eventcrew'
        );
        $fi = Language::run(Language::FINNISH, $footer);
        $en = Language::run(Language::ENGLISH, $footer);

        return $this->mailer->toTicketHolder(
            $email,
            $mail['subject'],
            $mail['html'],
            $mail['text'],
            $fi === $en ? $en : $fi . ' / ' . $en
        );
    }

    /**
     * The cancel email for one ticket: subject, HTML content and plain text.
     * The HTML is drawn like the ticket email: the same texts-then-card
     * order, the same card and dark header, and a red band across the card
     * that says the ticket is cancelled. The card has the holder's name and
     * no QR code, since the cancelled code no longer gets anyone in.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function composeCancelled(string $name, string $date): array
    {
        return $this->composeNotice($name, $date, static fn (string $event, string $short): array => [
            'sentence' => sprintf(
                /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('Your ticket to %1$s on %2$s is cancelled. The QR code in the ticket email no longer works at the door.', 'eventcrew'),
                $event,
                $short
            ),
            'subject' => __('Your ticket is cancelled', 'eventcrew'),
            'band' => __('TICKET CANCELLED', 'eventcrew'),
        ]);
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
        return $this->composeNotice($name, $date, static fn (string $event, string $short): array => [
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
     * A cancel or moved email: the greeting and $texts' sentence, then the
     * card with the red band. $texts gets the event's name and the short
     * date and returns, in the current language, the sentence, the subject
     * and the band's text.
     *
     * @param callable(string, string): array{sentence: string, subject: string, band: string} $texts
     * @return array{subject: string, html: string, text: string}
     */
    private function composeNotice(string $name, string $date, callable $texts): array
    {
        $short = self::shortDate($date);
        [$event, $image] = $this->event($date);
        $words = static function () use ($name, $event, $short, $texts): array {
            $first = DoorDesk::firstName($name);
            $own = $texts($event, $short);

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
        $fi = Language::run(Language::FINNISH, $words);
        $en = Language::run(Language::ENGLISH, $words);
        $both = static fn (string $key): string => $fi[$key] === $en[$key] ? $en[$key] : $fi[$key] . ' / ' . $en[$key];
        // Each language's text starts with its FI / EN tag, when the two differ.
        $tags = $fi['body'] === $en['body'] ? ['', ''] : ['FI', 'EN'];
        $holder = trim($name);

        $card = $this->ticketCard(
            $this->ticketHead($both('kicker'), $event, $short, $image),
            '' === $holder ? '' : sprintf(
                '<p style="margin:0;%1$sfont-size:30px;font-weight:700;line-height:1.15;color:#1d1d1f">%2$s</p>',
                self::PAGE_FONT,
                esc_html($holder)
            ),
            $this->cancelledBand($both('band'))
        );

        return [
            'subject' => $both('subject') . ' – ' . $short,
            'html' => EmailBody::toHtml($fi['body'], [], EmailTemplate::accent(), $tags[0])
                . '<hr style="border:0;border-top:1px solid #e3e3e8;margin:20px 0">'
                . EmailBody::toHtml($en['body'], [], EmailTemplate::accent(), $tags[1])
                . $card,
            'text' => $fi['body'] . "\n\n" . $en['body'] . "\n\n" . $both('band'),
        ];
    }

    /**
     * The red band of a cancelled ticket, as the ticket page draws its
     * DISABLED band: full width, white capitals on red, under the header.
     */
    private function cancelledBand(string $text): string
    {
        return sprintf(
            '<td align="center" bgcolor="#b32d2e" style="padding:9px 12px;text-align:center;background-color:#b32d2e;'
                . 'background-image:linear-gradient(#b32d2e,#b32d2e)">'
                . '<p style="margin:0;%1$sfont-size:15px;font-weight:700;line-height:1.4;letter-spacing:.15em;'
                . 'color:#ffffff">%2$s</p></td>',
            self::PAGE_FONT,
            esc_html($text)
        );
    }

    /**
     * The email for one ticket: subject, the HTML content that goes into the
     * email template (the QR code drawn in it as tables), and the plain-text
     * alternative.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function compose(TicketBuyer|Guest|CrewTicket $row): array
    {
        $date = self::shortDate($row->eventDate);
        [$event, $image] = $this->event($row->eventDate);
        $fi = Language::run(Language::FINNISH, fn (): array => $this->words($row, $event, $date));
        $en = Language::run(Language::ENGLISH, fn (): array => $this->words($row, $event, $date));
        $both = static fn (string $key): string => $fi[$key] === $en[$key] ? $en[$key] : $fi[$key] . ' / ' . $en[$key];

        if ($row instanceof CrewTicket) {
            $url = self::crewUrl($row);
        } else {
            $url = $row instanceof Guest ? self::guestUrl($row->id, $row->ticketRev) : self::buyerUrl($row);
        }

        // Only a ticket buyer's code is written out: it is the code the buyer
        // knows from Holvi. A guest's or a crew member's code is in the QR only.
        $code = $row instanceof TicketBuyer ? $row->ticketCode : '';
        $holder = '' === trim($row->name) ? '' : $row->name;

        if ($row instanceof Guest && $row->plusOnes > 0) {
            $holder = trim($holder . ' +' . $row->plusOnes);
        }

        // The site's icon (or logo) in the middle, as on the web ticket.
        $logo = EmailTemplate::qrMarkUrl();
        $qrHtml = $this->qr->table(
            self::qrData($row),
            $this->qr->fitCell(self::qrData($row), self::QR_WIDTH, $logo),
            $logo
        );
        // The same ticket note as the web ticket (door code, address), as typed.
        $note = trim((string) get_option(TicketController::NOTE_OPTION, ''));

        $ticket = $this->ticketCard(
            $this->ticketHead($both('subject'), $event, $date, $image),
            ('' === $holder ? '' : sprintf(
                '<p style="margin:0 0 4px;%1$sfont-size:30px;font-weight:700;line-height:1.15;color:#1d1d1f">%2$s</p>',
                self::PAGE_FONT,
                esc_html($holder)
            ))
            . $qrHtml
            . ('' === $code ? '' : sprintf(
                '<p style="margin:0 0 16px;font-family:Menlo,Consolas,\'Courier New\',monospace;font-size:22px;'
                    . 'font-weight:700;line-height:1.3;letter-spacing:.08em;color:#1d1d1f">%s</p>',
                esc_html($code)
            ))
            . ('' === $note ? '' : sprintf(
                '<p style="margin:16px 0 0;%1$sfont-size:16px;line-height:1.5;color:#1d1d1f;white-space:pre-line">'
                    . '%2$s</p>',
                self::PAGE_FONT,
                esc_html($note)
            ))
            . sprintf(
                '<p style="margin:16px 0 0;%1$sfont-size:13px;line-height:1.5;color:#616165">'
                    . '<a href="%2$s" style="color:#616165;text-decoration:underline">%3$s</a></p>',
                self::PAGE_FONT,
                esc_url($url),
                esc_html($both('open'))
            )
        );

        // Each language's text starts with its FI / EN tag, when the two differ.
        $tags = $fi['body'] === $en['body'] ? ['', ''] : ['FI', 'EN'];
        $html = EmailBody::toHtml($fi['body'], [], EmailTemplate::accent(), $tags[0])
            . '<hr style="border:0;border-top:1px solid #e3e3e8;margin:20px 0">'
            . EmailBody::toHtml($en['body'], [], EmailTemplate::accent(), $tags[1])
            . $ticket;

        $text = $fi['body'] . "\n\n" . $en['body'] . "\n\n"
            . ('' === $code ? '' : $both('code') . ': ' . $code . "\n")
            . ('' === $holder ? '' : $holder . "\n")
            . ('' === $note ? '' : "\n" . $note . "\n\n")
            . $both('open') . ': ' . $url;

        return [
            'subject' => $both('subject') . ' – ' . $date,
            'html' => $html,
            'text' => $text,
        ];
    }

    /** The footer line, in both languages. */
    public function footer(): string
    {
        $fi = Language::run(
            Language::FINNISH,
            static fn (): string => __('You get this email because you have a ticket to this event.', 'eventcrew')
        );
        $en = Language::run(
            Language::ENGLISH,
            static fn (): string => __('You get this email because you have a ticket to this event.', 'eventcrew')
        );

        return $fi === $en ? $en : $fi . ' / ' . $en;
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

    private function deliver(TicketBuyer|Guest|CrewTicket $row, string $to): bool
    {
        if ('' === $to) {
            return false;
        }

        $mail = $this->compose($row);

        return $this->mailer->toTicketHolder(
            $to,
            $mail['subject'],
            $mail['html'],
            $mail['text'],
            $this->footer()
        );
    }

    /**
     * One language's words for one ticket, composed in the current language.
     *
     * @return array{body: string, subject: string, open: string, code: string}
     */
    private function words(TicketBuyer|Guest|CrewTicket $row, string $event, string $date): array
    {
        $first = DoorDesk::firstName($row->name);

        if ($row instanceof CrewTicket) {
            $thanks = $row->isCredit()
                ? __('You spent a free-entry credit on this night.', 'eventcrew')
                : __('Thank you for taking a task!', 'eventcrew');
        } else {
            $thanks = $row instanceof Guest
                ? __('Thank you for coming! You are on the guest list.', 'eventcrew')
                : __('Thank you for buying a ticket!', 'eventcrew');
        }

        $lines = [
            '' === $first
                ? __('Hi,', 'eventcrew')
                /* translators: %s: the ticket holder's first name */
                : sprintf(__('Hi %s,', 'eventcrew'), $first),
            $thanks,
            sprintf(
                /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                __('Here is your ticket to %1$s on %2$s. Show the QR code below at the door.', 'eventcrew'),
                $event,
                $date
            ),
        ];

        // A crew ticket lists the person's tasks on the night.
        if ($row instanceof CrewTicket && ! $row->isCredit() && [] !== $row->tasks) {
            $lines[] = __('Your tasks on this night:', 'eventcrew');
            $lines[] = implode("\n", array_map(
                static function (Task $task): string {
                    $time = $task->timeRange();

                    return '- ' . $task->roleDisplay() . ('' === $time ? '' : ', ' . $time);
                },
                $row->tasks
            ));
        }

        if ($row instanceof Guest && $row->plusOnes > 0) {
            $lines[] = sprintf(
                /* translators: %d: how many people the guest may bring */
                _n(
                    'You can bring %d person with you.',
                    'You can bring %d people with you.',
                    $row->plusOnes,
                    'eventcrew'
                ),
                $row->plusOnes
            );
        }

        return [
            'body' => implode("\n\n", $lines),
            'subject' => __('Your ticket', 'eventcrew'),
            'open' => __('Open the ticket in your browser', 'eventcrew'),
            'code' => __('Ticket code', 'eventcrew'),
        ];
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
     * The event on $date: [its name, the URL of its picture or ''], from its
     * first task with an event name, or [the site's name, ''] when the night
     * has no tasks.
     *
     * @return array{0: string, 1: string}
     */
    private function event(string $date): array
    {
        foreach ($this->tasks->forDate($date) as $task) {
            $name = trim($task->eventName());

            if ('' !== $name) {
                return [$name, SignupBoardView::eventImage($task->eventPostId)];
            }
        }

        return [(string) get_bloginfo('name'), ''];
    }

    /** "14.11.2026": the date as written in Finnish and read in English. */
    public static function shortDate(string $ymd): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return false === $day ? $ymd : $day->format('j.n.Y');
    }
}
