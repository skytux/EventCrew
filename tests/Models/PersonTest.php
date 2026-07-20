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
            'email_opt_in_at' => '2026-07-01 10:05:00',
            'email_opt_in_source' => 'telegram',
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
            'email_opt_in_at' => '',
        ]);

        self::assertNull($person->emailVerifiedAt);
        self::assertNull($person->telegramUserId);
        self::assertNull($person->emailOptInAt);
        self::assertFalse($person->isEmailVerified());
        self::assertFalse($person->hasTelegram());
    }

    /**
     * The open-task email is opt-in, so the absence of a consent record must
     * read as "no". This test exists to fail loudly if that condition is ever
     * inverted or loosened, because the failure mode is mailing people who
     * never agreed to it.
     */
    public function testDoesNotAcceptOpenTaskEmailWithoutAnOptInRecord(): void
    {
        $person = Person::fromRow([
            'id' => 1,
            'email' => 'sam@example.test',
            'email_verified_at' => '2026-07-01 10:00:00',
            'email_opt_in_at' => null,
        ]);

        self::assertFalse($person->acceptsOpenTaskEmail());
    }

    /**
     * Opting in through an address nobody has proved they control would let
     * one person sign another up for mail, so verification is required too.
     */
    public function testDoesNotAcceptOpenTaskEmailWhileTheAddressIsUnverified(): void
    {
        $person = Person::fromRow([
            'id' => 1,
            'email' => 'sam@example.test',
            'email_verified_at' => null,
            'email_opt_in_at' => '2026-07-01 10:05:00',
        ]);

        self::assertFalse($person->acceptsOpenTaskEmail());
    }

    public function testAcceptsOpenTaskEmailOnlyWhenVerifiedAndOptedIn(): void
    {
        $person = Person::fromRow([
            'id' => 1,
            'email' => 'sam@example.test',
            'email_verified_at' => '2026-07-01 10:00:00',
            'email_opt_in_at' => '2026-07-01 10:05:00',
        ]);

        self::assertTrue($person->acceptsOpenTaskEmail());
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
}
