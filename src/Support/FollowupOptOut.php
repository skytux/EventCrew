<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\NewsletterSubscriberRepository;

/**
 * The do-not-send list of the post-event email (FollowupMail): the addresses
 * whose owner pressed "Don't send me these" in one. The email is not sent to
 * an address on the list, nor to an address that left the next events
 * announcement (its subscriber row is unsubscribed).
 *
 * The addresses are stored the way the plugin stores every other email
 * address: in plain text, trimmed and in lower case
 * (NewsletterSubscriberRepository::normalize()), each with the time it was
 * added, in one option that is not autoloaded.
 */
final class FollowupOptOut
{
    /** address => when it was added (site time, Y-m-d H:i:s). */
    public const OPTION = 'eventcrew_followup_optout';

    public function __construct(
        private readonly NewsletterSubscriberRepository $subscribers = new NewsletterSubscriberRepository()
    ) {
    }

    /**
     * Puts $email on the list. Returns false only when the address is empty
     * or the list could not be saved; an address already on it stays as it is.
     */
    public function add(string $email): bool
    {
        $email = NewsletterSubscriberRepository::normalize($email);

        if ('' === $email) {
            return false;
        }

        $list = self::stored();

        if (isset($list[$email])) {
            return true;
        }

        $list[$email] = current_time('mysql');

        return (bool) update_option(self::OPTION, $list, false);
    }

    /**
     * Takes $email off the list. Returns false only when it was not on it or
     * the list could not be saved.
     */
    public function remove(string $email): bool
    {
        $email = NewsletterSubscriberRepository::normalize($email);
        $list = self::stored();

        if ('' === $email || ! isset($list[$email])) {
            return false;
        }

        unset($list[$email]);

        return (bool) update_option(self::OPTION, $list, false);
    }

    /**
     * The list itself, newest first: address => when it was added (site
     * time, Y-m-d H:i:s; '' when not known).
     *
     * @return array<string, string>
     */
    public function entries(): array
    {
        $list = [];

        foreach (self::stored() as $email => $added) {
            $list[(string) $email] = is_string($added) ? $added : '';
        }

        // Newest first; addresses added at the same moment keep their order.
        $order = array_flip(array_keys($list));
        uksort(
            $list,
            static fn (string $a, string $b): int => [$list[$b], $order[$a]] <=> [$list[$a], $order[$b]]
        );

        return $list;
    }

    /** Whether $email is on the list itself (not counting the announcement). */
    public function listed(string $email): bool
    {
        return isset(self::stored()[NewsletterSubscriberRepository::normalize($email)]);
    }

    /**
     * Every address the post-event email is not sent to, in lower case, as
     * keys: the list and the addresses that left the announcement.
     *
     * @return array<string, true>
     */
    public function blocked(): array
    {
        $blocked = array_fill_keys(array_keys(self::stored()), true);

        foreach ($this->subscribers->unsubscribedEmails() as $email) {
            $blocked[NewsletterSubscriberRepository::normalize($email)] = true;
        }

        return $blocked;
    }

    /** @return array<string, string> */
    private static function stored(): array
    {
        $list = get_option(self::OPTION, []);

        return is_array($list) ? $list : [];
    }
}
