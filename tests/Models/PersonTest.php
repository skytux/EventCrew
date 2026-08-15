<?php

declare(strict_types=1);

namespace EventCrew\Tests\Models;

use EventCrew\Models\Person;
use EventCrew\Tests\TestCase;

final class PersonTest extends TestCase
{
    public function testHydratesFromADatabaseRow(): void
    {
        $person = Person::fromRow([
            'id' => '7',
            'email' => 'ana@example.test',
            'display_name' => 'Ana',
            'email_verified_at' => '2026-07-01 10:00:00',
            'telegram_user_id' => '123456',
            'telegram_chat_id' => '123456',
            'is_organizer' => '1',
            'notes' => 'Brings her own ladder',
            'created_at' => '2026-06-01 09:00:00',
            'updated_at' => '2026-07-01 10:05:00',
        ]);

        self::assertSame(7, $person->id);
        self::assertSame('ana@example.test', $person->email);
        self::assertSame(123456, $person->telegramUserId);
        self::assertTrue($person->isOrganizer);
        self::assertTrue($person->isEmailVerified());
        self::assertTrue($person->hasTelegram());
    }

    public function testTreatsEmptyTimestampColumnsAsNull(): void
    {
        // MySQL hands back '' rather than null for some drivers and column
        // types, and a '' timestamp read as "present" would wrongly mark an
        // unverified person as verified.
        $person = Person::fromRow([
            'id' => 1,
            'email' => 'sam@example.test',
            'email_verified_at' => '',
            'telegram_user_id' => '',
            'sessions_valid_from' => '',
        ]);

        self::assertNull($person->emailVerifiedAt);
        self::assertNull($person->telegramUserId);
        self::assertNull($person->sessionsValidFrom);
        self::assertFalse($person->isEmailVerified());
        self::assertFalse($person->hasTelegram());
    }

    public function testFallsBackToTheEmailLocalPartWhenNoNameWasGiven(): void
    {
        $person = Person::fromRow([
            'id' => 1,
            'email' => 'pat.lee@example.test',
            'display_name' => '',
        ]);

        self::assertSame('pat.lee', $person->name());
    }

    public function testPrefersTheDisplayNameWhenPresent(): void
    {
        $person = Person::fromRow([
            'id' => 1,
            'email' => 'pat.lee@example.test',
            'display_name' => 'Pat',
        ]);

        self::assertSame('Pat', $person->name());
    }

    /** Nobody has revoked anything, so every session is still theirs. */
    public function testEverySessionIsAcceptedUntilOneIsRevoked(): void
    {
        $person = Person::fromRow(['id' => 1, 'email' => 'sam@example.test']);

        self::assertTrue($person->acceptsSessionIssuedAt(0));
        self::assertTrue($person->acceptsSessionIssuedAt(time()));
    }

    /**
     * The line, once moved: cookies issued before it are refused, ones issued
     * after are not. A legacy cookie's issue time of 0 falls on the refused
     * side, which is what retires it.
     */
    public function testRevocationRefusesOnlyTheSessionsIssuedBeforeIt(): void
    {
        $person = Person::fromRow([
            'id' => 1,
            'email' => 'sam@example.test',
            'sessions_valid_from' => '2026-07-20 12:00:00',
        ]);

        $revokedAt = (int) strtotime('2026-07-20 12:00:00');

        self::assertFalse($person->acceptsSessionIssuedAt($revokedAt - 1));
        self::assertFalse($person->acceptsSessionIssuedAt(0));
        self::assertTrue($person->acceptsSessionIssuedAt($revokedAt));
        self::assertTrue($person->acceptsSessionIssuedAt($revokedAt + 1));
    }
}
