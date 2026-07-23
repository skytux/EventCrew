<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The heartbeat: one hourly WP-Cron event that runs the scheduled sends - the
 * task reminders and the automated open-task call - in bounded batches.
 *
 * The schedule self-heals on boot rather than relying on the activation hook,
 * which WordPress skips when a plugin is updated by the file-drop or the
 * updater, so a new version can't leave the site with no heartbeat. The same
 * run() is what the cron fallback calls on an ordinary request, so there is one
 * job, reachable two ways.
 */
final class Scheduler
{
    /** The cron hook, also cleared on deactivation from the main plugin file. */
    public const HOOK = 'eventcrew_run_notifications';

    public const LAST_RUN_OPTION = 'eventcrew_cron_last_run';

    /** How far ahead of an event the open-task call goes out. */
    private const OPEN_TASK_LEAD_HOURS = 48;

    /** Most sends per run, so a shared-host request can't time out mid-batch. */
    private const BATCH = 25;

    /** The fallback only re-runs once the last run is this old. */
    private const DUE_AFTER_SECONDS = HOUR_IN_SECONDS;

    public function __construct(
        private readonly ReminderCall $reminders,
        private readonly OpenTaskCall $openTasks,
        private readonly StandingNotice $standingNotices,
        private readonly BoardPush $boardPush
    ) {
    }

    /**
     * Registers the run action and makes sure the hourly event exists.
     */
    public function boot(): void
    {
        add_action(self::HOOK, [$this, 'run']);

        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    /**
     * The batched run itself - the WP-Cron callback. Reminders first (time
     * critical), then the open-task call, each capped; the ledger and
     * reminded_at guarantee the next run resumes rather than repeats.
     */
    public function run(): void
    {
        $this->reminders->run(self::BATCH);
        $this->openTasks->sendDue(self::OPEN_TASK_LEAD_HOURS, self::BATCH);
        $this->standingNotices->sendDue(self::BATCH);
        $this->boardPush->run();

        update_option(self::LAST_RUN_OPTION, time());
    }

    /**
     * Runs only when the last run is over an hour old. This is the throttle the
     * inline fallback leans on; real WP-Cron is already hourly and calls run()
     * directly. Returns whether it ran.
     */
    public function runIfDue(): bool
    {
        if (! $this->isDue()) {
            return false;
        }

        $this->run();

        return true;
    }

    public function isDue(): bool
    {
        $last = (int) get_option(self::LAST_RUN_OPTION, 0);

        return (time() - $last) >= self::DUE_AFTER_SECONDS;
    }

    /**
     * Clears the scheduled event. Called from the plugin's deactivation hook.
     */
    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }
}
