<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\LeaderEligibility;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\PermissionService;

/**
 * The organizer-only /allow grant. The tap (onSelect) is where a permission is
 * actually written; routing and the name prompt are covered in UpdateRouterTest.
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
            new LeaderEligibility(new AssignmentRepository(), new PersonRepository()),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    public function testAllowMakesSomeoneALeaderAndTellsThem(): void
    {
        // findByTelegramUserId(organizer), then people->find(target).
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'email' => 'sam@example.com', 'telegram_chat_id' => 999, 'can_lead' => 0];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:leader:8']);

        self::assertSame(1, (int) $this->wpdb->updates[0]['data']['can_lead']);
        // The person is told, on both channels.
        self::assertSame(999, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
    }

    public function testAllowGrantsAOneTimePass(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Sam', 'telegram_chat_id' => 999];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:pass:8']);

        self::assertSame(1, (int) $this->wpdb->updates[0]['data']['at_risk_pass']);
    }

    public function testLeadersListsEligibleAndAllowedForAnOrganizer(): void
    {
        // findByTelegramUserId(organizer); eligiblePeople -> people->all (none);
        // allowed scan -> people->all (one can_lead).
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 1, 'telegram_user_id' => 555];
        $this->wpdb->nextResults[] = []; // eligiblePeople: people->all
        $this->wpdb->nextResults[] = [['id' => 8, 'display_name' => 'Jo', 'can_lead' => 1]]; // allowed scan

        $this->service()->onLeaders(555, 555);

        $text = $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('Crew leaders', $text);
        self::assertStringContainsString('Jo', $text);
    }

    public function testANonOrganizerCannotSeeLeaders(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 0, 'telegram_user_id' => 555];

        $this->service()->onLeaders(555, 555);

        self::assertStringContainsString('Only organizers', $this->lastCallTo('sendMessage')['text']);
    }

    public function testANonOrganizerCannotSetPermissions(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'is_organizer' => 0, 'telegram_user_id' => 555];

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'perm:admin:8']);

        self::assertSame([], $this->wpdb->updates);
        self::assertStringContainsString('Only organizers', $this->lastCallTo('answerCallbackQuery')['text']);
    }
}
