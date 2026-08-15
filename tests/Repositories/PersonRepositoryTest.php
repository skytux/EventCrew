<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Tests\TestCase;

final class PersonRepositoryTest extends TestCase
{
    /**
     * Email is the identity that merges a Telegram signup with a web signup,
     * so two rows differing only in case would split one person's history -
     * and therefore the credits they have earned - across two records.
     */
    public function testLowercasesTheEmailOnLookup(): void
    {
        $this->wpdb->nextRows = [null];

        (new PersonRepository())->findByEmail('  Ana@Example.TEST ');

        self::assertStringContainsString("'ana@example.test'", $this->wpdb->lastQuery());
    }

    public function testLowercasesTheEmailOnCreate(): void
    {
        (new PersonRepository())->create(['email' => 'Ana@Example.TEST']);

        self::assertSame('ana@example.test', $this->wpdb->inserts[0]['data']['email']);
    }

    public function testLowercasesTheEmailOnUpdate(): void
    {
        (new PersonRepository())->update(4, ['email' => 'NEW@Example.TEST']);

        self::assertSame('new@example.test', $this->wpdb->updates[0]['data']['email']);
    }

    /**
     * The open-task recipient policy never mails an unverified address, since
     * that inbox isn't proven theirs - this test would fail if that clause were
     * ever dropped and people who shouldn't be mailed slipped in.
     */
    public function testActiveEmailRecipientsRequireAVerifiedAddress(): void
    {
        $this->wpdb->nextResults = [[]];

        (new PersonRepository())->activeEmailRecipients();

        $sql = $this->wpdb->lastQuery();
        self::assertStringContainsString('email_verified_at IS NOT NULL', $sql);
    }

    /**
     * An organizer adding somebody by hand has not verified that address, so a
     * new row starts unverified and stays out of every send that checks.
     */
    public function testCreatesWithoutVerificationByDefault(): void
    {
        (new PersonRepository())->create(['email' => 'sam@example.test']);

        $data = $this->wpdb->inserts[0]['data'];

        self::assertNull($data['email_verified_at']);
    }

    /**
     * Revoking is one write moving one line: every cookie issued before this
     * moment stops being accepted. There is no session list to delete from -
     * that is the point of a stateless cookie - so the stamp is the whole
     * mechanism.
     */
    public function testRevokingSessionsStampsTheCutoff(): void
    {
        (new PersonRepository())->revokeSessions(4);

        $data = $this->wpdb->updates[0]['data'];

        self::assertNotEmpty($data['sessions_valid_from']);
        self::assertSame(['id' => 4], $this->wpdb->updates[0]['where']);
    }

    public function testTouchesTheUpdatedTimestampOnEveryWrite(): void
    {
        (new PersonRepository())->update(4, ['display_name' => 'Ana']);

        self::assertNotEmpty($this->wpdb->updates[0]['data']['updated_at']);
    }

    public function testIgnoresAnUnknownSortColumn(): void
    {
        $this->wpdb->nextResults = [[]];

        (new PersonRepository())->all(['orderby' => 'id; DROP TABLE wp_users']);

        self::assertStringContainsString('ORDER BY display_name', $this->wpdb->lastQuery());
    }
}
