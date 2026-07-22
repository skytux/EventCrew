<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The loopback-free cron fallback for hosts where WP-Cron never fires.
 *
 * WP-Cron spawns its work with a loopback HTTP request the site makes to itself,
 * which the target shared host (InfinityFree) blocks - so the scheduled sends
 * would never run. With this switched on, an ordinary front-end or admin request
 * runs any due sends inline instead, no self-request involved. The bot webhook
 * is an ordinary request too, so on an active install the board being used is
 * enough to keep the heartbeat going.
 *
 * It is off by default and enabled in Settings, because a healthy host (or an
 * external webcron pinging wp-cron.php) needs neither the extra work nor the
 * small latency it adds to whichever request happens to trip it.
 */
final class CronFallbackTrigger
{
    /** Opt-in: only an install that needs it should pay for it. */
    public const OPTION = 'eventcrew_cron_fallback';

    /** Guards against two overlapping requests both running the batch. */
    private const LOCK = 'eventcrew_cron_fallback_lock';
    private const LOCK_TTL = 120;

    public function __construct(
        private readonly Scheduler $scheduler
    ) {
    }

    public function boot(): void
    {
        if (! (bool) get_option(self::OPTION, false)) {
            return;
        }

        // Late on init, so it never delays the response's first byte and a
        // genuine WP-Cron run has already had its chance this request.
        add_action('init', [$this, 'maybeRun'], 99);
    }

    /**
     * Runs the due batch under a short lock, so a burst of requests arriving at
     * once (a webhook flurry) doesn't start several overlapping runs.
     */
    public function maybeRun(): void
    {
        if (! $this->scheduler->isDue()) {
            return;
        }

        if (false !== get_transient(self::LOCK)) {
            return;
        }

        set_transient(self::LOCK, 1, self::LOCK_TTL);

        try {
            $this->scheduler->runIfDue();
        } finally {
            delete_transient(self::LOCK);
        }
    }
}
