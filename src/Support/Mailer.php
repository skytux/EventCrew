<?php

declare(strict_types=1);

namespace EventCrew\Support;

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
    public function __construct(
        private readonly Logger $logger,
        private readonly EmailTemplate $template
    ) {
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
        $manageUrl = $this->manageUrl($personId);
        $text = EmailBody::toText($body, $actions) . "\n\n" . $this->footerText($manageUrl);

        $sent = EmailTemplate::enabled()
            ? $this->sendHtml($to, $subject, $body, $actions, $manageUrl, $text)
            : (bool) wp_mail($to, $subject, $text);

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
        $body = __(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            "Hi there,\n\nThis is what your crew's notifications look like. If the logo, the button and the footer below all look right, everything is set up.\n\nThe wording of each real message comes from EventCrew itself — this template only wraps it.",
            'eventcrew'
        );

        // No token in a test: it is not addressed to a crew member, so there is
        // nobody to sign in as. The button points at the public page instead.
        $board = $this->boardUrl();
        $actions = '' === $board
            ? []
            : [['label' => __('Open the signup page', 'eventcrew'), 'url' => $board]];

        $text = EmailBody::toText($body, $actions) . "\n\n" . $this->footerText($board);

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

        $sent = (bool) wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);

        remove_action('phpmailer_init', $altBody);

        return $sent;
    }

    public function manageUrl(int $personId): string
    {
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
