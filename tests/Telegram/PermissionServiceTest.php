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
use EventCrew\Support\Roles;
use EventCrew\Telegram\PermissionService;

/**
 * The organizer-only /roles grant (old name /allow). The tap (onSelect) is
 * where a permission is actually written; routing and the name prompt are
 * covered in UpdateRouterTest.
 */
final class PermissionServiceTest extends TelegramTestCase
{
    /** @var array<int, array{to: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'body' => $body];

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function service(): PermissionService
    {
        return new PermissionService(
            new PersonRepository(),
            $this->client(),
            new RoleConditions(new AssignmentRepository(), new PersonRepository()),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    public function testTheOldLeaderButtonNoLongerChangesAnything(): void
    {
        // findByTelegramUserId(organizer), then people->find(target).
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com', 'telegram_chat_id' => 999];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:leader:8']);

        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->mails);
    }

    public function testAllowGrantsAOneTimePass(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'telegram_chat_id' => 999];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:pass:8']);

        self::assertSame(1, (int) $this->wpdb->updates[0]['data']['at_risk_pass']);
    }

    public function testTakingAwayOrganizerStatusAsksForANewCommandMenu(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'is_organizer' => 1, 'telegram_chat_id' => 999];

        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:admin:8']);

        self::assertSame(0, (int) $this->wpdb->updates[0]['data']['is_organizer']);
        self::assertContains([\EventCrew\Telegram\CommandMenu::ORGANIZER_CHANGED_HOOK, [8]], $fired);
    }

    public function testEligibleListsWhoMeetsEachRolesConditionsForAnOrganizer(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'welcome', 'label' => 'Welcome'],
            [
                'slug' => 'leader',
                'label' => 'Crew leader',
                'restricted' => true,
                'conditions' => ['min_roles' => 2, 'times_each' => 2],
            ],
        ];
        // findByTelegramUserId(organizer); people->all; completedByRole per person.
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextResults[] = [
            ['id' => 8, 'display_name' => 'Jo', 'role_slugs' => 'leader'],
            ['id' => 9, 'display_name' => 'Kim'],
        ];
        $this->wpdb->nextResults[] = [['role_slug' => 'decorate', 'n' => 2], ['role_slug' => 'welcome', 'n' => 3]];
        $this->wpdb->nextResults[] = [['role_slug' => 'decorate', 'n' => 5]];

        $this->service()->onEligible(555, 555);

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('Crew leader', $text);
        self::assertStringContainsString('• Jo ✅', $text);
        self::assertStringNotContainsString('Kim', $text);
    }

    public function testANonOrganizerCannotSeeWhoIsEligible(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 0, 'telegram_user_id' => 555];

        $this->service()->onEligible(555, 555);

        self::assertStringContainsString('Only organizers', $this->lastCallTo('sendMessage')['text']);
    }

    public function testANonOrganizerCannotSetPermissions(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 0, 'telegram_user_id' => 555];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:admin:8']);

        self::assertSame([], $this->wpdb->updates);
        self::assertStringContainsString('Only organizers', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    private function withDjRole(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'dj', 'label' => 'DJ', 'restricted' => true],
        ];
    }

    public function testRolesGivesARestrictedRoleAndTellsThePerson(): void
    {
        $this->withDjRole();
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com', 'telegram_chat_id' => 999, 'role_slugs' => ''];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:role:dj:8']);

        self::assertSame('dj', $this->wpdb->updates[0]['data']['role_slugs']);
        self::assertSame('Sam now has the role DJ.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame(999, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertStringContainsString('You now have the role DJ.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testRolesTakesAwayARoleThePersonHas(): void
    {
        $this->withDjRole();
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'telegram_chat_id' => 999, 'role_slugs' => 'dj'];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:role:dj:8']);

        self::assertSame('', $this->wpdb->updates[0]['data']['role_slugs']);
        self::assertSame('Sam no longer has the role DJ.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertNull($this->lastCallTo('sendMessage'));
    }

    public function testRolesRefusesARoleThatIsNotRestricted(): void
    {
        $this->withDjRole();
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'role_slugs' => ''];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:role:decorate:8']);

        self::assertSame([], $this->wpdb->updates);
        self::assertSame('That role is no longer offered.', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testANonOrganizerCannotChangeARole(): void
    {
        $this->withDjRole();
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 0, 'telegram_user_id' => 555];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:role:dj:8']);

        self::assertSame([], $this->wpdb->updates);
        self::assertSame('Only organizers can change roles.', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testTheMenuForAPersonHasAButtonPerRoleWithMarks(): void
    {
        $this->withDjRole();
        Functions\when('delete_transient')->justReturn(true);
        $this->wpdb->nextResults[] = [['id' => 8, 'display_name' => 'Sam', 'role_slugs' => 'dj']];

        $this->service()->captureTarget(555, 555, 'Sam', []);

        $sent = $this->lastCallTo('sendMessage');
        self::assertStringStartsWith('Roles for Sam.', $sent['text']);
        $buttons = array_merge(...$sent['reply_markup']['inline_keyboard']);
        self::assertSame(
            ['perm:pass:8', 'perm:admin:8', 'perm:role:dj:8'],
            array_column($buttons, 'callback_data')
        );
        self::assertStringStartsWith('✅ ', $buttons[2]['text']);
        self::assertStringStartsNotWith('✅ ', $buttons[0]['text']);
    }
}
