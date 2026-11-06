<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Reads an event's venue from its description with the language model, when
 * the setting "Read the venue from the event description with AI" is on
 * (Settings > Web page; off by default).
 *
 * On the hourly heartbeat (Scheduler::HOOK, in the background only, when the
 * model is set up), each coming event that has no own venue (EventVenue) and
 * a description is asked about once (NewsletterWriter::venueOf(), the same
 * request and checks as the newsletter's practical information). A venue it
 * finds is saved as the event's own venue and kept in SUGGESTED_META, so the
 * Coming events tab says it is the AI's suggestion; the organizers can change
 * or clear it there, and it is not asked again. A failed request is asked
 * again on later runs, at most MAX_TRIES times. Nothing asks the model while
 * a message is sent.
 */
final class VenueReader
{
    /** '1' when the venue is read from the description (Settings > Web page). */
    public const OPTION = 'eventcrew_venue_ai';

    /** The venue the model read for the event, on the EventMesh event post. */
    public const SUGGESTED_META = '_eventcrew_venue_ai';

    /** 'done' once the event was asked about, else the failed tries so far. */
    public const ASKED_META = '_eventcrew_venue_ai_asked';

    public const MAX_TRIES = 3;

    /** The most events asked about in one run. */
    public const MAX_PER_RUN = 5;

    /** Seconds one run may spend asking. */
    public const BUDGET = 60;

    private const DONE = 'done';

    public function __construct(private readonly NewsletterWriter $writer)
    {
    }

    public function boot(): void
    {
        // After the heartbeat's own work and the AI texts (EventAiText, 20).
        add_action(Scheduler::HOOK, [$this, 'run'], 30);
    }

    public static function isOn(): bool
    {
        return '1' === (string) get_option(self::OPTION, '0');
    }

    public static function save(bool $on): void
    {
        update_option(self::OPTION, $on ? '1' : '0');
    }

    /**
     * Whether event $postId's own venue is the one the model suggested (no
     * one changed it since).
     */
    public static function isSuggested(int $postId): bool
    {
        $own = EventVenue::own($postId);

        return '' !== $own && $own === EventVenue::clean((string) get_post_meta($postId, self::SUGGESTED_META, true));
    }

    /**
     * Whether event $postId can be read again ("Read again" on the Coming
     * events tab): the setting is on and the event has no own venue, or only
     * the model's suggestion. A venue set by hand is never replaced.
     */
    public static function canReadAgain(int $postId): bool
    {
        return self::isOn() && ('' === EventVenue::own($postId) || self::isSuggested($postId));
    }

    /**
     * Has event $postId asked about again on the next heartbeat: removes the
     * model's suggestion and the mark that it was asked. Returns false, and
     * changes nothing, when canReadAgain() is false.
     */
    public static function readAgain(int $postId): bool
    {
        if (! self::canReadAgain($postId)) {
            return false;
        }

        if (self::isSuggested($postId)) {
            EventVenue::saveOwn($postId, '');
        }

        delete_post_meta($postId, self::SUGGESTED_META);
        delete_post_meta($postId, self::ASKED_META);

        return true;
    }

    /** The heartbeat: asks about the coming events that need it. Returns how many were asked. */
    public function run(): int
    {
        if (! self::isOn() || ! AssistantSettings::isConfigured() || ! self::inBackground()) {
            return 0;
        }

        $today = (string) current_time('Y-m-d');
        $started = microtime(true);
        $asked = 0;

        foreach (EventSource::upcoming() as $event) {
            if ($asked >= self::MAX_PER_RUN || microtime(true) - $started >= self::BUDGET) {
                break;
            }

            if ('' === $event['date'] || $event['date'] < $today || ! self::needs($event['id'])) {
                continue;
            }

            $this->read($event['id']);
            ++$asked;
        }

        return $asked;
    }

    /**
     * Whether event $postId is asked about: no own venue, not asked yet (or
     * failed fewer than MAX_TRIES times) and a description to read.
     */
    private static function needs(int $postId): bool
    {
        if ('' !== EventVenue::own($postId)) {
            return false;
        }

        $asked = (string) get_post_meta($postId, self::ASKED_META, true);

        if (self::DONE === $asked || (int) $asked >= self::MAX_TRIES) {
            return false;
        }

        $card = EventSource::card($postId);

        return '' !== trim($card['excerpt_fi'] . $card['excerpt_en'] . $card['excerpt']);
    }

    /** Asks about event $postId once and saves what it finds. */
    private function read(int $postId): void
    {
        $venue = $this->writer->venueOf($postId);

        if (null === $venue) {
            $tries = (int) get_post_meta($postId, self::ASKED_META, true);
            update_post_meta($postId, self::ASKED_META, (string) ($tries + 1));

            return;
        }

        update_post_meta($postId, self::ASKED_META, self::DONE);
        $venue = EventVenue::clean($venue);

        // Someone may have set a venue while the model was asked.
        if ('' !== $venue && '' === EventVenue::own($postId)) {
            EventVenue::saveOwn($postId, $venue);
            update_post_meta($postId, self::SUGGESTED_META, $venue);
        }
    }

    /** Whether this run is in the background: WP-Cron or WP-CLI, not a visitor's request. */
    private static function inBackground(): bool
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return function_exists('wp_doing_cron') && wp_doing_cron();
    }
}
