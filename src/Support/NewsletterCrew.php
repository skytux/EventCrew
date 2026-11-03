<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;

/**
 * Puts crew members on the next events announcement (the newsletter) by
 * default. A crew member is every person with a verified email address,
 * organizers included.
 *
 * - Existing crew: backfill() adds them once per site. The first request
 *   after the update runs it (maybeRun()), and an option records that it ran.
 * - New crew: when a person's email is verified, onEmailVerified() adds them.
 *
 * Both add the address as a confirmed subscriber and send no email. An
 * address that already has a subscriber row in any state is skipped, so a
 * crew member who left the newsletter is never put back. Leaving the
 * newsletter changes nothing else: task reminders, ticket emails and Telegram
 * messages are separate.
 *
 * The setting (OPTION, on by default) switches both off. While it is off the
 * backfill does not run and is not marked as done, so it runs when the
 * setting is switched on again.
 */
final class NewsletterCrew
{
    /** '1' (the default) adds crew members to the newsletter; '0' does not. */
    public const OPTION = 'eventcrew_newsletter_crew';

    /** Set to '1' once the backfill has run on this site. */
    public const DONE_OPTION = 'eventcrew_newsletter_crew_backfill_done';

    public function __construct(
        private readonly NewsletterSubscriberRepository $subscribers,
        private readonly PersonRepository $people
    ) {
    }

    public function boot(): void
    {
        add_action('eventcrew/email_verified', [$this, 'onEmailVerified']);
    }

    public static function enabled(): bool
    {
        return '1' === (string) get_option(self::OPTION, '1');
    }

    /** Runs the backfill once per site, while the setting is on. */
    public function maybeRun(): void
    {
        if ('1' === (string) get_option(self::DONE_OPTION, '') || ! self::enabled()) {
            return;
        }

        $this->backfill();
        update_option(self::DONE_OPTION, '1');
    }

    /**
     * Adds every person with a verified email who has no subscriber row yet.
     * Returns how many were added.
     */
    public function backfill(): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $added = 0;

        foreach ($this->people->activeEmailRecipients() as $person) {
            if ($this->add($person)) {
                ++$added;
            }
        }

        return $added;
    }

    /**
     * A confirmed address: add the person, unless they already have a row.
     * Fires on every web sign-in too; the existing row makes those no-ops.
     */
    public function onEmailVerified(int $personId): void
    {
        if (! self::enabled()) {
            return;
        }

        $person = $this->people->find($personId);

        if (null !== $person) {
            $this->add($person);
        }
    }

    private function add(Person $person): bool
    {
        if (null === $person->emailVerifiedAt || ! $person->hasRealEmail()) {
            return false;
        }

        return $this->subscribers->addCrewMember($person->email);
    }
}
