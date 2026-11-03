<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\CrewTicket;
use EventCrew\Models\Guest;
use EventCrew\Models\Person;
use EventCrew\Models\TicketBuyer;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Telegram\TicketController;

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

    /** Emails per cron run or per "Send the rest now". */
    public const BATCH = 25;

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
        add_action(self::HOOK, [$this, 'run']);
    }

    /**
     * Asks WP-Cron to send the waiting tickets on its next run, unless that is
     * already asked. Called after an import adds rows.
     */
    public static function schedule(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_single_event')) {
            return;
        }

        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time(), self::HOOK);
        }
    }

    /**
     * The cron event: sends up to BATCH tickets not tried yet, for tonight and
     * later nights, buyers before guests, and schedules the next batch when
     * this one was full. Returns how many emails were tried.
     */
    public function run(): int
    {
        $today = (string) current_time('Y-m-d');
        $rows = $this->buyers->unsentFrom($today, self::BATCH);

        if (count($rows) < self::BATCH) {
            $rows = array_merge($rows, $this->guests->unsentFrom($today, self::BATCH - count($rows)));
        }

        foreach ($rows as $row) {
            $this->sendRow($row);
        }

        if (count($rows) >= self::BATCH) {
            self::schedule();
        }

        return count($rows);
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

    /** The token of a guest's ticket page, and of the QR code on their ticket. */
    public static function guestToken(int $guestId): string
    {
        return SignedLink::sign('guest_ticket', $guestId);
    }

    public static function buyerUrl(TicketBuyer $buyer): string
    {
        return add_query_arg(['token' => self::buyerToken($buyer)], rest_url(self::PAGE_ROUTE));
    }

    public static function guestUrl(int $guestId): string
    {
        return add_query_arg(['token' => self::guestToken($guestId)], rest_url(self::PAGE_ROUTE));
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

        return $row instanceof Guest ? self::guestCode($row->id) : $row->ticketCode;
    }

    /** "EC-G12-0123456789abcdef": a guest's id and a short signature of it. */
    public static function guestCode(int $guestId): string
    {
        return self::shortCode('G', 'guest_ticket', $guestId);
    }

    /**
     * The guest id in a code made by guestCode(), or null when $text is not
     * such a code or its signature does not verify.
     */
    public static function guestIdFromCode(string $text): ?int
    {
        return self::idFromShortCode('G', 'guest_ticket', $text);
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
     */
    public function sendCrewTicket(Person $person, int $assignmentId, string $date): bool
    {
        $sent = $this->sendPersonTicket(
            new CrewTicket(CrewTicket::KIND_CREW, $assignmentId, $date, $person->name(), $person->email)
        );

        if ($sent && null !== $this->assignments) {
            $this->assignments->markTicketMailed($assignmentId);
        }

        return $sent;
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
     * The short "Your ticket is cancelled" email, in Finnish and English. Not
     * sent to an empty or placeholder address, or for a night already past.
     */
    public function sendCancelled(string $name, string $email, string $date): bool
    {
        if ('' === $email || Person::isPlaceholderEmail($email) || ! is_email($email)) {
            return false;
        }

        if ('' === $date || $date < (string) current_time('Y-m-d')) {
            return false;
        }

        $mail = $this->composeCancelled($name, $date);
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
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function composeCancelled(string $name, string $date): array
    {
        $short = self::shortDate($date);
        $event = $this->eventName($date);
        $words = static function () use ($name, $event, $short): array {
            $first = DoorDesk::firstName($name);

            return [
                'body' => ('' === $first
                        ? __('Hi,', 'eventcrew')
                        /* translators: %s: the ticket holder's first name */
                        : sprintf(__('Hi %s,', 'eventcrew'), $first))
                    . "\n\n"
                    . sprintf(
                        /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                        __('Your ticket to %1$s on %2$s is cancelled. The QR code in the ticket email no longer works at the door.', 'eventcrew'),
                        $event,
                        $short
                    ),
                'subject' => __('Your ticket is cancelled', 'eventcrew'),
            ];
        };
        $fi = Language::run(Language::FINNISH, $words);
        $en = Language::run(Language::ENGLISH, $words);
        $subject = $fi['subject'] === $en['subject'] ? $en['subject'] : $fi['subject'] . ' / ' . $en['subject'];
        // Each language's text starts with its FI / EN tag, when the two differ.
        $tags = $fi['body'] === $en['body'] ? ['', ''] : ['FI', 'EN'];

        return [
            'subject' => $subject . ' – ' . $short,
            'html' => EmailBody::toHtml($fi['body'], [], EmailTemplate::accent(), $tags[0])
                . '<hr style="border:0;border-top:1px solid #e3e3e8;margin:20px 0">'
                . EmailBody::toHtml($en['body'], [], EmailTemplate::accent(), $tags[1]),
            'text' => $fi['body'] . "\n\n" . $en['body'],
        ];
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
        $event = $this->eventName($row->eventDate);
        $fi = Language::run(Language::FINNISH, fn (): array => $this->words($row, $event, $date));
        $en = Language::run(Language::ENGLISH, fn (): array => $this->words($row, $event, $date));
        $both = static fn (string $key): string => $fi[$key] === $en[$key] ? $en[$key] : $fi[$key] . ' / ' . $en[$key];

        if ($row instanceof CrewTicket) {
            $url = self::crewUrl($row);
        } else {
            $url = $row instanceof Guest ? self::guestUrl($row->id) : self::buyerUrl($row);
        }

        // Only a ticket buyer's code is written out: it is the code the buyer
        // knows from Holvi. A guest's or a crew member's code is in the QR only.
        $code = $row instanceof TicketBuyer ? $row->ticketCode : '';
        $holder = '' === trim($row->name) ? '' : $row->name;

        if ($row instanceof Guest && $row->plusOnes > 0) {
            $holder = trim($holder . ' +' . $row->plusOnes);
        }

        // The site's icon (or logo) in the middle, as on the web ticket.
        $qrHtml = $this->qr->table(self::qrData($row), 6, EmailTemplate::qrMarkUrl());
        // The same ticket note as the web ticket (door code, address), as typed.
        $note = trim((string) get_option(TicketController::NOTE_OPTION, ''));

        $muted = 'font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;color:#6b6b70';
        $ticket = '<div style="margin:24px 0 8px;padding:20px 12px;border:1px solid #d9d9de;border-radius:12px;'
            . 'text-align:center;background:#ffffff">'
            . '<p style="margin:0 0 12px;' . $muted . '">' . esc_html($event . ' · ' . $date) . '</p>'
            . $qrHtml
            . ('' === $code ? '' : sprintf(
                '<p style="margin:12px 0 0;font-family:Consolas,Menlo,monospace;font-size:22px;font-weight:700;'
                    . 'letter-spacing:.08em;color:#1d1d1f">%s</p>',
                esc_html($code)
            ))
            . ('' === $holder ? '' : sprintf(
                '<p style="margin:8px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:17px;color:#1d1d1f">%s</p>',
                esc_html($holder)
            ))
            . ('' === $note ? '' : sprintf(
                '<p style="margin:16px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;'
                    . 'color:#1d1d1f;white-space:pre-line">%s</p>',
                esc_html($note)
            ))
            . sprintf(
                '<p style="margin:16px 0 0;%1$s"><a href="%2$s" style="color:#6b6b70">%3$s</a></p>',
                $muted,
                esc_url($url),
                esc_html($both('open'))
            )
            . '</div>';

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
     * The name of the event on $date: its first task's event, or the site's
     * name when the night has no tasks.
     */
    private function eventName(string $date): string
    {
        foreach ($this->tasks->forDate($date) as $task) {
            $name = trim($task->eventName());

            if ('' !== $name) {
                return $name;
            }
        }

        return (string) get_bloginfo('name');
    }

    /** "14.11.2026": the date as written in Finnish and read in English. */
    public static function shortDate(string $ymd): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return false === $day ? $ymd : $day->format('j.n.Y');
    }
}
