<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
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
                'wp_eventcrew_assignments',
                'wp_eventcrew_redemptions',
                'wp_eventcrew_credit_grants',
                'wp_eventcrew_people',
            ],
            $deletedTables
        );
    }
}
