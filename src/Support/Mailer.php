<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\AuthTokenRepository;

/**
 * One place every transactional email goes through, so they all share a footer
 * carrying the manage/disable link. Sending is inline `wp_mail`; a failure is
 * logged rather than thrown, because a missed confirmation must never break the
 * signup that triggered it.
 */
final class Mailer
{
    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * Sends a mail to a person, appending the manage-account footer so every
     * message carries a one-click way out.
     */
    public function toPerson(int $personId, string $to, string $subject, string $body): bool
    {
        $full = $body . "\n\n" . $this->footer($personId);
        $sent = (bool) wp_mail($to, $subject, $full);

        if (! $sent) {
            $this->logger->warning(sprintf('Email "%s" to %s could not be sent.', $subject, $to));
        }

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

    private function footer(int $personId): string
    {
        return sprintf(
            /* translators: %s: the manage-account link */
            __("—\nManage your account or stop these emails: %s", 'eventcrew'),
            $this->manageUrl($personId)
        );
    }
}
