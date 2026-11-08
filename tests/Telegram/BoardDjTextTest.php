<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EventCrew\Support\AiPrompts;
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

        // The text, then its translation (unanswered here).
        self::assertCount(2, $this->requests);
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

    /** The model's answer to a translation into Finnish. */
    private static function translation(string $finnish): string
    {
        return (string) json_encode(['lang' => 'en', 'fi' => $finnish]);
    }

    /**
     * The English text is translated in the same background run, under the
     * translator's rule for this text; a Finnish reader then gets the
     * Finnish text and nobody asks the model while reading it.
     */
    public function testTheTextIsTranslatedRightAfterItIsWritten(): void
    {
        $this->party();
        $this->answers[] = self::answer('DJ Anna plays swing and blues. She is based in Helsinki.');
        $this->answers[] = self::translation('DJ Anna soittaa swingiä ja bluesia. Hän asuu Helsingissä.');

        self::assertTrue($this->writer()->write(100));

        self::assertCount(2, $this->requests);
        $system = $this->requests[1]['messages'][0]['content'];
        self::assertStringContainsString(BoardDjText::TRANSLATE_NOTE, $system);
        self::assertStringContainsString('Finnish (fi)', $system);
        self::assertSame('DJ Anna plays swing and blues. She is based in Helsinki.', $this->requests[1]['messages'][1]['content']);

        self::assertSame('DJ Anna soittaa swingiä ja bluesia. Hän asuu Helsingissä.', BoardDjText::stored(100, 'fi'));
        self::assertSame('DJ Anna plays swing and blues. She is based in Helsinki.', BoardDjText::stored(100, 'en'));
        self::assertSame([], BoardDjText::untranslated(100));
        self::assertCount(2, $this->requests, 'reading never asks the model');
    }

    /**
     * A failed translation leaves the Finnish text empty (never English); a
     * later run asks again after RETRY_AFTER, not before; and a translation
     * that adds a number the English text has not (a door code) is not kept.
     */
    public function testAMissingTranslationIsEmptyAndAskedAgainLater(): void
    {
        $this->party();
        $this->answers[] = self::answer('DJ Anna plays swing and blues. She is based in Helsinki.');
        $this->answers[] = 500;

        self::assertTrue($this->writer()->write(100));
        self::assertSame('', BoardDjText::stored(100, 'fi'));
        self::assertSame(['fi'], BoardDjText::untranslated(100));

        // Within the hour: no new request, no run asked for.
        $this->queueTasks(1);
        $this->writer()->run();
        BoardDjText::schedule(100);
        self::assertCount(2, $this->requests);
        self::assertSame([], $this->scheduled);

        // An hour later: asked again; an added door code is not kept.
        $this->options[BoardDjText::OPTION][100]['tr_tried'] = time() - 3601;
        BoardDjText::schedule(100);
        self::assertSame([BoardDjText::HOOK], $this->scheduled);
        $this->answers[] = self::translation('DJ Anna soittaa swingiä. Ovikoodi 7302.');
        $this->queueTasks(1);
        $this->writer()->run();
        self::assertCount(3, $this->requests);
        self::assertSame('', BoardDjText::stored(100, 'fi'));

        $this->options[BoardDjText::OPTION][100]['tr_tried'] = 0;
        $this->answers[] = self::translation('DJ Anna soittaa swingiä ja bluesia. Hän asuu Helsingissä.');
        $this->queueTasks(1);
        $this->writer()->run();
        self::assertSame('DJ Anna soittaa swingiä ja bluesia. Hän asuu Helsingissä.', BoardDjText::stored(100, 'fi'));
    }

    /** A new English text (other DJs) drops the old translations and gets its own. */
    public function testANewTextGetsNewTranslations(): void
    {
        $this->party();
        $this->answers[] = self::answer('DJ Anna plays swing and blues. She is based in Helsinki.');
        $this->answers[] = self::translation('DJ Anna soittaa swingiä ja bluesia. Hän asuu Helsingissä.');
        $this->writer()->write(100);

        $this->djs[100] = [['name' => 'DJ Ben', 'description' => 'Ben plays lindy hop.']];
        self::assertSame('', BoardDjText::stored(100, 'fi'));

        $this->answers[] = self::answer('DJ Ben plays lindy hop. Expect fast feet.');
        $this->answers[] = self::translation('DJ Ben soittaa lindy hopia. Luvassa nopeita jalkoja.');
        self::assertTrue($this->writer()->write(100));
        self::assertSame('DJ Ben soittaa lindy hopia. Luvassa nopeita jalkoja.', BoardDjText::stored(100, 'fi'));
    }

    public function testAPromptChangedInWpAdminIsTheOneSent(): void
    {
        $this->party();
        $this->options[AiPrompts::OPTION] = [AiPrompts::BOARD_DJ => 'Write one sentence about the DJ.'];
        $this->answers[] = self::answer('DJ Anna plays swing.');

        self::assertTrue($this->writer()->write(100));

        self::assertStringStartsWith(
            "Write one sentence about the DJ.\n\nAnswer with JSON only",
            $this->requests[0]['messages'][0]['content']
        );
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

    public function testTheCronRunWritesTheTextAndTheNextPictureShowsIt(): void
    {
        $this->party();
        $this->answers[] = self::answer('DJ Anna plays swing and blues. She is based in Helsinki.');
        $this->queueTasks(1); // the run: which event is next
        $this->queueTasks(1); // the refresh: the text board
        $this->wpdb->nextResults[] = []; // and who is signed up

        $this->writer()->run();

        self::assertCount(2, $this->requests, 'the text, then its translation');
        // The text board has no picture (Q360): only it is posted.
        self::assertSame(['sendMessage'], $this->calledMethods());

        $this->queueTasks(2); // the announcement: the next event, then its card
        self::assertTrue($this->boardService()->announceNextCard());

        self::assertSame(['sendMessage', 'sendPhoto'], $this->calledMethods());
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

    public function testTheBoardAndThePictureNeverAskTheModel(): void
    {
        $this->party();
        $this->queueTasks(1); // the refresh: the text board
        $this->wpdb->nextResults[] = [];
        $this->queueTasks(1); // the announcement: the next event

        $this->boardService()->refresh();
        // The text is missing: a background run is asked for, and the
        // picture waits for it.
        self::assertFalse($this->boardService()->announceNextCard());

        self::assertSame([], $this->requests);
        self::assertSame([BoardDjText::HOOK], $this->scheduled);
        self::assertSame(['sendMessage'], $this->calledMethods());
    }

    /** A later event (not the board's next) with a DJ, so it has something to write from. */
    private function laterParty(int $id, string $date): void
    {
        $this->event($id, $date, 'Party ' . $id, ['_eventmesh_image_url' => self::IMAGE]);
        $this->djs[$id] = [['name' => 'DJ Ben ' . $id, 'description' => 'Ben plays lindy hop.']];
    }

    /**
     * schedule() queues an event that is not the board's next one (the
     * post-event email's coming event), once, and asks for one run.
     */
    public function testScheduleQueuesAnEventThatIsNotTheBoardsNext(): void
    {
        $this->laterParty(200, '2026-08-15');

        BoardDjText::schedule(200);
        BoardDjText::schedule(200);

        self::assertSame([200], $this->options[BoardDjText::QUEUE_OPTION]);
        self::assertSame([BoardDjText::HOOK, BoardDjText::HOOK], $this->scheduled);
        self::assertSame([], $this->requests, 'scheduling never asks the model');
    }

    /**
     * The run writes a queued event's text and its translation, also with
     * the board's cards off, and the event leaves the queue; the board is
     * not touched for it.
     */
    public function testTheRunWritesAQueuedEventsText(): void
    {
        $this->laterParty(200, '2026-08-15');
        $this->options[BoardService::CARDS_OPTION] = false;
        BoardDjText::schedule(200);
        $this->answers[] = self::answer('DJ Ben plays lindy hop. Expect fast feet.');
        $this->answers[] = self::translation('DJ Ben soittaa lindy hopia. Luvassa nopeita jalkoja.');

        $this->writer()->run();

        self::assertCount(2, $this->requests);
        self::assertStringContainsString('- DJ Ben 200: Ben plays lindy hop.', $this->requests[0]['messages'][1]['content']);
        self::assertSame('DJ Ben plays lindy hop. Expect fast feet.', BoardDjText::stored(200));
        self::assertSame('DJ Ben soittaa lindy hopia. Luvassa nopeita jalkoja.', BoardDjText::stored(200, 'fi'));
        self::assertSame([], $this->options[BoardDjText::QUEUE_OPTION]);
        self::assertSame([], $this->telegramCalls);
    }

    /**
     * A queued event whose request fails is tried again only after
     * RETRY_AFTER and stays queued; it holds up neither the board's next
     * event nor the other queued events. At most PER_RUN events are asked
     * about per run; another run is asked for the rest.
     */
    public function testAFailingQueuedEventHoldsUpNoOtherAndARunHasALimit(): void
    {
        $this->party();
        $this->laterParty(200, '2026-08-15');
        $this->laterParty(300, '2026-08-22');
        $this->laterParty(400, '2026-08-29');
        $this->options[BoardDjText::QUEUE_OPTION] = [200, 300, 400];
        $this->answers = [
            self::answer('DJ Anna plays swing and blues. She is based in Helsinki.'),
            self::translation('DJ Anna soittaa swingiä ja bluesia. Hän asuu Helsingissä.'),
            500,
            self::answer('DJ Ben plays lindy hop. Expect fast feet.'),
            self::translation('DJ Ben soittaa lindy hopia. Luvassa nopeita jalkoja.'),
        ];
        $this->queueTasks(1); // the run: which event is next
        $this->queueTasks(1); // the refresh: the text board
        $this->wpdb->nextResults[] = []; // and who is signed up

        $this->writer()->run();

        self::assertCount(5, $this->requests, 'the next event, 200 (failed), 300; 400 waits');
        self::assertNotSame('', BoardDjText::stored(100));
        self::assertSame('', BoardDjText::stored(200));
        self::assertSame('DJ Ben plays lindy hop. Expect fast feet.', BoardDjText::stored(300));
        self::assertSame('', BoardDjText::stored(400));
        self::assertSame([200, 400], $this->options[BoardDjText::QUEUE_OPTION]);
        self::assertSame([BoardDjText::HOOK], $this->scheduled, 'another run for 400');
        self::assertSame([100, 200, 300], array_keys($this->options[BoardDjText::TRIED_OPTION]));

        // The next run: 400 only; 200 waits for RETRY_AFTER.
        $this->options[BoardService::CARDS_OPTION] = false;
        $this->answers = [self::answer('DJ Ben plays lindy hop. Bring water.'), self::translation('DJ Ben soittaa.')];
        $this->writer()->run();
        self::assertCount(7, $this->requests);
        self::assertSame('DJ Ben plays lindy hop. Bring water.', BoardDjText::stored(400));
        self::assertSame([200], $this->options[BoardDjText::QUEUE_OPTION]);

        // An hour later 200 is asked again.
        $this->options[BoardDjText::TRIED_OPTION][200]['at'] = time() - 3601;
        $this->answers = [self::answer('DJ Ben plays lindy hop. At last.'), self::translation('DJ Ben soittaa vihdoin.')];
        $this->writer()->run();
        self::assertSame('DJ Ben plays lindy hop. At last.', BoardDjText::stored(200));
        self::assertSame([], $this->options[BoardDjText::QUEUE_OPTION]);
    }
}
