<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\RoleNotifier;
use EventCrew\Support\Roles;

/**
 * The organizers hear, by Telegram and by email, the first time someone meets
 * a role's conditions and the first time someone is given a role - once per
 * person per role.
 */
final class RoleNotifierTest extends TelegramTestCase
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );

        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'welcome', 'label' => 'Welcome'],
            ['slug' => 'clean', 'label' => 'Clean'],
            [
                'slug' => 'leader',
                'label' => 'Crew leader',
                'restricted' => true,
                'conditions' => ['counts' => ['welcome' => 2]],
            ],
        ];
    }

    private function notifier(): RoleNotifier
    {
        $people = new PersonRepository();

        return new RoleNotifier(
            new RoleConditions(new AssignmentRepository(), $people),
            $people,
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            $this->client()
        );
    }

    /** @return array<string, mixed> */
    private function organizerRow(): array
    {
        return ['id' => 1, 'display_name' => 'Owner', 'email' => 'owner@example.com', 'is_organizer' => 1, 'telegram_chat_id' => 500];
    }

    public function testSomeoneWhoNewlyMeetsTheConditionsIsAnnouncedByTelegramAndEmailAndRecorded(): void
    {
        // organizers(); people->all; completedByRole; markRoleNotice's find.
        $this->wpdb->nextResults[] = [$this->organizerRow()];
        $this->wpdb->nextResults[] = [['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com']];
        $this->wpdb->nextResults[] = [['role_slug' => 'welcome', 'n' => 2]];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];

        $this->notifier()->run();

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(500, $sent['chat_id']);
        self::assertStringContainsString('Jo now meets the conditions for the role', $sent['text']);
        self::assertStringContainsString('Crew leader', $sent['text']);

        self::assertCount(1, $this->mails);
        self::assertSame('owner@example.com', $this->mails[0]['to']);

        self::assertCount(1, $this->wpdb->updates);
        self::assertSame(8, $this->wpdb->updates[0]['where']['id']);
        self::assertArrayHasKey('met:leader', json_decode($this->wpdb->updates[0]['data']['role_notices'], true));
    }

    public function testSomeoneAlreadyAnnouncedIsNotAnnouncedAgain(): void
    {
        $this->wpdb->nextResults[] = [$this->organizerRow()];
        $this->wpdb->nextResults[] = [[
            'id' => 8,
            'display_name' => 'Jo',
            'email' => 'jo@example.com',
            'role_notices' => '{"met:leader":"2026-07-01 12:00:00"}',
        ]];
        $this->wpdb->nextResults[] = [['role_slug' => 'welcome', 'n' => 4]];

        $this->notifier()->run();

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testSomeoneWhoAlreadyHoldsTheRoleIsNotAnnouncedAsMeetingIt(): void
    {
        $this->wpdb->nextResults[] = [$this->organizerRow()];
        $this->wpdb->nextResults[] = [['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com', 'role_slugs' => 'leader']];
        $this->wpdb->nextResults[] = [['role_slug' => 'welcome', 'n' => 4]];

        $this->notifier()->run();

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }

    public function testBeingGivenARoleIsAnnouncedOnceByTelegramAndEmail(): void
    {
        // find(person); organizers(); markRoleNotice's find.
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];
        $this->wpdb->nextResults[] = [$this->organizerRow()];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];

        $this->notifier()->roleGiven(8, 'leader');

        self::assertStringContainsString('Jo was given the role', $this->lastCallTo('sendMessage')['text']);
        self::assertCount(1, $this->mails);
        self::assertArrayHasKey('given:leader', json_decode($this->wpdb->updates[0]['data']['role_notices'], true));
    }

    public function testBeingGivenTheSameRoleAgainIsNotAnnounced(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 8,
            'display_name' => 'Jo',
            'email' => 'jo@example.com',
            'role_notices' => '{"given:leader":"2026-07-01 12:00:00"}',
        ];

        $this->notifier()->roleGiven(8, 'leader');

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testOnlyNewlyAddedRolesFireTheGivenHook(): void
    {
        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, ...$args];
        });

        RoleNotifier::rolesChanged(8, ['dj'], ['dj', 'leader', Roles::ORGANIZE_SLUG], 'Owner');

        self::assertSame([[RoleNotifier::GIVEN_HOOK, 8, 'leader', 'Owner']], $fired);
    }

    public function testTheRoleNoticeNamesWhoGaveTheRole(): void
    {
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];
        $this->wpdb->nextResults[] = [$this->organizerRow()];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];

        $this->notifier()->roleGiven(8, 'leader', 'Sam');

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('Jo was given the role Crew leader by Sam.', $text);
        self::assertStringContainsString('by Sam', $this->mails[0]['body']);
    }

    public function testBeingMadeAnOrganizerIsAnnouncedToTheOtherOrganizersWithWhoDidIt(): void
    {
        // find(person); organizers() - Jo is one by now; markRoleNotice's find.
        $jo = [
            'id' => 8,
            'display_name' => 'Jo',
            'email' => 'jo@example.com',
            'is_organizer' => 1,
            'telegram_chat_id' => 800,
        ];
        $this->wpdb->nextRows[] = $jo;
        $this->wpdb->nextResults[] = [$this->organizerRow(), $jo];
        $this->wpdb->nextRows[] = $jo;

        $this->notifier()->organizerGiven(8, 'Sam');

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(500, $sent['chat_id']);
        self::assertStringContainsString('Jo was made an organizer by Sam.', $sent['text']);
        self::assertCount(1, $this->mails);
        self::assertSame('owner@example.com', $this->mails[0]['to']);
        self::assertArrayHasKey('made:organizer', json_decode($this->wpdb->updates[0]['data']['role_notices'], true));
    }

    public function testBeingMadeAnOrganizerAgainIsNotAnnounced(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 8,
            'display_name' => 'Jo',
            'email' => 'jo@example.com',
            'role_notices' => '{"made:organizer":"2026-07-01 12:00:00"}',
        ];

        $this->notifier()->organizerGiven(8, 'Sam');

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }
}
