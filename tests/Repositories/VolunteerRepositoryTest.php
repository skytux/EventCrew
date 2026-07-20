<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\VolunteerRepository;
use EventCrew\Tests\TestCase;

final class VolunteerRepositoryTest extends TestCase
{
    /**
     * Email is the identity that merges a Telegram signup with a web signup,
     * so two rows differing only in case would split one person's history -
     * and therefore the credits they have earned - across two records.
     */
    public function testLowercasesTheEmailOnLookup(): void
    {
        $this->wpdb->nextRows = [null];

        (new VolunteerRepository())->findByEmail('  Ana@Example.TEST ');

        self::assertStringContainsString("'ana@example.test'", $this->wpdb->lastQuery());
    }

    public function testLowercasesTheEmailOnCreate(): void
    {
        (new VolunteerRepository())->create(['email' => 'Ana@Example.TEST']);

        self::assertSame('ana@example.test', $this->wpdb->inserts[0]['data']['email']);
    }

    public function testLowercasesTheEmailOnUpdate(): void
    {
        (new VolunteerRepository())->update(4, ['email' => 'NEW@Example.TEST']);

        self::assertSame('new@example.test', $this->wpdb->updates[0]['data']['email']);
    }

    /**
     * An organizer adding somebody by hand has not verified that address and
     * cannot consent on their behalf, so a new row starts with neither.
     */
    public function testCreatesWithoutVerificationOrConsentByDefault(): void
    {
        (new VolunteerRepository())->create(['email' => 'sam@example.test']);

        $data = $this->wpdb->inserts[0]['data'];

        self::assertNull($data['email_verified_at']);
        self::assertNull($data['email_opt_in_at']);
        self::assertSame('', $data['email_opt_in_source']);
    }

    public function testRecordsWhereConsentWasGivenAlongsideWhen(): void
    {
        (new VolunteerRepository())->recordEmailOptIn(4, 'telegram');

        $data = $this->wpdb->updates[0]['data'];

        self::assertNotEmpty($data['email_opt_in_at']);
        self::assertSame('telegram', $data['email_opt_in_source']);
    }

    /**
     * Unsubscribing has to clear the timestamp, not just the source - the
     * timestamp is what every send checks.
     */
    public function testWithdrawingConsentClearsTheTimestamp(): void
    {
        (new VolunteerRepository())->withdrawEmailOptIn(4);

        $data = $this->wpdb->updates[0]['data'];

        self::assertNull($data['email_opt_in_at']);
        self::assertSame('', $data['email_opt_in_source']);
    }

    public function testTouchesTheUpdatedTimestampOnEveryWrite(): void
    {
        (new VolunteerRepository())->update(4, ['display_name' => 'Ana']);

        self::assertNotEmpty($this->wpdb->updates[0]['data']['updated_at']);
    }

    public function testIgnoresAnUnknownSortColumn(): void
    {
        $this->wpdb->nextResults = [[]];

        (new VolunteerRepository())->all(['orderby' => 'id; DROP TABLE wp_users']);

        self::assertStringContainsString('ORDER BY display_name', $this->wpdb->lastQuery());
    }
}
