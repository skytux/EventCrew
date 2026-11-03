<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Tests\TestCase;

final class NewsletterSubscriberRepositoryTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(int $id, string $status): array
    {
        return [
            'id' => $id,
            'email' => 'fan@example.test',
            'status' => $status,
            'created_at' => '2026-07-19 10:00:00',
            'confirmed_at' => null,
        ];
    }

    /** A new address is stored in lower case as pending, with no name. */
    public function testANewAddressIsStoredAsPending(): void
    {
        $id = (new NewsletterSubscriberRepository())->addPending('  Fan@Example.TEST ');

        self::assertSame(1, $id);
        self::assertSame(
            ['email' => 'fan@example.test', 'status' => 'pending', 'created_at' => '2026-07-20 12:00:00'],
            $this->wpdb->inserts[0]['data']
        );
    }

    public function testAConfirmedAddressIsLeftAsItIs(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        self::assertSame(5, (new NewsletterSubscriberRepository())->addPending('fan@example.test'));
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
    }

    /** Someone who left and joins again starts a fresh pending sign-up. */
    public function testALeftAddressBecomesPendingAgain(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED);

        self::assertSame(5, (new NewsletterSubscriberRepository())->addPending('fan@example.test'));
        self::assertSame('pending', $this->wpdb->updates[0]['data']['status']);
        self::assertSame('2026-07-20 12:00:00', $this->wpdb->updates[0]['data']['created_at']);
    }

    public function testATooLongAddressIsRefused(): void
    {
        $email = str_repeat('a', 190) . '@example.test';

        self::assertSame(0, (new NewsletterSubscriberRepository())->addPending($email));
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testConfirmTurnsOnlyAPendingRowIntoASubscriber(): void
    {
        $repository = new NewsletterSubscriberRepository();

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_PENDING);
        self::assertTrue($repository->confirm(5));
        self::assertSame('confirmed', $this->wpdb->updates[0]['data']['status']);

        // Someone who left cannot be put back by an old confirm link.
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED);
        self::assertFalse($repository->confirm(5));
        self::assertCount(1, $this->wpdb->updates);
    }

    public function testUnsubscribeKeepsTheRowMarkedAsLeft(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        self::assertTrue((new NewsletterSubscriberRepository())->unsubscribe(5));
        self::assertSame(['status' => 'unsubscribed'], $this->wpdb->updates[0]['data']);
        self::assertSame([], $this->wpdb->deletes);
    }

    public function testThePurgeDeletesOnlyOldPendingRows(): void
    {
        $this->wpdb->nextQueryResults[] = 2;

        self::assertSame(2, (new NewsletterSubscriberRepository())->purgePending('2026-07-13 12:00:00'));
        $sql = $this->wpdb->lastQuery();
        self::assertStringContainsString('DELETE FROM wp_eventcrew_newsletter_subscribers WHERE', $sql);
        self::assertStringContainsString("status = 'pending' AND created_at < '2026-07-13 12:00:00'", $sql);
    }

    public function testTheBatchReadsConfirmedRowsAfterTheCursor(): void
    {
        $this->wpdb->nextResults[] = [['id' => '7', 'email' => 'fan@example.test']];

        $rows = (new NewsletterSubscriberRepository())->confirmedAfter(6, 20);

        self::assertSame([['id' => 7, 'email' => 'fan@example.test']], $rows);
        self::assertStringContainsString(
            "status = 'confirmed' AND id > 6 ORDER BY id ASC LIMIT 20",
            $this->wpdb->lastQuery()
        );
    }
}
