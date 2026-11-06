<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EventCrew\Admin\AiPromptsPage;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\EventVenue;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\NewsletterWriter;
use EventCrew\Support\VenueReader;
use EventCrew\Tests\TestCase;

/**
 * The venue read from an event's description by the language model: off by
 * default, background only, once per event, kept as a suggestion the
 * organizers can change, and never invented.
 */
final class VenueReaderTest extends TestCase
{
    private const DESCRIPTION = 'Ecstatic dance at Studio Kallio. DJ Aino plays from 19.';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array{post: object, meta: array<string, string>}> */
    private array $events = [];

    /** @var list<string|int> Model answers in order; an int is an HTTP error status. */
    private array $answers = [];

    private int $requests = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            VenueReader::OPTION => '1',
            AssistantSettings::URL_OPTION => 'https://llm.example.test/v1',
            AssistantSettings::MODEL_OPTION => 'test-model',
        ];
        $this->events = [];
        $this->answers = [];
        $this->requests = 0;

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_posts')->alias(
            fn (): array => array_map(static fn (array $event): object => $event['post'], array_values($this->events))
        );
        Functions\when('get_post')->alias(fn (int $id): ?object => $this->events[$id]['post'] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key): string => $this->events[$id]['meta'][$key] ?? ''
        );
        Functions\when('update_post_meta')->alias(function (int $id, string $key, string $value): bool {
            $this->events[$id]['meta'][$key] = $value;

            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            unset($this->events[$id]['meta'][$key]);

            return true;
        });
        Functions\when('get_permalink')->alias(static fn (int $id): string => 'https://site.test/event-' . $id . '/');
        Functions\when('add_action')->justReturn(true);
        Functions\when('wp_doing_cron')->justReturn(true);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('wp_remote_post')->alias(function (): array {
            ++$this->requests;

            return ['answer' => array_shift($this->answers) ?? ''];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn (array $response): int => is_int($response['answer']) ? $response['answer'] : 200
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (array $response): string => (string) json_encode(
                ['choices' => [['message' => ['content' => (string) $response['answer']]]]]
            )
        );
        Filters\expectApplied(NewsletterWriter::DJ_FILTER)->zeroOrMoreTimes()->andReturn([]);
    }

    private function event(int $id, string $date, string $content = self::DESCRIPTION): void
    {
        $this->events[$id] = [
            'post' => (object) [
                'ID' => $id,
                'post_type' => 'eventmesh_event',
                'post_title' => 'Ecstatic Dance',
                'post_status' => 'publish',
                'post_content' => $content,
                'post_excerpt' => '',
            ],
            'meta' => ['_eventmesh_starts_at' => $date . ' 19:00:00'],
        ];
    }

    private function reader(): VenueReader
    {
        $logger = new Logger();

        return new VenueReader(new NewsletterWriter(new LlmClient($logger), $logger));
    }

    private static function answer(string $venue): string
    {
        return (string) json_encode(['venue' => $venue, 'address' => '', 'doors_open' => '', 'doors_close' => '']);
    }

    public function testOffByDefault(): void
    {
        unset($this->options[VenueReader::OPTION]);
        $this->event(41, '2099-01-10');

        self::assertFalse(VenueReader::isOn());
        self::assertSame(0, $this->reader()->run());
        self::assertSame(0, $this->requests);
    }

    public function testNeverOutsideTheBackground(): void
    {
        Functions\when('wp_doing_cron')->justReturn(false);
        $this->event(41, '2099-01-10');

        self::assertSame(0, $this->reader()->run());
        self::assertSame(0, $this->requests);
    }

    public function testTheVenueIsSavedAsASuggestionOnce(): void
    {
        $this->event(41, '2099-01-10');
        $this->answers = [self::answer('Studio Kallio')];

        self::assertSame(1, $this->reader()->run());
        self::assertSame('Studio Kallio', EventVenue::own(41));
        self::assertSame('📍 Studio Kallio', EventVenue::line(41));
        self::assertTrue(VenueReader::isSuggested(41));

        // Not asked again, even after the organizer clears it.
        EventVenue::saveOwn(41, '');
        self::assertSame(0, $this->reader()->run());
        self::assertSame(1, $this->requests);

        // Changed by hand: no longer the AI's suggestion.
        EventVenue::saveOwn(41, 'Garden');
        self::assertFalse(VenueReader::isSuggested(41));
    }

    public function testAnInventedVenueOrADjIsNotKept(): void
    {
        $this->event(41, '2099-01-10');
        $this->answers = [self::answer('Paris Opera')];

        $this->reader()->run();

        self::assertSame('', EventVenue::own(41));
        self::assertSame('done', $this->events[41]['meta'][VenueReader::ASKED_META]);
    }

    public function testSkipsEventsThatHaveAVenueNoTextOrArePast(): void
    {
        $this->event(41, '2099-01-10');
        EventVenue::saveOwn(41, 'Garden');
        $this->event(42, '2099-01-11', '');
        $this->event(43, '2000-01-01');

        self::assertSame(0, $this->reader()->run());
        self::assertSame(0, $this->requests);
        self::assertSame('Garden', EventVenue::own(41));
    }

    public function testAFailureIsTriedAgainAtMostMaxTries(): void
    {
        $this->event(41, '2099-01-10');
        $this->answers = array_fill(0, VenueReader::MAX_TRIES + 1, 500);

        for ($i = 0; $i < VenueReader::MAX_TRIES + 1; ++$i) {
            $this->reader()->run();
        }

        self::assertSame(VenueReader::MAX_TRIES, $this->requests);
        self::assertSame('', EventVenue::own(41));
    }

    public function testReadAgainAsksOnTheNextRunButKeepsAVenueSetByHand(): void
    {
        $this->event(41, '2099-01-10');
        $this->answers = [self::answer('Studio Kallio'), self::answer('Studio Kallio')];
        $this->reader()->run();
        $page = new AiPromptsPage();

        self::assertTrue(VenueReader::canReadAgain(41));
        self::assertSame(
            'The AI reads the venue from the description again within the hour.',
            $page->applyVenueAgain(41)
        );
        self::assertSame('', EventVenue::own(41), 'the suggestion is removed');
        self::assertSame(1, $this->requests, 'nothing is asked while the page is used');

        self::assertSame(1, $this->reader()->run());
        self::assertSame('Studio Kallio', EventVenue::own(41));
        self::assertSame(2, $this->requests);

        EventVenue::saveOwn(41, 'Garden');
        self::assertFalse(VenueReader::canReadAgain(41));
        self::assertStringStartsWith('Nothing was changed', $page->applyVenueAgain(41));
        self::assertSame('Garden', EventVenue::own(41));

        $this->options[VenueReader::OPTION] = '0';
        EventVenue::saveOwn(41, '');
        self::assertFalse(VenueReader::canReadAgain(41), 'no link while the setting is off');
    }
}
