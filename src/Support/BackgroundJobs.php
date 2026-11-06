<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;
use Throwable;

/**
 * The slow part of a wp-admin save, done after the page has answered: a
 * translation by the language model, an AI text, a call to Telegram.
 *
 * A save stores what it can at once and adds a job (enqueue()); the page
 * redirects without waiting. A job is a kind (what runs it: handle()), its
 * arguments, a target (what it changes, like "row:message.welcome.body") and
 * a status: queued, running, done or failed. Two runners take jobs:
 * - the wp-admin page itself, while it shows a job that is not done
 *   (EventCrew\Admin\JobStatus asks for one job at a time through
 *   admin-ajax), and
 * - WP-Cron, every minute (on the live site, every time the external pinger
 *   opens wp-cron.php), for up to RUN_SECONDS each run (drain()).
 *
 * Every job list change is made under one MySQL lock, so two requests never
 * take the same job (claim()) or lose each other's changes. A second job for
 * the same target replaces the one that was waiting, so the latest input is
 * the one used. A job still running after STUCK_SECONDS (its request died)
 * is queued once more, then failed. Finished jobs are kept for a day, at
 * most KEEP_FINISHED of them, so the page can show what happened.
 *
 * The jobs live in one option that WordPress does not load on every page.
 */
final class BackgroundJobs
{
    public const OPTION = 'eventcrew_background_jobs';

    /** The WP-Cron hook that runs the queue. */
    public const HOOK = 'eventcrew_background_jobs';

    /** The WP-Cron schedule of HOOK. */
    public const SCHEDULE = 'eventcrew_every_minute';

    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** A job running longer than this is taken as dead. */
    public const STUCK_SECONDS = 300;

    /** How many times a job is started before a dead run fails it. */
    public const MAX_ATTEMPTS = 2;

    /** Finished jobs kept, newest first. */
    public const KEEP_FINISHED = 50;

    /** Seconds a finished job is kept. */
    public const KEEP_SECONDS = DAY_IN_SECONDS;

    /** Seconds one WP-Cron run spends on jobs. */
    public const RUN_SECONDS = 20;

    /** A job is started only while at least this many seconds of the run are left. */
    public const MIN_JOB_SECONDS = 5;

    /** The MySQL lock name around every change of the job list. */
    private const LOCK = 'eventcrew_background_jobs';

    /** Seconds to wait for the lock. */
    private const LOCK_WAIT = 5;

    /** @var array<string, callable(array<string, mixed>): array<string, mixed>> */
    private array $handlers = [];

    /** How many jobs this request added. */
    private static int $added = 0;

    private readonly Closure $clock;

    /**
     * @param (Closure(): float)|null $clock Seconds now, for the time budget
     *        of drain(); microtime() when null. Tests pass their own.
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function boot(): void
    {
        add_filter('cron_schedules', [self::class, 'addSchedule']);
        add_action(self::HOOK, [$this, 'drain']);

        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), self::SCHEDULE, self::HOOK);
        }
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function addSchedule(array $schedules): array
    {
        // Asked for before init too, when translating would make WordPress
        // warn; only the interval matters for scheduling.
        $schedules[self::SCHEDULE] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => 'Every minute (EventCrew background jobs)',
        ];

        return $schedules;
    }

    /**
     * Sets what runs the jobs of $kind. The handler gets the job's arguments
     * and answers ok (bool), error (the sentence shown when it failed) and,
     * optionally, args (the arguments for "Try again", when they differ).
     *
     * @param callable(array<string, mixed>): array<string, mixed> $handler
     */
    public function handle(string $kind, callable $handler): void
    {
        $this->handlers[$kind] = $handler;
    }

    /**
     * Adds a job and returns its id. A queued, failed or done job for the
     * same $target is replaced; one that is running stays and finishes
     * first, so the newest input is the last one stored.
     *
     * @param array<string, mixed> $args
     */
    public static function enqueue(string $kind, string $target, array $args, string $label = ''): string
    {
        $id = bin2hex(random_bytes(8));
        $job = [
            'id' => $id,
            'kind' => $kind,
            'target' => $target,
            'label' => $label,
            'args' => $args,
            'status' => self::QUEUED,
            'error' => '',
            'attempts' => 0,
            'created_at' => time(),
            'started_at' => 0,
            'finished_at' => 0,
        ];

        self::change(static function (array $jobs) use ($job, $target): array {
            $jobs = array_values(array_filter(
                $jobs,
                static fn (array $old): bool => $old['target'] !== $target || self::RUNNING === $old['status']
            ));
            $jobs[] = $job;

            return $jobs;
        });

        self::$added++;

        return $id;
    }

    /** How many jobs this request added (a save notice says they run in the background). */
    public static function addedNow(): int
    {
        return self::$added;
    }

    /** Forget the jobs counted by addedNow(): for tests, and for a notice that says it itself. */
    public static function resetAdded(): void
    {
        self::$added = 0;
    }

    /** The notice sentence after a save that left work for the background. */
    public static function queuedNotice(): string
    {
        return __('The translations and other slow steps run in the background. This page shows when they are done.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    }

    /**
     * "Try again" for a failed job: it is queued again with the arguments
     * its handler left for that. Returns false when $id is not a failed job.
     */
    public static function retry(string $id): bool
    {
        $found = false;

        self::change(static function (array $jobs) use ($id, &$found): array {
            foreach ($jobs as $index => $job) {
                if ($job['id'] !== $id || self::FAILED !== $job['status']) {
                    continue;
                }

                $found = true;
                $jobs[$index]['status'] = self::QUEUED;
                $jobs[$index]['error'] = '';
                $jobs[$index]['attempts'] = 0;
                $jobs[$index]['started_at'] = 0;
                $jobs[$index]['finished_at'] = 0;
                $jobs[$index]['created_at'] = time();
            }

            return $jobs;
        });

        if ($found) {
            self::$added++;
        }

        return $found;
    }

    /**
     * Every job kept, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::read();
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        foreach (self::read() as $job) {
            if ($job['id'] === $id) {
                return $job;
            }
        }

        return null;
    }

    /**
     * The newest job for $target, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function latest(string $target): ?array
    {
        $latest = null;

        foreach (self::read() as $job) {
            if ($job['target'] === $target) {
                $latest = $job;
            }
        }

        return $latest;
    }

    /**
     * The newest job of each target that starts with $prefix, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function withPrefix(string $prefix): array
    {
        $out = [];

        foreach (self::read() as $job) {
            if (str_starts_with($job['target'], $prefix)) {
                unset($out[$job['target']]);
                $out[$job['target']] = $job;
            }
        }

        return array_values($out);
    }

    /** Whether $job is queued or running. */
    public static function isActive(?array $job): bool
    {
        return null !== $job && in_array($job['status'], [self::QUEUED, self::RUNNING], true);
    }

    /** How many jobs are queued or running. */
    public static function activeCount(): int
    {
        return count(array_filter(self::read(), static fn (array $job): bool => self::isActive($job)));
    }

    /**
     * Takes the oldest queued job and marks it running, so no other request
     * takes it. A dead run is put back (or failed) first. Null when there is
     * nothing to take, or another request holds the lock.
     *
     * @return array<string, mixed>|null
     */
    public function claim(): ?array
    {
        $claimed = null;

        self::change(static function (array $jobs) use (&$claimed): array {
            $jobs = self::recover($jobs);

            foreach ($jobs as $index => $job) {
                if (self::QUEUED !== $job['status']) {
                    continue;
                }

                $jobs[$index]['status'] = self::RUNNING;
                $jobs[$index]['started_at'] = time();
                $jobs[$index]['attempts'] = (int) $job['attempts'] + 1;
                $claimed = $jobs[$index];

                break;
            }

            return $jobs;
        }, true);

        return $claimed;
    }

    /**
     * Takes one job and runs it. Returns the finished job, or null when
     * there was none to take.
     *
     * @return array<string, mixed>|null
     */
    public function runOne(): ?array
    {
        $job = $this->claim();

        if (null === $job) {
            return null;
        }

        $handler = $this->handlers[$job['kind']] ?? null;

        if (null === $handler) {
            $result = ['ok' => false, 'error' => __('This job can no longer be run.', 'eventcrew')];
        } else {
            try {
                $result = $handler($job['args']);
            } catch (Throwable $e) {
                $result = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $this->finish($job, is_array($result) ? $result : ['ok' => false, 'error' => '']);
    }

    /**
     * The WP-Cron run: jobs one after the other while RUN_SECONDS last,
     * starting none with less than MIN_JOB_SECONDS left. Returns how many ran.
     */
    public function drain(): int
    {
        $started = ($this->clock)();
        $ran = 0;

        while (($this->clock)() - $started + self::MIN_JOB_SECONDS <= self::RUN_SECONDS) {
            if (null === $this->runOne()) {
                break;
            }

            $ran++;
        }

        return $ran;
    }

    /**
     * Stores the outcome of a job that ran.
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function finish(array $job, array $result): array
    {
        $ok = (bool) ($result['ok'] ?? false);
        $job['status'] = $ok ? self::DONE : self::FAILED;
        $job['error'] = $ok ? '' : (string) ($result['error'] ?? '');
        $job['finished_at'] = time();

        if (! $ok && is_array($result['args'] ?? null)) {
            $job['args'] = $result['args'];
        }

        self::change(static function (array $jobs) use ($job): array {
            foreach ($jobs as $index => $old) {
                // A job replaced while it ran (a newer save of the same
                // target) is still in the list: running jobs are never
                // dropped. One removed in between stays removed.
                if ($old['id'] === $job['id']) {
                    $jobs[$index] = $job;
                }
            }

            return $jobs;
        });

        return $job;
    }

    /**
     * Puts each dead running job back in the queue, or fails it when it was
     * started MAX_ATTEMPTS times.
     *
     * @param list<array<string, mixed>> $jobs
     * @return list<array<string, mixed>>
     */
    private static function recover(array $jobs): array
    {
        $now = time();

        foreach ($jobs as $index => $job) {
            if (self::RUNNING !== $job['status'] || $now - (int) $job['started_at'] < self::STUCK_SECONDS) {
                continue;
            }

            if ((int) $job['attempts'] < self::MAX_ATTEMPTS) {
                $jobs[$index]['status'] = self::QUEUED;

                continue;
            }

            $jobs[$index]['status'] = self::FAILED;
            $jobs[$index]['error'] = __('The job took too long and was stopped.', 'eventcrew');
            $jobs[$index]['finished_at'] = $now;
        }

        return $jobs;
    }

    /**
     * Reads, changes and writes the job list under the lock. Without
     * $needLock, a lock that cannot be had is waited out and the change made
     * anyway (a save must not lose its input); with it, nothing is changed.
     *
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $change
     */
    private static function change(callable $change, bool $needLock = false): void
    {
        global $wpdb;

        $locked = isset($wpdb)
            && '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::LOCK, self::LOCK_WAIT));

        if (! $locked && $needLock) {
            return;
        }

        try {
            self::write($change(self::read(true)));
        } finally {
            if ($locked) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
            }
        }
    }

    /**
     * The job list. $fresh (under the lock, before a change) reads past the
     * option cache, since another request may have changed it since.
     *
     * @return list<array<string, mixed>>
     */
    private static function read(bool $fresh = false): array
    {
        if ($fresh) {
            wp_cache_delete(self::OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
            wp_cache_delete('alloptions', 'options');
        }

        $stored = get_option(self::OPTION, []);
        $jobs = [];

        foreach (is_array($stored) ? $stored : [] as $job) {
            if (! is_array($job) || ! isset($job['id'], $job['kind'], $job['target'], $job['status'])) {
                continue;
            }

            $jobs[] = [
                'id' => (string) $job['id'],
                'kind' => (string) $job['kind'],
                'target' => (string) $job['target'],
                'label' => (string) ($job['label'] ?? ''),
                'args' => is_array($job['args'] ?? null) ? $job['args'] : [],
                'status' => (string) $job['status'],
                'error' => (string) ($job['error'] ?? ''),
                'attempts' => (int) ($job['attempts'] ?? 0),
                'created_at' => (int) ($job['created_at'] ?? 0),
                'started_at' => (int) ($job['started_at'] ?? 0),
                'finished_at' => (int) ($job['finished_at'] ?? 0),
            ];
        }

        return $jobs;
    }

    /** @param list<array<string, mixed>> $jobs */
    private static function write(array $jobs): void
    {
        update_option(self::OPTION, self::prune($jobs), false);
    }

    /**
     * Drops finished jobs older than KEEP_SECONDS, and all but the newest
     * KEEP_FINISHED of the rest. Queued and running jobs always stay.
     *
     * @param list<array<string, mixed>> $jobs
     * @return list<array<string, mixed>>
     */
    private static function prune(array $jobs): array
    {
        $now = time();
        $finished = [];

        foreach ($jobs as $index => $job) {
            if (! self::isActive($job)) {
                $finished[$index] = (int) $job['finished_at'];
            }
        }

        arsort($finished);
        $keep = array_slice(
            array_filter($finished, static fn (int $at): bool => $now - $at <= self::KEEP_SECONDS),
            0,
            self::KEEP_FINISHED,
            true
        );

        return array_values(array_filter(
            $jobs,
            static fn (array $job, int $index): bool => self::isActive($job) || isset($keep[$index]),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * The status mark of $job for a page: a spinner and what it does while
     * queued or running; "Failed" with the reason and "Try again" when it
     * failed; with $live (an answer to the page's poll), "Done ✓" and a
     * reload link when it is done. Empty for no job, or a done job on a
     * freshly drawn page (which already shows the result).
     *
     * @param array<string, mixed>|null $job
     */
    public static function badge(?array $job, bool $live = false): string
    {
        if (null === $job) {
            return '';
        }

        $status = (string) $job['status'];
        $id = (string) $job['id'];

        if (self::isActive($job)) {
            $inner = '<span class="ec-job-spin" aria-hidden="true"></span>' . esc_html(self::doing((string) $job['kind']));
        } elseif (self::FAILED === $status) {
            $error = (string) $job['error'];
            $inner = esc_html__('Failed', 'eventcrew')
                . ('' === $error ? '' : ' – ' . esc_html($error))
                . ' ' . self::retryLink($id);
        } elseif ($live) {
            $inner = esc_html__('Done ✓', 'eventcrew')
                . ' <a href="#" class="ec-job-reload">' . esc_html__('Reload to see the new text.', 'eventcrew') . '</a>';
        } else {
            return '';
        }

        return sprintf(
            '<span class="ec-job ec-job-%s" data-ec-job="%s" role="status">%s</span>',
            esc_attr($status),
            esc_attr($id),
            $inner
        );
    }

    /** What a running job of $kind does, for its badge. */
    public static function doing(string $kind): string
    {
        return match ($kind) {
            'event_ai_rewrite' => __('Writing…', 'eventcrew'),
            'telegram_profile', 'telegram_webhook' => __('Saving to Telegram…', 'eventcrew'),
            default => __('Translating…', 'eventcrew'),
        };
    }

    /** The "Try again" link of a failed job: admin-post without JS, admin-ajax with it. */
    public static function retryLink(string $id): string
    {
        $url = wp_nonce_url(
            add_query_arg(['action' => 'eventcrew_job_retry', 'job' => $id], admin_url('admin-post.php')),
            'eventcrew_jobs'
        );

        return sprintf(
            '<a href="%s" data-ec-retry="%s">%s</a>',
            esc_url($url),
            esc_attr($id),
            esc_html__('Try again', 'eventcrew')
        );
    }
}
