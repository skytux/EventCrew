<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Guest;
use EventCrew\Models\TicketBuyer;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;

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
 * The QR code is drawn on the server (TicketQr) and attached to the email as
 * an inline image (a "cid:" reference), because Gmail does not show images
 * written into the HTML as data. Without PHP's GD extension it is drawn as an
 * HTML table instead. The email has no clock: the live clock is on the ticket
 * page the link opens, for a desk that checks tickets by eye.
 *
 * Sending is a WP-Cron event, scheduled by every import that adds rows. Each
 * run sends up to BATCH emails and schedules itself again while rows remain.
 * Each row's ticket_mail_status records what happened: '' (not tried), 'sent'
 * or 'failed'. The automatic run only tries '' rows, so a failed address is
 * not retried by itself; "Send the rest now" tries every row not 'sent'.
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

    /** The content id of the inline QR image. */
    public const QR_CID = 'eventcrew-ticket-qr';

    /** The REST route of the ticket page (Telegram\TicketController). */
    private const PAGE_ROUTE = 'eventcrew/v1/ticket';

    public function __construct(
        private readonly TicketBuyerRepository $buyers,
        private readonly GuestRepository $guests,
        private readonly TaskRepository $tasks,
        private readonly Mailer $mailer,
        private readonly TicketQr $qr = new TicketQr()
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
     * as it does the code in a Holvi ticket; a guest's ticket page link, which
     * the desk reads like a crew member's ticket link.
     */
    public static function qrData(TicketBuyer|Guest $row): string
    {
        return $row instanceof Guest ? self::guestUrl($row->id) : $row->ticketCode;
    }

    /**
     * The email for one ticket: subject, the HTML content that goes into the
     * email template, the plain-text alternative, and the PNG of the QR code
     * that the HTML refers to as cid:QR_CID (null when the code is drawn as a
     * table in the HTML instead).
     *
     * @return array{subject: string, html: string, text: string, png: ?string}
     */
    public function compose(TicketBuyer|Guest $row): array
    {
        $date = self::shortDate($row->eventDate);
        $event = $this->eventName($row->eventDate);
        $fi = Language::run(Language::FINNISH, fn (): array => $this->words($row, $event, $date));
        $en = Language::run(Language::ENGLISH, fn (): array => $this->words($row, $event, $date));
        $both = static fn (string $key): string => $fi[$key] === $en[$key] ? $en[$key] : $fi[$key] . ' / ' . $en[$key];

        $url = $row instanceof Guest ? self::guestUrl($row->id) : self::buyerUrl($row);
        $data = self::qrData($row);
        $png = $this->qr->png($data);
        $code = $row instanceof Guest ? '' : $row->ticketCode;
        $holder = '' === trim($row->name) ? '' : $row->name;

        if ($row instanceof Guest && $row->plusOnes > 0) {
            $holder = trim($holder . ' +' . $row->plusOnes);
        }

        $qrHtml = null === $png
            ? $this->qr->table($data)
            : sprintf(
                '<img src="cid:%1$s" width="240" height="240" alt="%2$s"'
                    . ' style="display:block;margin:0 auto;width:240px;height:240px;border:0">',
                self::QR_CID,
                esc_attr($both('qr'))
            );

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
            . sprintf(
                '<p style="margin:16px 0 0;%1$s"><a href="%2$s" style="color:#6b6b70">%3$s</a></p>',
                $muted,
                esc_url($url),
                esc_html($both('open'))
            )
            . '</div>';

        $html = EmailBody::toHtml($fi['body'])
            . '<hr style="border:0;border-top:1px solid #e3e3e8;margin:20px 0">'
            . EmailBody::toHtml($en['body'])
            . $ticket;

        $text = $fi['body'] . "\n\n" . $en['body'] . "\n\n"
            . ('' === $code ? '' : $both('code') . ': ' . $code . "\n")
            . ('' === $holder ? '' : $holder . "\n")
            . $both('open') . ': ' . $url;

        return [
            'subject' => $both('subject') . ' – ' . $date,
            'html' => $html,
            'text' => $text,
            'png' => $png,
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

    private function deliver(TicketBuyer|Guest $row, string $to): bool
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
            $this->footer(),
            $mail['png'],
            self::QR_CID
        );
    }

    /**
     * One language's words for one ticket, composed in the current language.
     *
     * @return array{body: string, subject: string, open: string, code: string, qr: string}
     */
    private function words(TicketBuyer|Guest $row, string $event, string $date): array
    {
        $first = DoorDesk::firstName($row->name);
        $lines = [
            '' === $first
                ? __('Hi,', 'eventcrew')
                /* translators: %s: the ticket holder's first name */
                : sprintf(__('Hi %s,', 'eventcrew'), $first),
            $row instanceof Guest
                ? __('Thank you for coming! You are on the guest list.', 'eventcrew')
                : __('Thank you for buying a ticket!', 'eventcrew'),
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
            'qr' => __('Ticket QR code', 'eventcrew'),
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
