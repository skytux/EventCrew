<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;

/**
 * "Translate missing" on the Translations page, run in the background.
 *
 * start() (the button) writes the job into OPTION: the ids of the rows that
 * miss a language text (TranslationRegistry::missingIds()), and asks WP-Cron
 * for a run. Nothing is translated during the click, so the page returns at
 * once. While a job is running, a second start() starts nothing.
 *
 * Each WP-Cron run (run()) takes the next batch from the queue, at most
 * BATCH_SIZE texts and BATCH_CHARS characters, and sends it to the language
 * model in one request (TranslationRegistry::translateRows()). Each row is
 * stored as soon as its batch comes back, so a failed batch does not lose
 * the texts of other batches. A run starts a new batch only while it has
 * at least MIN_REQUEST_SECONDS left of RUN_SECONDS, and gives each request
 * only the time left, so one run stays within about RUN_SECONDS. When texts
 * are left, the run asks WP-Cron for the next run.
 *
 * When a batch of more than one text times out or gets an answer that
 * cannot be read, its texts go back to the front of the queue and the batch
 * size is halved. Other failed texts are counted as failed and not tried
 * again in this job. The job stops, counting every text left as failed,
 * when AI translation is switched off, when no language model is set up,
 * or after MAX_FAILED_IN_A_ROW failed requests in a row (the model is down).
 *
 * The page reads the progress with status(): total, done, failed.
 */
final class TranslationJob
{
    /** The WP-Cron event of one run. */
    public const HOOK = 'eventcrew_translate_missing_run';

    /** Where the job is kept. */
    public const OPTION = 'eventcrew_translate_missing_job';

    /** start(): the job was started. */
    public const STARTED = 'started';

    /** start(): a job is already running; nothing new was started. */
    public const RUNNING = 'running';

    /** start(): every text is already in every language. */
    public const NOTHING = 'nothing';

    /** start(): AI translation is off. */
    public const OFF = 'off';

    /** Texts per request at most. */
    public const BATCH_SIZE = 30;

    /** Characters of source text per request at most (one longer text is still sent alone). */
    public const BATCH_CHARS = 6000;

    /** Seconds one run may take. */
    public const RUN_SECONDS = 20;

    /** A new request is started only with at least this many seconds left. */
    public const MIN_REQUEST_SECONDS = 5;

    /** Failed requests in a row after which the job stops. */
    public const MAX_FAILED_IN_A_ROW = 3;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param (Closure(): float)|null $clock Seconds, like microtime(true) (the default).
     */
    public function __construct(
        private readonly TranslationRegistry $registry,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function boot(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    /**
     * The job as stored, or null when "Translate missing" was never used.
     *
     * @return array{
     *     running: bool,
     *     total: int,
     *     done: int,
     *     failed: int,
     *     error: string,
     *     queue: list<string>,
     *     batch: int,
     *     failed_in_a_row: int
     * }|null
     *     error is the first request error kind ('' when none).
     */
    public static function status(): ?array
    {
        $stored = get_option(self::OPTION, null);

        if (! is_array($stored)) {
            return null;
        }

        $queue = is_array($stored['queue'] ?? null)
            ? array_values(array_map('strval', array_filter($stored['queue'], 'is_scalar')))
            : [];
        $batch = (int) ($stored['batch'] ?? self::BATCH_SIZE);

        return [
            'running' => (bool) ($stored['running'] ?? false),
            'total' => max(0, (int) ($stored['total'] ?? 0)),
            'done' => max(0, (int) ($stored['done'] ?? 0)),
            'failed' => max(0, (int) ($stored['failed'] ?? 0)),
            'error' => is_string($stored['error'] ?? null) ? $stored['error'] : '',
            'queue' => $queue,
            'batch' => $batch < 1 || $batch > self::BATCH_SIZE ? self::BATCH_SIZE : $batch,
            'failed_in_a_row' => max(0, (int) ($stored['failed_in_a_row'] ?? 0)),
        ];
    }

    /**
     * The button: queues every row that misses a language text and asks
     * WP-Cron for a run. Translates nothing itself.
     *
     * @return string STARTED, RUNNING (a job is already running: it is asked
     *     for again, in case its WP-Cron event was lost), NOTHING or OFF.
     */
    public function start(): string
    {
        if (! TranslatedText::autoEnabled()) {
            return self::OFF;
        }

        $status = self::status();

        if (null !== $status && $status['running']) {
            self::schedule();

            return self::RUNNING;
        }

        $ids = $this->registry->missingIds();

        if ([] === $ids) {
            return self::NOTHING;
        }

        self::save([
            'running' => true,
            'total' => count($ids),
            'done' => 0,
            'failed' => 0,
            'error' => '',
            'queue' => $ids,
            'batch' => self::BATCH_SIZE,
            'failed_in_a_row' => 0,
        ]);
        self::schedule();

        return self::STARTED;
    }

    /**
     * One WP-Cron run: translates batches until the queue is empty or the
     * time is used up, then asks for the next run when texts are left.
     * Returns the job as stored afterwards (null when there is none).
     *
     * @return array<string, mixed>|null
     */
    public function run(): ?array
    {
        $job = self::status();

        if (null === $job || ! $job['running']) {
            return $job;
        }

        $started = ($this->clock)();
        $batches = 0;

        while ([] !== $job['queue']) {
            if (! TranslatedText::autoEnabled()) {
                $job = self::stop($job, TranslatedText::ERROR_OFF);

                break;
            }

            $left = self::RUN_SECONDS - (int) floor(($this->clock)() - $started);

            if ($batches > 0 && $left < self::MIN_REQUEST_SECONDS) {
                break;
            }

            $ids = $this->nextBatch($job['queue'], $job['batch']);
            $job['queue'] = array_values(array_slice($job['queue'], count($ids)));
            $result = $this->registry->translateRows($ids, max(self::MIN_REQUEST_SECONDS, $left));
            $batches++;
            $job = $this->count($job, $ids, $result);
            self::save($job);
        }

        if ([] === $job['queue']) {
            $job['running'] = false;
            self::save($job);
        } else {
            self::schedule();
        }

        return $job;
    }

    /** Asks WP-Cron for a run, unless one is already waiting. */
    public static function schedule(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_single_event')) {
            return;
        }

        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time(), self::HOOK);
        }
    }

    /**
     * The first ids of $queue: at most $size, and at most BATCH_CHARS
     * characters of source (always at least one id).
     *
     * @param list<string> $queue
     * @return list<string>
     */
    private function nextBatch(array $queue, int $size): array
    {
        $lengths = [];

        foreach ($this->registry->rows() as $row) {
            $source = $row->value['source'];
            $lengths[$row->id] = function_exists('mb_strlen') ? mb_strlen($source) : strlen($source);
        }

        $ids = [];
        $chars = 0;

        foreach ($queue as $id) {
            $chars += $lengths[$id] ?? 0;

            if ([] !== $ids && (count($ids) >= $size || $chars > self::BATCH_CHARS)) {
                break;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The job after one batch.
     *
     * @param array<string, mixed> $job
     * @param list<string> $ids
     * @param array{done: list<string>, failed: list<string>, error: string} $result
     * @return array<string, mixed>
     */
    private function count(array $job, array $ids, array $result): array
    {
        $error = $result['error'];
        $job['done'] += count($result['done']);

        if ('' === $error) {
            $job['failed'] += count($result['failed']);
            $job['failed_in_a_row'] = 0;

            return $job;
        }

        if (in_array($error, [TranslatedText::ERROR_OFF, LlmClient::ERROR_NOT_CONFIGURED], true)) {
            $job['failed'] += count($result['failed']);

            return self::stop($job, $error);
        }

        $retry = in_array($error, [LlmClient::ERROR_TIMEOUT, TranslatedText::ERROR_BAD_ANSWER], true);

        if ($retry && count($result['failed']) > 1) {
            // Too much for one answer: try these again, half as many at a time.
            $job['queue'] = array_values(array_merge($result['failed'], $job['queue']));
            $job['batch'] = max(1, intdiv(count($ids), 2));

            return $job;
        }

        $job['failed'] += count($result['failed']);
        $job['failed_in_a_row']++;

        if ('' === $job['error']) {
            $job['error'] = $error;
        }

        return $job['failed_in_a_row'] >= self::MAX_FAILED_IN_A_ROW ? self::stop($job, $error) : $job;
    }

    /**
     * Ends the job: every text left counts as failed.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private static function stop(array $job, string $error): array
    {
        $job['failed'] += count($job['queue']);
        $job['queue'] = [];
        $job['running'] = false;

        if ('' === $job['error']) {
            $job['error'] = $error;
        }

        return $job;
    }

    /** @param array<string, mixed> $job */
    private static function save(array $job): void
    {
        update_option(self::OPTION, $job, false);
    }
}
