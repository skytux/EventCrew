<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\NewsletterHistoryRepository;
use EventCrew\Tests\TestCase;

final class NewsletterHistoryRepositoryTest extends TestCase
{
    /** A join or leave is stored as a time and a kind only; anything else is ignored. */
    public function testAChangeIsATimeAndAKindOnly(): void
    {
        $history = new NewsletterHistoryRepository();

        $history->recordChange(NewsletterHistoryRepository::SUBSCRIBED);
        $history->recordChange(NewsletterHistoryRepository::UNSUBSCRIBED);
        $history->recordChange('fan@example.test');

        $tables = array_unique(array_column($this->wpdb->inserts, 'table'));

        self::assertSame(['wp_eventcrew_newsletter_changes'], $tables);
        self::assertSame(
            [
                ['created_at' => '2026-07-20 12:00:00', 'kind' => 'subscribed'],
                ['created_at' => '2026-07-20 12:00:00', 'kind' => 'unsubscribed'],
            ],
            array_column($this->wpdb->inserts, 'data')
        );
    }

    public function testChangesAreCountedPerDay(): void
    {
        $this->wpdb->nextResults[] = [
            ['day' => '2026-07-18', 'kind' => 'subscribed', 'n' => '3'],
            ['day' => '2026-07-18', 'kind' => 'unsubscribed', 'n' => '1'],
            ['day' => '2026-07-20', 'kind' => 'subscribed', 'n' => '2'],
        ];

        self::assertSame(
            [
                '2026-07-18' => ['subscribed' => 3, 'unsubscribed' => 1],
                '2026-07-20' => ['subscribed' => 2, 'unsubscribed' => 0],
            ],
            (new NewsletterHistoryRepository())->changesPerDay('2026-07-01', '2026-07-20')
        );
        self::assertStringContainsString(
            "created_at >= '2026-07-01 00:00:00' AND created_at <= '2026-07-20 23:59:59'",
            $this->wpdb->lastQuery()
        );
    }

    public function testTheFirstJoinDayIsTheOldestJoin(): void
    {
        $history = new NewsletterHistoryRepository();

        $this->wpdb->nextVars[] = '2024-12-24 18:30:00';
        self::assertSame('2024-12-24', $history->firstJoinDay());
        self::assertStringContainsString(
            "SELECT MIN(created_at) FROM wp_eventcrew_newsletter_changes WHERE kind = 'subscribed'",
            $this->wpdb->lastQuery()
        );

        $this->wpdb->nextVars[] = null;
        self::assertNull($history->firstJoinDay());
    }

    /** A sent version is stored with its copy, and its counts are set later. */
    public function testASentVersionKeepsItsCopyAndCounts(): void
    {
        $history = new NewsletterHistoryRepository();

        self::assertSame(1, $history->addSend(7, 'fi', 'Tulevat tapahtumat', '<p>Hei!</p>'));
        self::assertSame(
            [
                'plan_id' => 7,
                'lang' => 'fi',
                'sent_at' => '2026-07-20 12:00:00',
                'subject' => 'Tulevat tapahtumat',
                'sent' => 0,
                'failed' => 0,
                'html' => '<p>Hei!</p>',
            ],
            $this->wpdb->inserts[0]['data']
        );

        $history->setCounts(1, 40, 2);
        $history->setCounts(0, 1, 1);

        self::assertCount(1, $this->wpdb->updates);
        self::assertSame(['sent' => 40, 'failed' => 2], $this->wpdb->updates[0]['data']);
        self::assertSame(['id' => 1], $this->wpdb->updates[0]['where']);
    }

    public function testTheListLeavesTheCopyOut(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => '3', 'plan_id' => '7', 'lang' => 'en', 'sent_at' => '2026-07-20 12:00:00',
                'subject' => 'Coming events', 'sent' => '5', 'failed' => '0'],
        ];

        self::assertSame(
            [[
                'id' => 3,
                'plan_id' => 7,
                'lang' => 'en',
                'sent_at' => '2026-07-20 12:00:00',
                'subject' => 'Coming events',
                'sent' => 5,
                'failed' => 0,
            ]],
            (new NewsletterHistoryRepository())->sentList()
        );
        self::assertStringNotContainsString('html', $this->wpdb->lastQuery());
    }

    public function testACopyIsReadById(): void
    {
        $history = new NewsletterHistoryRepository();

        $this->wpdb->nextRows[] = ['subject' => 'Coming events', 'html' => '<p>Hi!</p>'];
        self::assertSame(['subject' => 'Coming events', 'html' => '<p>Hi!</p>'], $history->copy(3));
        self::assertStringContainsString('WHERE id = 3', $this->wpdb->lastQuery());

        self::assertNull($history->copy(4));
        self::assertNull($history->copy(0));
    }
}
