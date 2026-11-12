<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\DoorNoteRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\PrivacyEraser;
use EventCrew\Tests\TestCase;

final class PrivacyEraserTest extends TestCase
{
    private function eraser(): PrivacyEraser
    {
        $people = new PersonRepository();

        return new PrivacyEraser(
            $people,
            new PersonEraser(
                $people,
                new AssignmentRepository(),
                new RedemptionRepository(),
                new CreditGrantRepository(),
                new AuthTokenRepository(),
                new NotificationsRepository()
            )
        );
    }

    public function testItRegistersItselfWithWordPress(): void
    {
        $erasers = $this->eraser()->register([]);

        self::assertArrayHasKey('eventcrew', $erasers);
        self::assertIsCallable($erasers['eventcrew']['callback']);
    }

    /**
     * An address nobody here has ever seen is not an error - core asks every
     * registered eraser about every request - so it reports a clean nothing.
     */
    public function testAnUnknownAddressRemovesNothing(): void
    {
        $this->wpdb->nextRows[] = null;

        $result = $this->eraser()->erase('stranger@example.test');

        self::assertFalse($result['items_removed']);
        self::assertFalse($result['items_retained']);
        self::assertTrue($result['done']);
        self::assertSame([], $this->wpdb->deletes);
    }

    /**
     * A known address takes the whole record with it, and nothing is held
     * back - there is no invoice or accounting row in here to weigh against
     * the request.
     */
    public function testAKnownAddressIsErasedEntirely(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'dancer@example.test',
            'display_name' => 'Dancer',
            'created_at' => '2020-01-01 00:00:00',
        ];

        $result = $this->eraser()->erase('dancer@example.test');

        self::assertTrue($result['items_removed']);
        self::assertFalse($result['items_retained']);
        self::assertTrue($result['done']);

        $deletedTables = array_column($this->wpdb->deletes, 'table');

        self::assertSame(
            [
                'wp_eventcrew_auth_tokens',
                'wp_eventcrew_notifications',
                'wp_eventcrew_takeovers',
                'wp_eventcrew_takeovers',
                'wp_eventcrew_assignments',
                'wp_eventcrew_redemptions',
                'wp_eventcrew_credit_grants',
                'wp_eventcrew_people',
            ],
            $deletedTables
        );

        // Takeover requests go both where the person held the task and where
        // they asked to take one over.
        $takeoverWheres = array_column(
            array_filter(
                $this->wpdb->deletes,
                static fn (array $d): bool => 'wp_eventcrew_takeovers' === $d['table']
            ),
            'where'
        );
        self::assertSame([['holder_id' => 7], ['cover_id' => 7]], $takeoverWheres);
    }

    public function testTheGroupChatMessagesGoAndTheQuotesOfThemAreEmptied(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'dancer@example.test', 'created_at' => '2020-01-01 00:00:00'];
        $people = new PersonRepository();
        $eraser = new PrivacyEraser($people, new PersonEraser(
            $people,
            new AssignmentRepository(),
            new RedemptionRepository(),
            new CreditGrantRepository(),
            new AuthTokenRepository(),
            new NotificationsRepository(),
            groupMessages: new \EventCrew\Repositories\GroupMessageRepository()
        ));

        $eraser->erase('dancer@example.test');

        self::assertContains(
            ['table' => 'wp_eventcrew_group_messages', 'where' => ['person_id' => 7]],
            $this->wpdb->deletes
        );
        $quotes = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $q): bool => str_contains($q, 'UPDATE wp_eventcrew_group_messages')
        ));
        self::assertCount(1, $quotes);
        self::assertStringContainsString("reply_text = ''", $quotes[0]);
        self::assertStringContainsString('WHERE reply_person_id = 7', $quotes[0]);
    }

    /**
     * An announcement subscriber's row is deleted entirely: it holds only the
     * address, the name to greet and the language.
     */
    public function testAnAnnouncementSubscriptionIsDeleted(): void
    {
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 0; // no ticket buyer rows
        $this->wpdb->nextQueryResults[] = 0; // no guest rows
        $this->wpdb->nextQueryResults[] = 1; // the subscriber row

        $result = $this->eraser()->erase('Fan@Example.test');

        self::assertTrue($result['items_removed']);
        self::assertCount(1, $result['messages']);
        self::assertStringContainsString('next events announcement', $result['messages'][0]);
        self::assertSame(
            "DELETE FROM wp_eventcrew_newsletter_subscribers WHERE email = 'fan@example.test'",
            $this->wpdb->lastQuery()
        );
    }

    /**
     * A ticket buyer who is not a crew member is erased from the ticket
     * buyers table by email: name and email go, the row stays.
     */
    public function testATicketBuyerWithNoCrewRecordIsErased(): void
    {
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 2;

        $result = $this->eraser()->erase('bo@example.test');

        self::assertTrue($result['items_removed']);
        self::assertFalse($result['items_retained']);
        self::assertCount(1, $result['messages']);
        self::assertContains(
            "UPDATE wp_eventcrew_ticket_buyers SET email = NULL, name = '' WHERE email = 'bo@example.test'",
            $this->wpdb->queries
        );
        self::assertSame([], $this->wpdb->deletes);
    }

    /**
     * A guest from a mailed guest list is erased by email too: name and
     * email go, the guest row stays.
     */
    public function testAGuestWithAnEmailIsErased(): void
    {
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 0; // no ticket buyer rows
        $this->wpdb->nextQueryResults[] = 1; // one guest row

        $result = $this->eraser()->erase('cy@example.test');

        self::assertTrue($result['items_removed']);
        self::assertCount(1, $result['messages']);
        self::assertStringContainsString('guest list', $result['messages'][0]);
        self::assertContains(
            "UPDATE wp_eventcrew_guests SET email = NULL, name = '' WHERE email = 'cy@example.test'",
            $this->wpdb->queries
        );
        self::assertSame([], $this->wpdb->deletes);
    }

    public function testDoorNotesUnderTheAddressAreDeleted(): void
    {
        $people = new PersonRepository();
        $eraser = new PrivacyEraser(
            $people,
            new PersonEraser(
                $people,
                new AssignmentRepository(),
                new RedemptionRepository(),
                new CreditGrantRepository(),
                new AuthTokenRepository(),
                new NotificationsRepository()
            ),
            doorNotes: new DoorNoteRepository()
        );
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 0; // no ticket buyer rows
        $this->wpdb->nextQueryResults[] = 0; // no guest rows
        $this->wpdb->nextQueryResults[] = 2; // two door notes

        $result = $eraser->erase('aino@example.org');

        self::assertTrue($result['items_removed']);
        self::assertSame(
            ['The names and email addresses the door crew noted under this address were deleted.'],
            $result['messages']
        );
        self::assertContains(
            "DELETE FROM wp_eventcrew_door_notes WHERE email = 'aino@example.org'",
            $this->wpdb->queries
        );
    }
}
