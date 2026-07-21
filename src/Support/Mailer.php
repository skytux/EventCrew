<?php

declare(strict_types=1);

namespace EventCrew\Support;

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
