<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\NewsletterWriter;
use EventCrew\Telegram\BoardDjText;
use EventCrew\Telegram\BoardService;

/**
 * The short DJ or host text under the group board's picture. The model
 * server is a stub of wp_remote_post that answers from $answers in order and
 * records each request; Telegram calls go to TelegramTestCase's stub. Nothing
 * leaves the test.
 */
final class BoardDjTextTest extends TelegramTestCase
{
    use FakeEvents;

    private const IMAGE = 'https://holvi-cdn.example.test/image/abc.jpg';

    /** @var list<string|int> Model answers in order; an int is an HTTP error status. */
    private array $answers = [];

    /** @var list<array<string, mixed>> The decoded model request bodies. */
    private array $requests = [];

    /** @var array<int, mixed> What the DJ filter returns, per event id. */
    private array $djs = [];

    /** @var list<string> The hooks scheduled. */
    private array $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        Functions\when('get_the_title')->justReturn('');

        $this->answers = [];
        $this->requests = [];
        $this->djs = [];
        $this->scheduled = [];

        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        $this->options[BoardService::CARDS_OPTION] = true;
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100, 'message_id' => 0];
        $this->telegramResults['sendPhoto'] = ['message_id' => 61];
        $this->telegramResults['sendMessage'] = ['message_id' => 62];

        Functions\when('wp_doing_cron')->justReturn(true);
        Functions\when('wp_schedule_single_event')->alias(function (int $time, string $hook): bool {
            $this->scheduled[] = $hook;

            return true;
        });

        // Model requests are answered here; everything else is Telegram.
        $telegram = $this->telegramPost();
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use ($telegram): mixed {
            if (str_contains($url, '/chat/completions')) {
                $this->requests[] = (array) json_decode((string) $args['body'], true);

                return ['answer' => array_shift($this->answers) ?? ''];
            }

            return $telegram($url, $args);
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn (mixed $response): int => is_array($response) && is_int($response['answer'])
                ? $response['answer']
                : 200
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (mixed $response): string => is_array($response)
                ? (string) json_encode(['choices' => [['message' => ['content' => (string) $response['answer']]]]])
                : (string) $response
        );
        Filters\expectApplied(NewsletterWriter::DJ_FILTER)->zeroOrMoreTimes()->andReturnUsing(
            fn (mixed $value, int $postId): mixed => $this->djs[$postId] ?? []
        );
    }

    /** TelegramTestCase's Telegram stub, as a callable. */
    private function telegramPost(): \Closure
    {
        return function (string $url, array $args): string {
            $method = substr($url, (int) strrpos($url, '/') + 1);
            $this->telegramCalls[] = ['method' => $method, 'body' => (array) json_decode((string) $args['body'], true)];

            return (string) json_encode(['ok' => true, 'result' => $this->telegramResults[$method] ?? true]);
        };
    }

    private function party(): void
    {
        $this->event(100, '2026-08-01', 'Party', [
            '_eventmesh_image_url' => self::IMAGE,
            '_eventmesh_price' => '10.00 EUR',
        ], 'A night of social dancing with DJ Anna.');
        $this->djs[100] = [['name' => 'DJ Anna', 'description' => 'Anna plays swing and blues in Helsinki.']];
    }

    /** One upcoming task on the party's day, as many times as the board reads it. */
    private function queueTasks(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->wpdb->nextResults[] = [[
                'id' => 1,
                'task_date' => '2026-08-01',
                'role_slug' => 'decorate',
                'capacity' => 2,
                'event_post_id' => 100,
                'event_label' => 'Party',
                'starts_at' => null,
                'ends_at' => null,
                'notes' => '',
                'created_at' => '2026-07-01 00:00:00',
            ]];
        }
    }

    private function writer(): BoardDjText
    {
        return new BoardDjText(new LlmClient(new Logger()), new Logger(), $this->boardService());
    }

    private static function answer(string $text): string
    {
        return (string) json_encode(['text' => $text]);
    }

    public function testTheModelIsToldAboutTheDjAndTheEventAndForbiddenTheCrewWords(): void
    {
        $this->party();
        $this->answers[] = self::answer('DJ Anna plays swing and blues. She is based in Helsinki.');

        self::assertTrue($this->writer()->write(100));

        self::assertCount(1, $this->requests);
        $system = $this->requests[0]['messages'][0]['content'];
        $user = $this->requests[0]['messages'][1]['content'];
        self::assertStringContainsString('exactly 2 sentences', $system);
        foreach (['volunteer', 'shift', 'vuoro', 'vapaaehtoinen'] as $word) {
            self::assertStringContainsString('"' . $word . '"', $system);
        }
        self::assertStringContainsString('Event: Party', $user);
        self::assertStringContainsString('- DJ Anna: Anna plays swing and blues in Helsinki.', $user);
        self::assertStringContainsString('A night of social dancing with DJ Anna.', $user);

        self::assertSame('DJ Anna plays swing and blues. She is based in Helsinki.', BoardDjText::stored(100));
    }

    public function testAStoredTextIsNotShownOnceItsSourceChanged(): void
    {
        $this->party();
        $this->answers[] = self::answer('DJ Anna plays swing and blues. She is based in Helsinki.');
        $this->writer()->write(100);

        $this->djs[100] = [['name' => 'DJ Ben', 'description' => 'Ben plays lindy hop.']];

        self::assertSame('', BoardDjText::stored(100));
    }

    /**
     * @return array<string, array{0: string|int}>
     */
    public static function unusableAnswers(): array
    {
        return [
            'a crew word' => [self::answer('DJ Anna plays swing. Every vol' . 'unteer loves her.')],
            'a Finnish crew word' => [self::answer('DJ Anna soittaa swingiä. Hän rakastaa vu' . 'oroja.')],
            'too long' => [self::answer(str_repeat('DJ Anna plays swing. ', 30))],
            'a link' => [self::answer('DJ Anna plays swing. See https://anna.example.test.')],
            'not JSON' => ['DJ Anna plays swing.'],
            'an HTTP error' => [500],
        ];
    }

    /**
     * @dataProvider unusableAnswers
     */
    public function testAnUnusableAnswerStoresNothing(string|int $answer): void
    {
        $this->party();
        $this->answers[] = $answer;

        self::assertFalse($this->writer()->write(100));

        self::assertSame('', BoardDjText::stored(100));
        self::assertArrayNotHasKey(BoardDjText::OPTION, $this->options);
    }

    public function testNothingIsAskedWithoutDjsOrADescription(): void
    {
        $this->event(100, '2026-08-01', 'Party', ['_eventmesh_image_url' => self::IMAGE]);

        self::assertSame('', BoardDjText::source(100));
        self::assertFalse($this->writer()->write(100));
        BoardDjText::schedule(100);

        self::assertSame([], $this->requests);
        self::assertSame([], $this->scheduled);
    }

    public function testScheduleNeedsAModelAndWaitsAnHourAfterAFailure(): void
    {
        $this->party();

        unset($this->options[AssistantSettings::MODEL_OPTION]);
        BoardDjText::schedule(100);
        self::assertSame([], $this->scheduled);

        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        BoardDjText::schedule(100);
        self::assertSame([BoardDjText::HOOK], $this->scheduled);

        // A failed request for the same source: not asked again within the hour.
        $this->answers[] = 500;
        $this->writer()->write(100);
        BoardDjText::schedule(100);
        self::assertSame([BoardDjText::HOOK], $this->scheduled);
    }

    public function testTheCronRunWritesTheTextAndRefreshesTheBoardWithIt(): void
    {
        $this->party();
        $this->answers[] = self::answer('DJ Anna plays swing and blues. She is based in Helsinki.');
        $this->queueTasks(1); // the run: which event is next
        $this->queueTasks(2); // the refresh: the card and the text board

        $this->writer()->run();

        self::assertCount(1, $this->requests);
        self::assertSame(['sendPhoto', 'sendMessage'], $this->calledMethods());
        self::assertStringEndsWith(
            "\n\nDJ Anna plays swing and blues. She is based in Helsinki.",
            $this->lastCallTo('sendPhoto')['caption']
        );
    }

    public function testTheRunDoesNothingOutsideABackgroundRunOrWithTheSettingOff(): void
    {
        $this->party();
        $this->queueTasks(3);

        $this->options[BoardService::CARDS_OPTION] = false;
        $this->writer()->run();

        $this->options[BoardService::CARDS_OPTION] = true;
        Functions\when('wp_doing_cron')->justReturn(false);
        $this->writer()->run();

        self::assertSame([], $this->requests);
        self::assertSame([], $this->telegramCalls);
    }

    public function testTheBoardNeverAsksTheModel(): void
    {
        $this->party();
        $this->queueTasks(2);

        $this->boardService()->refresh();

        self::assertSame([], $this->requests);
        self::assertSame([BoardDjText::HOOK], $this->scheduled);
        self::assertSame(['sendPhoto', 'sendMessage'], $this->calledMethods());
    }
}
