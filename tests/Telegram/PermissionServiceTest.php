<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AccessNotifier;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\RoleNotifier;
use EventCrew\Support\Roles;
use EventCrew\Telegram\PermissionService;

/**
 * The organizer-only /roles grant (old name /allow). The tap (onSelect) is
 * where a permission is actually written; routing and the name prompt are
 * covered in UpdateRouterTest.
 */
final class PermissionServiceTest extends TelegramTestCase
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
    }

    private function service(): PermissionService
    {
        return new PermissionService(
            new PersonRepository(),
            $this->client(),
            new RoleConditions(new AssignmentRepository(), new PersonRepository())
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
        $this->wpdb->nextVars[] = '1'; // another organizer, for the lockout guard

        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:admin:8']);

        self::assertSame(0, (int) $this->wpdb->updates[0]['data']['is_organizer']);
        self::assertContains([\EventCrew\Telegram\CommandMenu::ORGANIZER_CHANGED_HOOK, [8]], $fired);
        self::assertNotContains(\EventCrew\Support\RoleNotifier::ORGANIZER_HOOK, array_column($fired, 0));
    }

    /**
     * The last organizer keeps their rights: the site is never left without
     * an organizer (Owner 2026-11-08, Q372).
     */
    public function testTheLastOrganizerIsNotTakenAway(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Sam', 'is_organizer' => 1, 'role_slugs' => 'organize', 'telegram_chat_id' => 999];
        $this->wpdb->nextVars[] = '0'; // no other organizer

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:admin:7']);

        self::assertSame([], $this->wpdb->updates);
        self::assertSame(
            'That would leave no organizer: at least one person has to hold a role with Admin access.',
            $this->lastCallTo('answerCallbackQuery')['text']
        );
    }

    /**
     * Making someone an organizer gives them the Organize role, and their
     * organizer copy follows it.
     */
    public function testMakingSomeoneAnOrganizerGivesThemOrganize(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'is_organizer' => 0, 'role_slugs' => 'clean', 'telegram_chat_id' => 999];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:admin:8']);

        self::assertSame('clean,organize', $this->wpdb->updates[0]['data']['role_slugs']);
        self::assertSame(1, $this->wpdb->updates[0]['data']['is_organizer']);
    }

    public function testMakingSomeoneAnOrganizerAnnouncesItWithTheOrganizersName(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Owner', 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'is_organizer' => 0, 'telegram_chat_id' => 999];

        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:admin:8']);

        self::assertContains([\EventCrew\Support\RoleNotifier::ORGANIZER_HOOK, [8, 'Owner']], $fired);
    }

    public function testGivingARoleNamesTheOrganizerWhoGaveIt(): void
    {
        $this->withDjRole();
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Owner', 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com', 'telegram_chat_id' => 999, 'role_slugs' => ''];

        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:role:dj:8']);

        self::assertContains([\EventCrew\Support\RoleNotifier::GIVEN_HOOK, [8, 'dj', 'Owner']], $fired);
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

    public function testRolesGivesARestrictedRole(): void
    {
        $this->withDjRole();
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com', 'telegram_chat_id' => 999, 'role_slugs' => ''];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:role:dj:8']);

        self::assertSame('dj', $this->wpdb->updates[0]['data']['role_slugs']);
        self::assertSame('Sam now has the role DJ.', $this->lastCallTo('answerCallbackQuery')['text']);
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

    public function testActAsSomeoneOffersTheActAsButtonForTheNamedPerson(): void
    {
        // 👤 Act as someone under /people: the reply naming the person gets
        // only the Act as button, not the role card.
        Functions\when('get_transient')->justReturn('act');
        Functions\when('delete_transient')->justReturn(true);
        $this->wpdb->nextResults[] = [
            ['id' => 8, 'display_name' => 'Sam', 'email_verified_at' => '2026-11-01 10:00:00'],
            ['id' => 9, 'display_name' => 'Samu'],
        ];

        $this->service()->captureTarget(555, 555, 'Sam', []);

        $sends = array_column(
            array_values(array_filter($this->telegramCalls, static fn (array $c): bool => 'sendMessage' === $c['method'])),
            'body'
        );
        self::assertSame('👤 Sam', $sends[0]['text']);
        self::assertSame(['act:8'], array_column($sends[0]['reply_markup']['inline_keyboard'][0], 'callback_data'));
        self::assertSame('Samu has no verified email, so you can’t act as them.', $sends[1]['text']);
    }

    public function testTheWhoButtonShowsThePersonsRoleButtonsAndChangesNothing(): void
    {
        // An organizer notice's button: perm:who:8 shows Sam's role buttons.
        $this->withDjRole();
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'role_slugs' => 'dj'];

        $this->service()->onSelect([
            'id' => 'cbq',
            'from' => ['id' => 555],
            'message' => ['message_id' => 3, 'chat' => ['id' => 555]],
            'data' => PermissionService::whoData(8),
        ]);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(555, $sent['chat_id']);
        self::assertStringStartsWith('Roles for Sam.', $sent['text']);
        self::assertSame(
            ['perm:pass:8', 'perm:admin:8', 'perm:role:dj:8'],
            array_column(array_merge(...$sent['reply_markup']['inline_keyboard']), 'callback_data')
        );
        self::assertContains('answerCallbackQuery', $this->calledMethods());
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->mails);
    }

    public function testAPersonWhoIsGoneHasNoRoleButtons(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];

        $this->service()->showPerson(555, 555, 8);

        $sent = $this->lastCallTo('sendMessage');
        self::assertStringContainsString('That person is no longer in', $sent['text']);
        self::assertArrayNotHasKey('reply_markup', $sent);
    }

    // ---- The person is told (AccessNotifier, through the hooks) -------------

    /**
     * Sam (8) after the organizer taps $data, and the actions fired.
     *
     * @return array<int, array{0: string, 1: array<int, mixed>}>
     */
    private function firedAfter(string $data, string $roles = '', int $isOrganizer = 0): array
    {
        $this->withDjRole();
        $this->wpdb->nextRows[] = ['id' => 7, 'display_name' => 'Owner', 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = [
            'id' => 8,
            'display_name' => 'Sam',
            'email' => 'sam@example.com',
            'telegram_chat_id' => 999,
            'role_slugs' => $roles,
            'is_organizer' => $isOrganizer,
        ];

        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => $data]);

        return $fired;
    }

    public function testGivingAPassTellsThePerson(): void
    {
        self::assertContains([AccessNotifier::PASS_HOOK, [8]], $this->firedAfter('perm:pass:8'));
    }

    public function testGivingARoleTellsThePerson(): void
    {
        self::assertContains([RoleNotifier::GIVEN_HOOK, [8, 'dj', 'Owner']], $this->firedAfter('perm:role:dj:8'));
    }

    public function testMakingSomeoneAnOrganizerTellsThePerson(): void
    {
        self::assertContains([RoleNotifier::ORGANIZER_HOOK, [8, 'Owner']], $this->firedAfter('perm:admin:8'));
    }

    public function testTakingARoleOrOrganizerRightsAwayTellsNobody(): void
    {
        $hooks = array_merge(
            array_column($this->firedAfter('perm:role:dj:8', 'dj'), 0),
            array_column($this->firedAfter('perm:admin:8', '', 1), 0)
        );

        self::assertNotContains(RoleNotifier::GIVEN_HOOK, $hooks);
        self::assertNotContains(RoleNotifier::ORGANIZER_HOOK, $hooks);
        self::assertNotContains(AccessNotifier::PASS_HOOK, $hooks);
        self::assertSame([], $this->mails);
    }
}
