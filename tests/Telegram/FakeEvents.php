<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;

/**
 * EventMesh events for the event card tests: fakes of post_type_exists,
 * get_posts, get_post, get_post_meta and get_permalink over $events.
 * TestCase pins today to 2026-07-20.
 */
trait FakeEvents
{
    /** @var array<int, array{post: object, meta: array<string, string>}> */
    private array $events = [];

    private function fakeEvents(): void
    {
        $this->events = [];

        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_posts')->alias(
            fn (): array => array_map(static fn (array $event): object => $event['post'], array_values($this->events))
        );
        Functions\when('get_post')->alias(fn (int $id): ?object => $this->events[$id]['post'] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key): string => $this->events[$id]['meta'][$key] ?? ''
        );
        Functions\when('get_permalink')->alias(static fn (int $id): string => 'https://site.test/page-' . $id . '/');
    }

    /** @param array<string, string> $meta */
    private function event(
        int $id,
        string $date,
        string $title,
        array $meta = [],
        string $content = '',
        string $status = 'publish'
    ): void {
        $this->events[$id] = [
            'post' => (object) [
                'ID' => $id,
                'post_type' => 'eventmesh_event',
                'post_title' => $title,
                'post_status' => $status,
                'post_content' => $content,
            ],
            'meta' => ['_eventmesh_starts_at' => $date . 'T21:00:00'] + $meta,
        ];
    }
}
