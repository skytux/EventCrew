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
        self::assertSame(['status' => 'unsubscribed', 'name' => null], $this->wpdb->updates[0]['data']);
        self::assertSame([], $this->wpdb->deletes);
    }

    /** A sign-up from the website stores the browser's language on a new row. */
    public function testANewAddressKeepsTheBrowserLanguage(): void
    {
        (new NewsletterSubscriberRepository())->addPending('fan@example.test', 'fi');

        self::assertSame('fi', $this->wpdb->inserts[0]['data']['language']);
    }

    /** A language chosen on the manage page is not replaced by a browser. */
    public function testAnExistingLanguageIsNotOverwrittenBySigningUpAgain(): void
    {
        $this->wpdb->nextRows[] = ['language' => 'en']
            + self::row(5, NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED);

        (new NewsletterSubscriberRepository())->addPending('fan@example.test', 'fi');

        self::assertArrayNotHasKey('language', $this->wpdb->updates[0]['data']);
    }

    /** The thank-you button makes a new address a confirmed subscriber, with the first name. */
    public function testATicketHolderIsAddedConfirmedWithTheirFirstName(): void
    {
        $id = (new NewsletterSubscriberRepository())->addFromTicket('Bo@Example.test', 'Bo', 'en');

        self::assertSame(1, $id);
        self::assertSame(
            [
                'email' => 'bo@example.test',
                'status' => 'confirmed',
                'created_at' => '2026-07-20 12:00:00',
                'confirmed_at' => '2026-07-20 12:00:00',
                'name' => 'Bo',
                'language' => 'en',
            ],
            $this->wpdb->inserts[0]['data']
        );
    }

    /** A pending sign-up is confirmed by the button, and its empty name is filled. */
    public function testATicketHolderWithAPendingRowIsConfirmed(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_PENDING);

        self::assertSame(5, (new NewsletterSubscriberRepository())->addFromTicket('fan@example.test', 'Anna'));
        self::assertSame(
            ['status' => 'confirmed', 'confirmed_at' => '2026-07-20 12:00:00', 'name' => 'Anna'],
            $this->wpdb->updates[0]['data']
        );
        self::assertSame(['subscribed'], $this->changeKinds());
    }

    /** The join and leave events recorded for the Newsletter page, oldest first. @return list<string> */
    private function changeKinds(): array
    {
        $kinds = [];

        foreach ($this->wpdb->inserts as $insert) {
            if ('wp_eventcrew_newsletter_changes' === $insert['table']) {
                self::assertSame(['created_at', 'kind'], array_keys($insert['data']), 'no address or name');
                $kinds[] = (string) $insert['data']['kind'];
            }
        }

        return $kinds;
    }

    /** A sign-up that is not confirmed yet is not a join. */
    public function testAPendingSignUpRecordsNoJoin(): void
    {
        (new NewsletterSubscriberRepository())->addPending('fan@example.test');

        self::assertSame([], $this->changeKinds());
    }

    /** Confirming records a join; an old link on a row that left records nothing. */
    public function testConfirmingRecordsOneJoin(): void
    {
        $repository = new NewsletterSubscriberRepository();

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_PENDING);
        $repository->confirm(5);
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        $repository->confirm(5);
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED);
        $repository->confirm(5);

        self::assertSame(['subscribed'], $this->changeKinds());
        self::assertSame('2026-07-20 12:00:00', $this->wpdb->inserts[0]['data']['created_at']);
    }

    /** A leave is recorded for a confirmed subscriber only. */
    public function testOnlyAConfirmedSubscriberLeavingIsRecorded(): void
    {
        $repository = new NewsletterSubscriberRepository();

        $this->wpdb->nextRows[] = ['name' => null] + self::row(5, NewsletterSubscriberRepository::STATUS_PENDING);
        $repository->unsubscribe(5);
        self::assertSame([], $this->changeKinds());

        $this->wpdb->nextRows[] = ['name' => 'Anna'] + self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        $repository->unsubscribe(5);
        self::assertSame(['unsubscribed'], $this->changeKinds());
    }

    /** The ticket button and the crew sign-up each record a join for a new address. */
    public function testTicketAndCrewSignUpsRecordAJoin(): void
    {
        $repository = new NewsletterSubscriberRepository();

        self::assertSame(1, $repository->addFromTicket('bo@example.test', 'Bo'));
        $repository->addCrewMember('cy@example.test', 'Cy', 'fi');

        self::assertSame(['subscribed', 'subscribed'], $this->changeKinds());
    }

    /** A ticket holder who is already subscribed does not join twice. */
    public function testAConfirmedTicketHolderRecordsNoJoin(): void
    {
        $this->wpdb->nextRows[] = ['name' => 'Bo'] + self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        (new NewsletterSubscriberRepository())->addFromTicket('fan@example.test', 'Bo');

        self::assertSame([], $this->changeKinds());
    }

    /** The counts per state, with a state that has no rows as 0. */
    public function testCountByStatusCountsEachState(): void
    {
        $this->wpdb->nextResults[] = [
            ['status' => 'confirmed', 'n' => '7'],
            ['status' => 'unsubscribed', 'n' => '2'],
        ];

        self::assertSame(
            ['confirmed' => 7, 'pending' => 0, 'unsubscribed' => 2],
            (new NewsletterSubscriberRepository())->countByStatus()
        );
        self::assertStringContainsString('GROUP BY status', $this->wpdb->lastQuery());
    }

    /** A name the person typed in is never replaced by the one on a ticket. */
    public function testATicketNameDoesNotReplaceAChosenName(): void
    {
        $this->wpdb->nextRows[] = ['name' => 'Annie', 'language' => 'fi']
            + self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        self::assertSame(5, (new NewsletterSubscriberRepository())->addFromTicket('fan@example.test', 'Anna', 'en'));
        self::assertSame([], $this->wpdb->updates);
    }

    /** A confirmed row with no name gets the ticket's first name and nothing else. */
    public function testAConfirmedRowWithoutANameGetsTheTicketName(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        (new NewsletterSubscriberRepository())->addFromTicket('fan@example.test', 'Anna');

        self::assertSame(['name' => 'Anna'], $this->wpdb->updates[0]['data']);
    }

    /** Someone who had left joins again by pressing the button. */
    public function testALeftTicketHolderIsSubscribedAgain(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED);

        (new NewsletterSubscriberRepository())->addFromTicket('fan@example.test', '');

        self::assertSame(
            ['status' => 'confirmed', 'confirmed_at' => '2026-07-20 12:00:00'],
            $this->wpdb->updates[0]['data']
        );
    }

    public function testSetNameStoresACleanNameAndClearsAnEmptyOne(): void
    {
        $repository = new NewsletterSubscriberRepository();

        self::assertTrue($repository->setName(5, "  <b>Anna</b>\n  Maria "));
        self::assertSame(['name' => 'Anna Maria'], $this->wpdb->updates[0]['data']);

        self::assertTrue($repository->setName(5, '   '));
        self::assertSame(['name' => null], $this->wpdb->updates[1]['data']);

        self::assertFalse($repository->setName(0, 'Anna'));
        self::assertCount(2, $this->wpdb->updates);
    }

    public function testCleanNameCutsALongName(): void
    {
        $name = NewsletterSubscriberRepository::cleanName(str_repeat('ä', 150));

        self::assertSame(100, mb_strlen((string) $name));
        self::assertNull(NewsletterSubscriberRepository::cleanName("\u{200B}\t"));
    }

    public function testSetLanguageAcceptsOnlyFinnishEnglishOrNothing(): void
    {
        $repository = new NewsletterSubscriberRepository();

        self::assertTrue($repository->setLanguage(5, 'en'));
        self::assertTrue($repository->setLanguage(5, ''));
        self::assertFalse($repository->setLanguage(5, 'sv'));

        self::assertSame(
            [['language' => 'en'], ['language' => '']],
            array_column($this->wpdb->updates, 'data')
        );
    }

    public function testDeleteByEmailRemovesTheRow(): void
    {
        $this->wpdb->nextQueryResults[] = 1;

        self::assertSame(1, (new NewsletterSubscriberRepository())->deleteByEmail(' Fan@Example.test'));
        self::assertSame(
            "DELETE FROM wp_eventcrew_newsletter_subscribers WHERE email = 'fan@example.test'",
            $this->wpdb->lastQuery()
        );
    }

    /**
     * The privacy eraser deleting a confirmed subscriber records a leave, as
     * an unsubscribe does; a pending row, an unsubscribed row, or a delete
     * that removed nothing records none.
     */
    public function testErasingAConfirmedSubscriberRecordsALeave(): void
    {
        $repository = new NewsletterSubscriberRepository();

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        $this->wpdb->nextQueryResults[] = 1;
        self::assertSame(1, $repository->deleteByEmail('fan@example.test'));
        self::assertSame(['unsubscribed'], $this->changeKinds());

        $this->wpdb->nextRows[] = self::row(6, NewsletterSubscriberRepository::STATUS_PENDING);
        $this->wpdb->nextQueryResults[] = 1;
        $repository->deleteByEmail('fan@example.test');

        $this->wpdb->nextRows[] = self::row(7, NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED);
        $this->wpdb->nextQueryResults[] = 1;
        $repository->deleteByEmail('fan@example.test');

        $this->wpdb->nextRows[] = self::row(8, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        $this->wpdb->nextQueryResults[] = 0;
        self::assertSame(0, $repository->deleteByEmail('fan@example.test'));

        self::assertSame(['unsubscribed'], $this->changeKinds());
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
        $this->wpdb->nextResults[] = [
            ['id' => '7', 'email' => 'fan@example.test', 'name' => null, 'language' => ''],
            ['id' => '8', 'email' => 'bo@example.test', 'name' => 'Bo', 'language' => 'fi', 'crew' => '1'],
        ];

        $rows = (new NewsletterSubscriberRepository())->confirmedAfter(6, 20);

        self::assertSame(
            [
                ['id' => 7, 'email' => 'fan@example.test', 'name' => null, 'language' => '', 'crew' => false],
                ['id' => 8, 'email' => 'bo@example.test', 'name' => 'Bo', 'language' => 'fi', 'crew' => true],
            ],
            $rows
        );
        self::assertStringContainsString(
            "s.status = 'confirmed' AND s.id > 6 ORDER BY s.id ASC LIMIT 20",
            $this->wpdb->lastQuery()
        );
        // A crew member is a person with the subscriber's verified address.
        self::assertStringContainsString(
            'FROM wp_eventcrew_people p WHERE p.email = s.email AND p.email_verified_at IS NOT NULL',
            $this->wpdb->lastQuery()
        );
    }
}
