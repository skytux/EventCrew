<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AuthTokenRepository;

/**
 * One place every transactional email goes through, so they all share a footer
 * carrying the manage/disable link. Sending is inline `wp_mail`; a failure is
 * logged rather than thrown, because a missed confirmation must never break the
 * signup that triggered it.
 *
 * Callers hand over plain text plus, optionally, the actions the message is
 * asking for. The text is what the message says; an action is a thing to do -
 * "Show your ticket", "Add to calendar" - and becomes a button in the HTML and a
 * "Label: url" line in the plain-text alternative. Every message goes out as
 * both parts, so a client that will not render HTML still gets the whole thing.
 */
final class Mailer
{
    /** The red of the newsletter preview's "Do Not Send This" button. */
    public const STOP_COLOR = EmailBody::DANGER_COLOR;

    /** The address of every link a preview shows in place of a sign-in or other one-time link. */
    public const PREVIEW_LINK = '#preview';

    /**
     * What a preview Mailer would have sent, in order: to, subject, message,
     * headers and the plain-text part.
     *
     * @var list<array{to: string, subject: string, message: string, headers: list<string>, text: string}>
     */
    private array $captured = [];

    /**
     * $preview (the message preview in wp-admin, MessagePreview) sends
     * nothing: each mail is kept for captured() instead of going to wp_mail,
     * the footer's sign-in link is PREVIEW_LINK (no token is made), and a
     * ".invalid" sample address is not refused.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly EmailTemplate $template,
        private readonly bool $preview = false
    ) {
    }

    /**
     * The mails a preview Mailer kept, in order.
     *
     * @return list<array{to: string, subject: string, message: string, headers: list<string>, text: string}>
     */
    public function captured(): array
    {
        return $this->captured;
    }

    /**
     * Sends a mail to a person, appending the manage-account footer so every
     * message carries a one-click way out.
     *
     * @param array<int, array{label: string, url: string}> $actions Buttons,
     *        most important first; the first is drawn as the primary one.
     */
    public function toPerson(int $personId, string $to, string $subject, string $body, array $actions = []): bool
    {
        // A person added without an email (or erased) has a ".invalid"
        // placeholder address, which can never receive mail. Nothing is sent.
        if (! $this->preview && Person::isPlaceholderEmail($to)) {
            return false;
        }

        $manageUrl = $this->manageUrl($personId);
        $text = EmailBody::toText($body, $actions) . $this->signatureText() . "\n\n" . $this->footerText($manageUrl);

        $sent = EmailTemplate::enabled()
            ? $this->sendHtml($to, $subject, $body, $actions, $manageUrl, $text)
            : $this->mail($to, $subject, $text, [], $text);

        if (! $sent) {
            $this->logger->warning(sprintf('Email "%s" to %s could not be sent.', $subject, $to));
        }

        return $sent;
    }

    /**
     * A mail to an address nobody has confirmed yet, such as the new address in
     * an email change. Unlike toPerson() the footer carries no sign-in link:
     * a mistyped address would otherwise hand a stranger a way into the
     * account. The footer points at the public page instead.
     *
     * @param array<int, array{label: string, url: string}> $actions
     */
    public function toUnconfirmedAddress(string $to, string $subject, string $body, array $actions = []): bool
    {
        $board = $this->boardUrl();
        $text = EmailBody::toText($body, $actions) . $this->signatureText() . "\n\n" . $this->footerText($board);

        $sent = EmailTemplate::enabled()
            ? $this->sendHtml($to, $subject, $body, $actions, $board, $text)
            : $this->mail($to, $subject, $text, [], $text);

        if (! $sent) {
            $this->logger->warning(sprintf('Email "%s" to %s could not be sent.', $subject, $to));
        }

        return $sent;
    }

    /**
     * A ticket email to a ticket buyer or a guest (TicketMail). They have no
     * account, so the footer is $footer, a line saying why they get it, linked
     * to the public page. Always sent as HTML, whatever the template setting,
     * because the ticket's QR code is drawn in the HTML.
     *
     * $content is ready HTML for the template's content slot. The plain-text
     * part is added on phpmailer_init.
     *
     * A mail plugin can throw from inside wp_mail. That is caught, logged
     * without the address, and answered as not sent, so a batch of tickets
     * goes on with the next one.
     *
     * $lightOnly keeps the email in light colours, so a QR code stays black
     * on white. The post-event email (FollowupMail) has no QR code and
     * passes false, so it follows the mail app's own colours, dark mode
     * too, like the announcement (Lou, 2026-11-03).
     *
     * $corner is HTML put under the template's sign-off, above the footer
     * line: the ticket email's corner with the holder's name and the small
     * QR code (TicketMail::compose()).
     *
     * $headers are added to the mail's headers (the post-event email's
     * List-Unsubscribe).
     *
     * $languages are the email's languages, for the sign-off; empty for
     * every site language.
     *
     * @param array<int, string> $headers
     * @param list<string> $languages
     */
    public function toTicketHolder(
        string $to,
        string $subject,
        string $content,
        string $text,
        string $footer,
        bool $lightOnly = true,
        string $corner = '',
        array $headers = [],
        array $languages = []
    ): bool {
        $board = $this->boardUrl();
        // The closing shows the sign-off in each of the email's languages.
        $signature = Signature::bothText($languages);
        $text .= ('' === $signature ? '' : "\n\n" . $signature) . "\n\n—\n" . $footer
            . ('' === $board ? '' : ': ' . $board);
        // $corner (HTML, the ticket email's QR corner) goes under the sign-off,
        // above the footer line.
        $html = $this->template->render(
            $subject,
            $content,
            $corner . EmailBody::footerHtml($footer, $board),
            $lightOnly,
            null,
            '',
            [] === $languages ? true : $languages
        );

        $parts = static function ($phpmailer) use ($text): void {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $parts);

        try {
            $sent = $this->mail(
                $to,
                $subject,
                $html,
                array_merge(['Content-Type: text/html; charset=UTF-8'], $headers),
                $text
            );
        } catch (\Throwable $error) {
            $this->logger->error(sprintf(
                'Email "%s" to a ticket holder failed: %s: %s',
                $subject,
                get_class($error),
                (string) preg_replace('/\S+@\S+/', '[address]', $error->getMessage())
            ));

            return false;
        } finally {
            remove_action('phpmailer_init', $parts);
        }

        if (! $sent) {
            $this->logger->warning(sprintf('Email "%s" to a ticket holder could not be sent.', $subject));
        }

        return $sent;
    }

    /**
     * One newsletter to one subscriber: $content is the ready HTML of both
     * languages and $text the same as plain text. The footer is $footer
     * linked to $footerUrl (the subscriber's own unsubscribe link), and
     * $headers are added to the mail's headers (List-Unsubscribe).
     * $footerNote, when not empty, is a plain line just above that link.
     *
     * @param array<int, string> $headers
     */
    public function toSubscriber(
        string $to,
        string $subject,
        string $content,
        string $text,
        string $footer,
        string $footerUrl,
        array $headers = [],
        string $footerNote = ''
    ): bool {
        $text = $this->subscriberText($text, $footer, $footerUrl, $footerNote);
        $html = $this->subscriberHtml($subject, $content, $footer, $footerUrl, $footerNote);

        $parts = static function ($phpmailer) use ($text): void {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $parts);

        try {
            $sent = $this->mail(
                $to,
                $subject,
                $html,
                array_merge(['Content-Type: text/html; charset=UTF-8'], $headers),
                $text
            );
        } catch (\Throwable $error) {
            $this->logger->error(sprintf(
                'Email "%s" to a newsletter subscriber failed: %s: %s',
                $subject,
                get_class($error),
                (string) preg_replace('/\S+@\S+/', '[address]', $error->getMessage())
            ));

            return false;
        } finally {
            remove_action('phpmailer_init', $parts);
        }

        if (! $sent) {
            $this->logger->warning(sprintf('Email "%s" to a newsletter subscriber could not be sent.', $subject));
        }

        return $sent;
    }

    /**
     * The HTML of one newsletter email exactly as toSubscriber() sends it.
     * The newsletter also keeps a copy made with this for the Newsletter page
     * in wp-admin. $now and $after are EmailTemplate::render()'s: the moment
     * the email is for (null: now) and HTML under the whole email.
     */
    public function subscriberHtml(
        string $subject,
        string $content,
        string $footer,
        string $footerUrl,
        string $footerNote = '',
        ?\DateTimeInterface $now = null,
        string $after = ''
    ): string {
        return $this->template->render(
            $subject,
            $content,
            ('' === $footerNote ? '' : EmailBody::footerHtml($footerNote, ''))
                . EmailBody::footerHtml($footer, $footerUrl),
            false,
            $now,
            $after
        );
    }

    /**
     * The newsletter preview to one organizer: the email a subscriber gets
     * (subscriberHtml(), the same plain text as toSubscriber()), with $stop
     * as a red button at the very bottom and $line, one plain line, under
     * it. $now is the moment the email is shown as (the send time).
     * $headers are added to the mail's headers: the List-Unsubscribe headers
     * a subscriber gets, with a sample link that unsubscribes nobody.
     *
     * @param array{label: string, url: string} $stop
     * @param array<int, string> $headers
     */
    public function toOrganizerAsSubscriber(
        string $to,
        string $subject,
        string $content,
        string $text,
        string $footer,
        string $footerUrl,
        string $footerNote,
        array $stop,
        string $line,
        ?\DateTimeInterface $now = null,
        array $headers = []
    ): bool {
        if (! $this->preview && Person::isPlaceholderEmail($to)) {
            return false;
        }

        $after = sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0"'
                . ' bgcolor="' . EmailTemplate::NIGHT . '" style="background:' . EmailTemplate::NIGHT
                . ';margin:0;padding:0 12px 32px"><tr><td align="center">%s'
                . '<p style="margin:0;font-family:%s;font-size:14px;line-height:1.5;color:%s">%s</p>'
                . '</td></tr></table>',
            EmailBody::centred(EmailBody::toHtml('', [$stop], self::STOP_COLOR)),
            EmailBody::FONT,
            '#ffe2b8',
            esc_html($line)
        );
        $html = $this->subscriberHtml($subject, $content, $footer, $footerUrl, $footerNote, $now, $after);
        $text = $this->subscriberText($text, $footer, $footerUrl, $footerNote)
            . "\n\n————————\n\n" . ltrim(EmailBody::toText('', [$stop])) . "\n" . $line;

        $altBody = static function ($phpmailer) use ($text): void {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $altBody);

        try {
            $sent = $this->mail(
                $to,
                $subject,
                $html,
                array_merge(['Content-Type: text/html; charset=UTF-8'], $headers),
                $text
            );
        } catch (\Throwable $error) {
            $this->logger->error(sprintf(
                'Email "%s" to an organizer failed: %s: %s',
                $subject,
                get_class($error),
                (string) preg_replace('/\S+@\S+/', '[address]', $error->getMessage())
            ));

            return false;
        } finally {
            remove_action('phpmailer_init', $altBody);
        }

        if (! $sent) {
            $this->logger->warning(sprintf('Email "%s" to %s could not be sent.', $subject, $to));
        }

        return $sent;
    }

    /** The plain-text part of one newsletter email, as toSubscriber() sends it. */
    private function subscriberText(string $text, string $footer, string $footerUrl, string $footerNote): string
    {
        return $text . $this->signatureText() . "\n\n—\n"
            . ('' === $footerNote ? '' : $footerNote . "\n")
            . $footer . ('' === $footerUrl ? '' : ': ' . $footerUrl);
    }

    /**
     * A mail to a person that shows a ready-made email inside it: $intro (EmailBody
     * text) on top, then $content (ready HTML) between two lines, then the
     * $actions buttons, then the manage-account footer. $contentText is the
     * same content as plain text. Used for the announcement preview, so the
     * organizers see the announcement exactly as subscribers will.
     *
     * Always sent as HTML, whatever the template setting, because the content
     * is HTML.
     *
     * @param array<int, array{label: string, url: string}> $actions
     */
    public function toPersonWithContent(
        int $personId,
        string $to,
        string $subject,
        string $intro,
        string $content,
        string $contentText,
        array $actions = []
    ): bool {
        if (! $this->preview && Person::isPlaceholderEmail($to)) {
            return false;
        }

        $manageUrl = $this->manageUrl($personId);
        $accent = EmailTemplate::accent();
        $rule = '<hr style="border:0;border-top:1px solid #e3e3e8;margin:24px 0">';
        $textRule = "\n\n————————\n\n";

        $body = EmailBody::toHtml($intro, [], $accent) . $rule . $content . $rule
            . EmailBody::centred(EmailBody::toHtml('', $actions, $accent));
        $html = $this->template->render(
            $subject,
            $body,
            EmailBody::footerHtml($this->footerLabel(), $manageUrl)
        );
        $text = EmailBody::toText($intro) . $textRule . $contentText . $textRule
            . EmailBody::toText('', $actions) . $this->signatureText() . "\n\n" . $this->footerText($manageUrl);

        $altBody = static function ($phpmailer) use ($text): void {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $altBody);

        try {
            $sent = $this->mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8'], $text);
        } finally {
            remove_action('phpmailer_init', $altBody);
        }

        if (! $sent) {
            $this->logger->warning(sprintf('Email "%s" to %s could not be sent.', $subject, $to));
        }

        return $sent;
    }

    /**
     * A sample of the real thing, for the Settings button that proves the
     * template and the site's mail setup work before anyone relies on them.
     */
    public function sendTest(string $to): bool
    {
        $subject = __('EventCrew test email', 'eventcrew');
        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        $body = __(
            "Hi there,\n\nThis is what your crew's notifications look like. If the logo, the button and the footer below all look right, everything is set up.\n\nThe wording of each real message comes from EventCrew itself — this template only wraps it.",
            'eventcrew'
        );
        // phpcs:enable Generic.Files.LineLength.TooLong

        // No token in a test: it is not addressed to a crew member, so there is
        // nobody to sign in as. The button points at the public page instead.
        $board = $this->boardUrl();
        $actions = '' === $board
            ? []
            : [['label' => __('Open the signup page', 'eventcrew'), 'url' => $board]];

        $text = EmailBody::toText($body, $actions) . $this->signatureText() . "\n\n" . $this->footerText($board);

        return $this->sendHtml($to, $subject, $body, $actions, $board, $text);
    }

    /**
     * @param array<int, array{label: string, url: string}> $actions
     */
    private function sendHtml(
        string $to,
        string $subject,
        string $body,
        array $actions,
        string $manageUrl,
        string $text
    ): bool {
        $html = $this->template->render(
            $subject,
            EmailBody::toHtml($body, $actions, EmailTemplate::accent()),
            EmailBody::footerHtml($this->footerLabel(), $manageUrl)
        );

        // The plain-text half of the multipart message. PHPMailer only offers
        // this through its own object, so it is set on the instance wp_mail is
        // about to use and the filter is removed straight after - leaving it
        // attached would put this body on every other plugin's mail too.
        //
        // Best-effort by nature: an install whose mail leaves through a relay
        // or an API-based SMTP plugin never reaches PHPMailer, and the message
        // simply goes out as HTML alone. That is why nothing the reader must
        // see is allowed to live only here - see EmailTemplate's note on why
        // the logo is linked rather than attached on this same hook.
        $altBody = static function ($phpmailer) use ($text): void {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $altBody);

        $sent = $this->mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8'], $text);

        remove_action('phpmailer_init', $altBody);

        return $sent;
    }

    public function manageUrl(int $personId): string
    {
        if ($this->preview) {
            return self::PREVIEW_LINK;
        }

        // The option name mirrors Web\PwaController::PAGE_OPTION, kept literal so
        // this low-level Support class does not reach up into the Web layer.
        $pageId = (int) get_option('eventcrew_signup_page_id', 0);
        $pageUrl = $pageId > 0 ? get_permalink($pageId) : false;

        if (is_string($pageUrl) && '' !== $pageUrl) {
            // A single-use, 30-minute sign-in link - the same kind of token the
            // login form mails - landing on the person's own profile page, where
            // pausing email and deleting data live. One-time and short-lived so a
            // forwarded or leaked old email can't be replayed as a login.
            // Purpose/TTL mirror SignupController::LOGIN_PURPOSE / LOGIN_TTL; the
            // param is consumed there on template_redirect.
            $token = (new AuthTokenRepository())->issue($personId, 'web_login', 30 * MINUTE_IN_SECONDS);

            return add_query_arg(['eventcrew_login' => $token], $pageUrl);
        }

        // No public page (a Telegram-only install): fall back to the standalone
        // self-service page over REST. It only ever offers pause/delete, never a
        // session, so its stateless reusable token stays appropriately narrow.
        return add_query_arg(
            ['token' => SignedLink::sign('manage', $personId)],
            rest_url('eventcrew/v1/manage')
        );
    }

    /**
     * wp_mail(), or for a preview Mailer the mail kept for captured().
     * $text is the plain-text part, kept with a preview only.
     *
     * @param list<string> $headers
     */
    private function mail(string $to, string $subject, string $message, array $headers, string $text): bool
    {
        if ($this->preview) {
            $this->captured[] = [
                'to' => $to,
                'subject' => $subject,
                'message' => $message,
                'headers' => $headers,
                'text' => $text,
            ];

            return true;
        }

        return [] === $headers
            ? (bool) wp_mail($to, $subject, $message)
            : (bool) wp_mail($to, $subject, $message, $headers);
    }

    public function ticketUrl(int $assignmentId): string
    {
        return add_query_arg(
            ['token' => SignedLink::sign('ticket', $assignmentId)],
            rest_url('eventcrew/v1/ticket')
        );
    }

    /**
     * The public signup page, for the buttons that just say "here are the open
     * tasks". Empty on a Telegram-only install, and a button with no URL is
     * dropped rather than drawn, so those emails simply have one button fewer.
     */
    public function boardUrl(): string
    {
        $pageId = (int) get_option('eventcrew_signup_page_id', 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;

        return is_string($url) ? $url : '';
    }

    /**
     * The sign-off for the plain-text half. The HTML half gets it from the
     * template's {{signature}} slot instead, so it can be moved about.
     */
    private function signatureText(): string
    {
        return Signature::isSet() ? "\n\n" . Signature::text() : '';
    }

    private function footerLabel(): string
    {
        return __('Manage your account or stop these emails', 'eventcrew');
    }

    private function footerText(string $manageUrl): string
    {
        return sprintf(
            /* translators: %s: the manage-account link */
            __("—\nManage your account or stop these emails: %s", 'eventcrew'),
            $manageUrl
        );
    }
}
