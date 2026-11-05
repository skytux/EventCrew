<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Admin\JobsStatus;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Tests\TestCase;
use RuntimeException;

/**
 * The background job queue: adding and collapsing jobs, the exclusive claim,
 * running one job, the dead-run recovery, the WP-Cron time budget, what is
 * kept, and the admin-ajax poll. Options live in an array.
 */
final class BackgroundJobsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    private float $now = 1000.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->now = 1000.0;

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_nonce_url')->alias(static fn (string $url): string => $url . '&_wpnonce=n');
    }

    private function runner(): BackgroundJobs
    {
        return new BackgroundJobs(fn (): float => $this->now);
    }

    /** @return list<array<string, mixed>> */
    private function stored(): array
    {
        return (array) ($this->options[BackgroundJobs::OPTION] ?? []);
    }

    public function testTheSameTargetSavedTwiceIsOneJobWithTheLatestInput(): void
    {
        BackgroundJobs::enqueue('translate_row', 'row:a', ['source' => 'First']);
        $id = BackgroundJobs::enqueue('translate_row', 'row:a', ['source' => 'Second']);
        BackgroundJobs::enqueue('translate_row', 'row:b', ['source' => 'Other']);

        self::assertCount(2, $this->stored());
        self::assertSame($id, BackgroundJobs::latest('row:a')['id'] ?? null);
        self::assertSame(['source' => 'Second'], BackgroundJobs::latest('row:a')['args'] ?? null);
        self::assertSame(2, BackgroundJobs::activeCount());
        self::assertSame(3, BackgroundJobs::addedNow());
    }

    /** A job that already runs is not dropped by a newer save: the newer input runs after it. */
    public function testANewerSaveWaitsForTheRunningJob(): void
    {
        BackgroundJobs::enqueue('translate_row', 'row:a', ['source' => 'First']);
        $running = $this->runner()->claim();

        BackgroundJobs::enqueue('translate_row', 'row:a', ['source' => 'Second']);

        self::assertCount(2, $this->stored());
        self::assertSame(BackgroundJobs::RUNNING, BackgroundJobs::find((string) $running['id'])['status'] ?? null);
        self::assertSame(['source' => 'Second'], BackgroundJobs::latest('row:a')['args'] ?? null);
    }

    /** One job goes to one request: a second claim, or one while the lock is held, gets nothing. */
    public function testAJobIsClaimedByOneRequestOnly(): void
    {
        BackgroundJobs::enqueue('translate_row', 'row:a', []);

        $this->wpdb->heldLocks['eventcrew_background_jobs'] = true;
        self::assertNull($this->runner()->claim(), 'another request holds the lock');
        self::assertSame(BackgroundJobs::QUEUED, BackgroundJobs::latest('row:a')['status'] ?? null);

        unset($this->wpdb->heldLocks['eventcrew_background_jobs']);
        $first = $this->runner()->claim();
        $second = $this->runner()->claim();

        self::assertSame(BackgroundJobs::RUNNING, $first['status'] ?? null);
        self::assertSame(1, $first['attempts'] ?? null);
        self::assertNull($second);
        self::assertSame([], $this->wpdb->heldLocks, 'the lock is released');
    }

    public function testARunnerStoresSuccessAndFailure(): void
    {
        $jobs = $this->runner();
        $jobs->handle('ok', static fn (array $args): array => ['ok' => true, 'error' => '']);
        $jobs->handle('bad', static fn (array $args): array => [
            'ok' => false,
            'error' => 'The model did not answer.',
            'args' => ['source' => 'For the retry'],
        ]);
        $jobs->handle('throws', static function (): never {
            throw new RuntimeException('Broken.');
        });
        BackgroundJobs::enqueue('ok', 't:1', []);
        BackgroundJobs::enqueue('bad', 't:2', ['source' => 'First']);
        BackgroundJobs::enqueue('throws', 't:3', []);
        BackgroundJobs::enqueue('unknown', 't:4', []);

        self::assertSame(BackgroundJobs::DONE, $jobs->runOne()['status'] ?? null);

        $failed = $jobs->runOne();
        self::assertSame(BackgroundJobs::FAILED, $failed['status'] ?? null);
        self::assertSame('The model did not answer.', $failed['error']);
        self::assertSame(['source' => 'For the retry'], BackgroundJobs::latest('t:2')['args'] ?? null);

        self::assertSame('Broken.', $jobs->runOne()['error'] ?? null);
        self::assertSame('This job can no longer be run.', $jobs->runOne()['error'] ?? null);
        self::assertNull($jobs->runOne(), 'nothing is left');
        self::assertSame(0, BackgroundJobs::activeCount());

        // "Try again" queues the failed job once more; a done job cannot be.
        self::assertTrue(BackgroundJobs::retry((string) $failed['id']));
        self::assertSame(BackgroundJobs::QUEUED, BackgroundJobs::latest('t:2')['status'] ?? null);
        self::assertFalse(BackgroundJobs::retry((string) BackgroundJobs::latest('t:1')['id']));
    }

    /** A run that died is put back once, then failed. */
    public function testAStuckJobIsQueuedAgainOnceThenFailed(): void
    {
        BackgroundJobs::enqueue('slow', 't:1', []);
        $this->runner()->claim();
        $this->options[BackgroundJobs::OPTION][0]['started_at'] = time() - BackgroundJobs::STUCK_SECONDS - 1;

        $again = $this->runner()->claim();

        self::assertSame(2, $again['attempts'] ?? null, 'put back and taken again');

        $this->options[BackgroundJobs::OPTION][0]['started_at'] = time() - BackgroundJobs::STUCK_SECONDS - 1;

        self::assertNull($this->runner()->claim());
        self::assertSame(BackgroundJobs::FAILED, BackgroundJobs::latest('t:1')['status'] ?? null);
        self::assertSame('The job took too long and was stopped.', BackgroundJobs::latest('t:1')['error'] ?? null);

        // A job running for less than the limit is left alone.
        BackgroundJobs::enqueue('slow', 't:2', []);
        $this->runner()->claim();
        self::assertNull($this->runner()->claim());
        self::assertSame(BackgroundJobs::RUNNING, BackgroundJobs::latest('t:2')['status'] ?? null);
    }

    /** The WP-Cron run starts no job with less than MIN_JOB_SECONDS of its RUN_SECONDS left. */
    public function testTheCronRunKeepsToItsTimeBudget(): void
    {
        $jobs = $this->runner();
        $jobs->handle('slow', function (): array {
            $this->now += 6.0;

            return ['ok' => true, 'error' => ''];
        });

        for ($i = 0; $i < 10; $i++) {
            BackgroundJobs::enqueue('slow', 't:' . $i, []);
        }

        // Starts at 0 s, 6 s and 12 s; at 18 s only 2 s are left.
        self::assertSame(3, $jobs->drain());
        self::assertSame(7, BackgroundJobs::activeCount());
        self::assertSame(18.0, $this->now - 1000.0);
    }

    /** At most KEEP_FINISHED finished jobs are kept, none older than a day; active ones always. */
    public function testFinishedJobsArePruned(): void
    {
        $old = [];

        for ($i = 0; $i < 60; $i++) {
            $old[] = [
                'id' => 'done' . $i,
                'kind' => 'k',
                'target' => 't:' . $i,
                'status' => BackgroundJobs::DONE,
                'finished_at' => time() - 100 + $i,
            ];
        }

        $old[] = [
            'id' => 'ancient',
            'kind' => 'k',
            'target' => 't:ancient',
            'status' => BackgroundJobs::FAILED,
            'finished_at' => time() - DAY_IN_SECONDS - 10,
        ];
        $this->options[BackgroundJobs::OPTION] = $old;

        BackgroundJobs::enqueue('k', 't:new', []);

        $ids = array_column($this->stored(), 'id');
        self::assertCount(BackgroundJobs::KEEP_FINISHED + 1, $ids);
        self::assertNotContains('done0', $ids, 'the oldest go first');
        self::assertContains('done59', $ids);
        self::assertNotContains('ancient', $ids);
        self::assertSame(1, BackgroundJobs::activeCount());
    }

    public function testTheBadgeShowsWhatTheJobDoes(): void
    {
        $id = BackgroundJobs::enqueue('event_ai_rewrite', 'event:1:reminder', []);
        $badge = BackgroundJobs::badge(BackgroundJobs::find($id));

        self::assertStringContainsString('Writing…', $badge);
        self::assertStringContainsString('ec-job-spin', $badge);
        self::assertStringContainsString('data-ec-job="' . $id . '"', $badge);
        self::assertSame('', BackgroundJobs::badge(null));
        self::assertStringContainsString('Translating…', BackgroundJobs::badge(BackgroundJobs::find(
            BackgroundJobs::enqueue('translate_row', 'row:a', [])
        )));
    }

    /** The poll needs the capability and the nonce; then it runs one job and answers the badges. */
    public function testThePollChecksTheCapabilityAndTheNonce(): void
    {
        $jobs = $this->runner();
        $jobs->handle('ok', static fn (): array => ['ok' => true, 'error' => '']);
        $id = BackgroundJobs::enqueue('ok', 't:1', []);
        $status = new JobsStatus($jobs);
        $can = false;
        $nonce = false;
        Functions\when('current_user_can')->alias(static function () use (&$can): bool {
            return $can;
        });
        Functions\when('check_ajax_referer')->alias(static function () use (&$nonce): bool {
            if (! $nonce) {
                throw new RuntimeException('bad nonce');
            }

            return true;
        });
        Functions\when('wp_send_json_error')->alias(static function (mixed $data, int $code): never {
            throw new RuntimeException('error ' . $code);
        });
        Functions\when('wp_send_json_success')->alias(static function (mixed $data): never {
            throw new JsonAnswer($data);
        });
        Functions\when('wp_unslash')->returnArg(1);
        $_POST = ['ids' => $id];

        try {
            $status->poll();
        } catch (RuntimeException $e) {
            self::assertSame('error 403', $e->getMessage());
        }

        self::assertSame(1, BackgroundJobs::activeCount(), 'nothing ran without the capability');

        $can = true;

        try {
            $status->poll();
        } catch (RuntimeException $e) {
            self::assertSame('bad nonce', $e->getMessage());
        }

        self::assertSame(1, BackgroundJobs::activeCount(), 'nothing ran without the nonce');

        $nonce = true;

        try {
            $status->poll();
            self::fail('The poll answers.');
        } catch (JsonAnswer $answer) {
            self::assertSame(0, $answer->data['active']);
            self::assertSame(BackgroundJobs::DONE, $answer->data['jobs'][$id]['status']);
            self::assertStringContainsString('Done ✓', $answer->data['jobs'][$id]['html']);
            self::assertStringContainsString('All background jobs are done.', $answer->data['bar']);
        } finally {
            $_POST = [];
        }
    }
}

/** What wp_send_json_success() was given, thrown so the test can read it. */
final class JsonAnswer extends \Exception
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly array $data)
    {
        parent::__construct('json');
    }
}
