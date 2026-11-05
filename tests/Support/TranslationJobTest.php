<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationJob;
use EventCrew\Support\TranslationRegistry;
use EventCrew\Support\TranslationRow;
use EventCrew\Tests\TestCase;

/**
 * "Translate missing" in the background: the button queues the job, each
 * WP-Cron run sends batches of many texts per request within its time
 * budget and asks for the next run, the progress numbers, the placeholder
 * guard, and that a second click starts nothing. The model is a stub of
 * wp_remote_post that answers every text it is sent; nothing leaves the
 * test.
 */
final class TranslationJobTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<list<array<string, mixed>>> The texts each request sent. */
    private array $sent = [];

    /** @var list<string> The WP-Cron events asked for. */
    private array $scheduled = [];

    /** Seconds on the job's clock. */
    private float $now = 1000.0;

    /** Seconds each request takes on the job's clock. */
    private float $requestSeconds = 0.0;

    /** @var array<int, string> Answers to use instead of the echo, by request number (0 = first). */
    private array $answers = [];

    /** @var array<int, int> HTTP status by request number; 200 when not set. */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            AssistantSettings::URL_OPTION => 'https://llm.example.test/v1',
            AssistantSettings::MODEL_OPTION => 'test-model',
        ];
        $this->sent = [];
        $this->scheduled = [];
        $this->now = 1000.0;
        $this->requestSeconds = 0.0;
        $this->answers = [];
        $this->statuses = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function (int $at, string $hook): bool {
            $this->scheduled[] = $hook;

            return true;
        });
        Functions\when('wp_remote_post')->alias(fn (string $url, array $args): array => $this->model($args));
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn (array $response): int => $response['status']
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (array $response): string => (string) json_encode(
                ['choices' => [['message' => ['content' => $response['answer']]]]]
            )
        );
    }

    /**
     * The model stub: records the texts sent and answers each one, "translated"
     * into Finnish as "FI <text>", unless $answers or $statuses say otherwise.
     *
     * @param array<string, mixed> $args
     * @return array{answer: string, status: int}
     */
    private function model(array $args): array
    {
        $body = json_decode((string) $args['body'], true);
        $texts = json_decode((string) $body['messages'][1]['content'], true);
        $number = count($this->sent);
        $this->sent[] = $texts;
        $this->now += $this->requestSeconds;

        $answer = $this->answers[$number] ?? (string) json_encode(array_map(
            static fn (array $text): array => ['id' => $text['id'], 'lang' => 'en', 'fi' => 'FI ' . $text['text']],
            $texts
        ));

        return ['answer' => $answer, 'status' => $this->statuses[$number] ?? 200];
    }

    /**
     * A registry with $count rows "r1".."r$count", English, Finnish missing.
     * $required: row id => the placeholder it must keep.
     *
     * @param array<string, string> $required
     */
    private function registry(int $count, array $required = []): TranslationRegistry
    {
        for ($i = 1; $i <= $count; $i++) {
            $text = 'Text ' . $i . (isset($required['r' . $i]) ? ' ' . $required['r' . $i] : '');
            $this->options['row.r' . $i] ??= [
                'source' => $text,
                'lang' => 'en',
                'texts' => ['fi' => '', 'en' => $text],
            ];
        }

        $registry = new TranslationRegistry(new TranslatedText(new LlmClient(new Logger()), new Logger()));
        $registry->add(function () use ($count, $required): array {
            $rows = [];

            for ($i = 1; $i <= $count; $i++) {
                $id = 'r' . $i;
                $rows[] = new TranslationRow(
                    $id,
                    'Label ' . $id,
                    TranslationRow::GROUP_TEXTS,
                    (array) $this->options['row.' . $id],
                    function (array $value) use ($id): void {
                        $this->options['row.' . $id] = $value;
                    },
                    required: isset($required[$id]) ? [$required[$id]] : []
                );
            }

            return $rows;
        });

        return $registry;
    }

    private function job(TranslationRegistry $registry): TranslationJob
    {
        return new TranslationJob($registry, fn (): float => $this->now);
    }

    private function finnish(int $row): string
    {
        return (string) $this->options['row.r' . $row]['texts']['fi'];
    }

    /** @return array<string, mixed> */
    private function jobStatus(): array
    {
        $status = TranslationJob::status();
        self::assertNotNull($status);

        return $status;
    }

    /** The click queues every missing text and asks WP-Cron; the model is not asked. */
    public function testStartQueuesWithoutTranslating(): void
    {
        $job = $this->job($this->registry(70));

        self::assertSame(TranslationJob::STARTED, $job->start());
        self::assertSame([], $this->sent);
        self::assertSame([TranslationJob::HOOK], $this->scheduled);
        self::assertSame(70, $this->jobStatus()['total']);
        self::assertSame(0, $this->jobStatus()['done']);
        self::assertTrue($this->jobStatus()['running']);
        self::assertSame('', $this->finnish(1));
    }

    /** Many texts per request: 70 texts are three requests, not seventy. */
    public function testTextsAreSentInBatches(): void
    {
        $job = $this->job($this->registry(70));
        $job->start();

        $job->run();

        self::assertCount(3, $this->sent);
        self::assertSame([30, 30, 10], array_map('count', $this->sent));
        self::assertSame('FI Text 1', $this->finnish(1));
        self::assertSame('FI Text 70', $this->finnish(70));
        self::assertFalse($this->jobStatus()['running']);
        self::assertSame(70, $this->jobStatus()['done']);
        self::assertSame(0, $this->jobStatus()['failed']);
    }

    /** One long text over the character limit goes alone; the limit is never a reason to send nothing. */
    public function testLongTextsMakeSmallerBatches(): void
    {
        $long = str_repeat('a', TranslationJob::BATCH_CHARS - 10);
        $this->options['row.r1'] = ['source' => $long, 'lang' => 'en', 'texts' => ['fi' => '', 'en' => $long]];
        $this->options['row.r2'] = ['source' => $long, 'lang' => 'en', 'texts' => ['fi' => '', 'en' => $long]];
        $job = $this->job($this->registry(3));
        $job->start();

        $job->run();

        self::assertSame([1, 2], array_map('count', $this->sent));
        self::assertSame(3, $this->jobStatus()['done']);
    }

    /**
     * Each run stays inside its time budget and asks for the next run; the
     * job goes on where it stopped, and the progress numbers follow.
     */
    public function testTheJobResumesAcrossRuns(): void
    {
        $this->requestSeconds = 16.0;
        $job = $this->job($this->registry(70));
        $job->start();
        $this->scheduled = [];

        $job->run();

        self::assertCount(1, $this->sent, 'after 16 s there is no time for a second request');
        self::assertSame([TranslationJob::HOOK], $this->scheduled, 'the next run was asked for');
        self::assertTrue($this->jobStatus()['running']);
        self::assertSame(30, $this->jobStatus()['done']);
        self::assertSame(70, $this->jobStatus()['total']);
        self::assertCount(40, $this->jobStatus()['queue']);

        $job->run();

        self::assertCount(2, $this->sent);
        self::assertSame(60, $this->jobStatus()['done']);
        self::assertSame('Text 31', $this->sent[1][0]['text'], 'the second run went on where the first stopped');

        $this->scheduled = [];
        $job->run();

        self::assertCount(3, $this->sent);
        self::assertSame([], $this->scheduled, 'nothing is left, so no next run');
        self::assertFalse($this->jobStatus()['running']);
        self::assertSame(70, $this->jobStatus()['done']);
        self::assertSame('FI Text 70', $this->finnish(70));

        $job->run();

        self::assertCount(3, $this->sent, 'a finished job sends nothing');
    }

    /** Each request gets only the time the run has left. */
    public function testARequestGetsOnlyTheTimeLeft(): void
    {
        $timeouts = [];
        $this->requestSeconds = 9.0;
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$timeouts): array {
            $timeouts[] = $args['timeout'];

            return $this->model($args);
        });
        $job = $this->job($this->registry(90));
        $job->start();

        $job->run();

        self::assertSame([20, 11], $timeouts);
    }

    /** A translation that drops a required placeholder is not stored and counts as failed; the rest of its batch is stored. */
    public function testThePlaceholderGuardHolds(): void
    {
        $this->answers[0] = (string) json_encode([
            ['id' => '1', 'lang' => 'en', 'fi' => 'FI Text 1'],
            ['id' => '2', 'lang' => 'en', 'fi' => 'Teksti 2 ilman osoitetta'],
            ['id' => '3', 'lang' => 'en', 'fi' => 'FI Text 3'],
        ]);
        $job = $this->job($this->registry(3, ['r2' => '{email}']));
        $job->start();

        $job->run();

        self::assertSame('FI Text 1', $this->finnish(1));
        self::assertSame('', $this->finnish(2));
        self::assertSame('FI Text 3', $this->finnish(3));
        self::assertSame(2, $this->jobStatus()['done']);
        self::assertSame(1, $this->jobStatus()['failed']);
        self::assertFalse($this->jobStatus()['running']);
    }

    /** A failed batch counts its texts as failed; the batches before and after it are stored. */
    public function testAFailedBatchDoesNotLoseTheOthers(): void
    {
        $this->statuses[1] = 500;
        $job = $this->job($this->registry(70));
        $job->start();

        $job->run();

        self::assertCount(3, $this->sent);
        self::assertSame('FI Text 1', $this->finnish(1));
        self::assertSame('', $this->finnish(31));
        self::assertSame('FI Text 61', $this->finnish(61));
        self::assertSame(40, $this->jobStatus()['done']);
        self::assertSame(30, $this->jobStatus()['failed']);
        self::assertSame('http_500', $this->jobStatus()['error']);
    }

    /** An answer that cannot be read: the texts are tried again, half as many at a time. */
    public function testAnUnreadableAnswerIsTriedAgainInSmallerBatches(): void
    {
        $this->answers[0] = 'not json';
        $job = $this->job($this->registry(10));
        $job->start();

        $job->run();

        self::assertSame([10, 5, 5], array_map('count', $this->sent));
        self::assertSame(10, $this->jobStatus()['done']);
        self::assertSame(0, $this->jobStatus()['failed']);
        self::assertSame('', $this->jobStatus()['error']);
    }

    /** A model that keeps failing stops the job; every text left counts as failed. */
    public function testAModelThatKeepsFailingStopsTheJob(): void
    {
        $this->statuses = array_fill(0, 10, 503);
        $job = $this->job($this->registry(150));
        $job->start();

        $job->run();

        self::assertCount(TranslationJob::MAX_FAILED_IN_A_ROW, $this->sent);
        self::assertFalse($this->jobStatus()['running']);
        self::assertSame(0, $this->jobStatus()['done']);
        self::assertSame(150, $this->jobStatus()['failed']);
    }

    /** A second click while the job runs starts nothing new. */
    public function testASecondClickDoesNotStartASecondJob(): void
    {
        $this->requestSeconds = 16.0;
        $job = $this->job($this->registry(70));
        $job->start();
        $job->run();
        $before = $this->jobStatus();

        self::assertSame(TranslationJob::RUNNING, $job->start());
        self::assertSame($before, $this->jobStatus());
        self::assertCount(1, $this->sent);
    }

    /** After a job has finished, a click starts a new one for what is still missing. */
    public function testAFinishedJobCanBeStartedAgain(): void
    {
        $job = $this->job($this->registry(3));
        $job->start();
        $job->run();

        self::assertSame(TranslationJob::NOTHING, $job->start());

        $this->options['row.r2']['texts']['fi'] = '';

        self::assertSame(TranslationJob::STARTED, $job->start());
        self::assertSame(1, $this->jobStatus()['total']);
    }

    /** The AI translation switch: off, the click queues nothing; switched off mid-job, the job stops. */
    public function testTheSwitchIsKept(): void
    {
        $this->options[TranslatedText::AUTO_OPTION] = '0';
        $job = $this->job($this->registry(5));

        self::assertSame(TranslationJob::OFF, $job->start());
        self::assertNull(TranslationJob::status());

        $this->options[TranslatedText::AUTO_OPTION] = '1';
        $job->start();
        $this->options[TranslatedText::AUTO_OPTION] = '0';
        $job->run();

        self::assertSame([], $this->sent);
        self::assertFalse($this->jobStatus()['running']);
        self::assertSame(5, $this->jobStatus()['failed']);
        self::assertSame('', $this->finnish(1));
    }

    /** No language model set up: the job stops at once instead of trying every batch. */
    public function testNoModelStopsTheJob(): void
    {
        unset($this->options[AssistantSettings::URL_OPTION], $this->options[AssistantSettings::MODEL_OPTION]);
        $job = $this->job($this->registry(70));
        $job->start();

        $job->run();

        self::assertSame([], $this->sent);
        self::assertFalse($this->jobStatus()['running']);
        self::assertSame(70, $this->jobStatus()['failed']);
        self::assertSame(LlmClient::ERROR_NOT_CONFIGURED, $this->jobStatus()['error']);
        self::assertSame('', $this->finnish(1));
    }
}
