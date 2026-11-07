<?php

declare(strict_types=1);

namespace EventCrew\Tests\Repositories;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Tests\TestCase;

final class PersonRepositoryTest extends TestCase
{
    /**
     * The welcome message hangs off this action, so every way an address gets
     * confirmed reaches it.
     */
    public function testConfirmingAnEmailAnnouncesIt(): void
    {
        $fired = [];
        Functions\when('do_action')->alias(
            static function (string $hook, mixed ...$args) use (&$fired): void {
                $fired[] = [$hook, $args];
            }
        );

        (new PersonRepository())->markEmailVerified(4);

        self::assertSame([['eventcrew/email_verified', [4]]], $fired);
    }

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
     * Only a newcomer who signs themselves up waits for the team's OK; any
     * other create leaves approval out, so the column default '' (OK) holds.
     */
    public function testOnlyAWaitingApprovalIsWrittenOnCreate(): void
    {
        $repo = new PersonRepository();
        $repo->create(['email' => 'sam@example.test']);
        $repo->create(['email' => 'new@example.test', 'approval' => 'waiting']);
        $repo->create(['email' => 'odd@example.test', 'approval' => 'no']);

        self::assertArrayNotHasKey('approval', $this->wpdb->inserts[0]['data']);
        self::assertSame('waiting', $this->wpdb->inserts[1]['data']['approval']);
        self::assertArrayNotHasKey('approval', $this->wpdb->inserts[2]['data']);
    }

    /** The conditional update is what makes the first organizer's press the one that counts. */
    public function testDecidingANewcomerOnlyChangesSomeoneStillWaiting(): void
    {
        $repo = new PersonRepository();

        $this->wpdb->nextQueryResults[] = 1;
        self::assertTrue($repo->decideNewcomer(8, true, 'Kim'));
        self::assertStringContainsString("WHERE id = 8 AND approval = 'waiting'", $this->wpdb->lastQuery());

        $this->wpdb->nextQueryResults[] = 0;
        self::assertFalse($repo->decideNewcomer(8, false, 'Alex'));
    }

    public function testReplacingRoleNoticesKeepsTheOtherEntries(): void
    {
        $repo = new PersonRepository();
        $this->wpdb->nextRows[] = ['id' => 8, 'role_notices' => '{"newcomer:asked":"x","newcomer:times":"9"}'];

        $repo->replaceRoleNotices(8, ['newcomer:before', 'newcomer:times'], ['newcomer:before' => 'no']);

        self::assertSame(
            ['newcomer:asked' => 'x', 'newcomer:before' => 'no'],
            json_decode($this->wpdb->updates[0]['data']['role_notices'], true)
        );
        self::assertSame(['id' => 8], $this->wpdb->updates[0]['where']);
    }

    public function testReplacingRoleNoticesWritesNothingWhenNothingChanges(): void
    {
        $repo = new PersonRepository();
        $this->wpdb->nextRows[] = ['id' => 8, 'role_notices' => '{"newcomer:asked":"x"}'];
        $this->wpdb->nextRows[] = null;

        $repo->replaceRoleNotices(8, ['newcomer:before']);
        $repo->replaceRoleNotices(9, ['newcomer:before'], ['newcomer:before' => 'yes']);

        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheWaitingListHasOnlyConfirmedNewcomers(): void
    {
        $this->wpdb->nextResults[] = [['id' => 8, 'email' => 'sam@example.test', 'approval' => 'waiting']];

        $list = (new PersonRepository())->waitingNewcomers();

        self::assertCount(1, $list);
        self::assertTrue($list[0]->isWaitingForOk());
        self::assertStringContainsString(
            "approval = 'waiting' AND email_verified_at IS NOT NULL AND anonymized_at IS NULL",
            $this->wpdb->lastQuery()
        );
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
