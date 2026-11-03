<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssistantKnowledge;
use EventCrew\Tests\TestCase;

final class AssistantKnowledgeTest extends TestCase
{
    /** @var array<string, string> */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->meta = ['_eventmesh_starts_at' => '2026-08-01T21:00:00'];
        $post = (object) [
            'ID' => 12,
            'post_type' => 'eventmesh_event',
            'post_status' => 'publish',
            'post_title' => 'Summer Dance',
            'post_content' => '',
            'post_excerpt' => '',
        ];

        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_posts')->justReturn([$post]);
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(fn (int $id, string $key): string => $this->meta[$key] ?? '');
        Functions\when('get_permalink')->justReturn('https://example.test/event/summer-dance/');
        Functions\when('get_post_field')->justReturn('');
        Functions\when('wp_strip_all_tags')->alias(static fn (string $text): string => strip_tags($text));
        Functions\when('strip_shortcodes')->returnArg();
    }

    private function eventsText(): string
    {
        $method = new \ReflectionMethod(AssistantKnowledge::class, 'eventsText');

        return (string) $method->invoke(new AssistantKnowledge(new TaskRepository()));
    }

    /** The coming event's line gives the ticket price the event card shows. */
    public function testAComingEventGivesItsTicketPrice(): void
    {
        $this->meta['_eventmesh_price'] = '22.00 EUR';

        self::assertSame(
            '- 2026-08-01: Summer Dance, starts 21:00, ticket 22 €, https://example.test/event/summer-dance/',
            $this->eventsText()
        );
    }

    /** An event without a price says nothing about one. */
    public function testAnEventWithoutAPriceHasNoTicketWords(): void
    {
        $text = $this->eventsText();

        self::assertSame('- 2026-08-01: Summer Dance, starts 21:00, https://example.test/event/summer-dance/', $text);
        self::assertStringNotContainsString('ticket', $text);
    }
}
