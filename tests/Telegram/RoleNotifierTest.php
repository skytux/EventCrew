<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\RoleNotifier;
use EventCrew\Support\Roles;
use EventCrew\Support\Standing;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\PermissionService;

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

    public function testTheMetNoticeOpensThatPersonsRoleButtons(): void
    {
        $this->options[BoardService::USERNAME_OPTION] = 'crewbot';
        $this->wpdb->nextResults[] = [$this->organizerRow()];
        $this->wpdb->nextResults[] = [['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com']];
        $this->wpdb->nextResults[] = [['role_slug' => 'welcome', 'n' => 2]];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];

        $this->notifier()->run();

        $button = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0][0];
        self::assertSame('🏷 Give them the role', $button['text']);
        self::assertSame(PermissionService::whoData(8), $button['callback_data']);
        self::assertStringContainsString('t.me/crewbot?start=who-8', $this->mails[0]['body']);
    }

    public function testTheMetNoticeSaysWhatThePersonHasDone(): void
    {
        $record = RoleNotifier::record('Jo', new Standing(Standing::GOOD, 0.82, 5, 0));
        self::assertSame('Jo has done 5 tasks · Good standing · 82%', $record);

        $context = ['member' => new Person(8, 'jo@example.com', 'Jo'), 'role' => 'leader', 'record' => $record];
        self::assertSame(
            "Jo now meets the conditions for the role Crew leader. To give it to them, tap Give them the role below.\n" . $record,
            MessageTemplates::body('role_met_dm', $context)
        );
        unset($context['record']);
        self::assertSame(
            'Jo now meets the conditions for the role Crew leader. To give it to them, tap Give them the role below.',
            MessageTemplates::body('role_met_dm', $context)
        );
    }

    public function testTheGivenNoticeOpensThatPersonsRoleButtons(): void
    {
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];
        $this->wpdb->nextResults[] = [$this->organizerRow()];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];

        $this->notifier()->roleGiven(8, 'leader');

        $button = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0][0];
        self::assertSame('🏷 Open their roles', $button['text']);
        self::assertSame('perm:who:8', $button['callback_data']);
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

    // --- the texts are the Messages page templates ------------------------

    /**
     * Runs one notice about Jo (id 8) to organizer Owner, with plain-text email.
     */
    private function runNotice(string $kind, string $giver = ''): void
    {
        $this->options[EmailTemplate::HTML_OPTION] = false;
        $jo = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];

        if ('met' === $kind) {
            // organizers(); people->all; completedByRole; markRoleNotice's find.
            $this->wpdb->nextResults[] = [$this->organizerRow()];
            $this->wpdb->nextResults[] = [$jo];
            $this->wpdb->nextResults[] = [['role_slug' => 'welcome', 'n' => 2]];
            $this->wpdb->nextRows[] = $jo;
            $this->notifier()->run();

            return;
        }

        // find(person); organizers(); markRoleNotice's find.
        $this->wpdb->nextRows[] = $jo;
        $this->wpdb->nextResults[] = [$this->organizerRow()];
        $this->wpdb->nextRows[] = $jo;

        if ('organizer' === $kind) {
            $this->notifier()->organizerGiven(8, $giver);
        } else {
            $this->notifier()->roleGiven(8, 'leader', $giver);
        }
    }

    public function testEachNoticeSendsItsBuiltInTextWhenNothingIsStored(): void
    {
        $cases = [
            ['met', '', 'Someone meets the conditions for a role', 'Jo now meets the conditions for the role Crew leader. To give it to them, tap Give them the role below.'],
            ['given', '', 'Someone was given a role', 'Jo was given the role Crew leader.'],
            ['given', 'Sam', 'Someone was given a role', 'Jo was given the role Crew leader by Sam.'],
            ['organizer', '', 'Someone was made an organizer', 'Jo was made an organizer.'],
            ['organizer', 'Sam', 'Someone was made an organizer', 'Jo was made an organizer by Sam.'],
        ];

        foreach ($cases as [$kind, $giver, $subject, $line]) {
            $this->mails = [];
            $this->telegramCalls = [];
            $this->runNotice($kind, $giver);

            self::assertSame("💌 Test Site\n\nHi Owner,\n\n" . $line, $this->lastCallTo('sendMessage')['text'], $line);
            self::assertCount(1, $this->mails, $line);
            self::assertSame($subject, $this->mails[0]['subject'], $line);
            self::assertStringStartsWith("Hi Owner,\n\n" . $line . "\n\n", $this->mails[0]['body'], $line);
        }
    }

    public function testAStoredTextIsUsedForTheEmailAndTheTelegramMessage(): void
    {
        $prefix = MessageTemplates::OPTION_PREFIX;
        $this->options[$prefix . 'role_given'] = [
            MessageTemplates::SUBJECT => ['source' => 'New role: {role}'],
            MessageTemplates::BODY => ['source' => '{name} now has {role}, from {giver}.'],
        ];
        $this->options[$prefix . 'role_given_dm'] = [
            MessageTemplates::BODY => ['source' => '{name} got {role} ({giver}).'],
        ];
        $this->options[$prefix . 'organizer_made'] = [
            MessageTemplates::BODY => ['source' => '{name} organizes now.'],
        ];
        $this->options[$prefix . 'role_met_dm'] = [
            MessageTemplates::BODY => ['source' => '{name} could be {role}.'],
        ];

        $this->runNotice('given', 'Sam');
        self::assertSame("💌 Test Site\n\nHi Owner,\n\nJo got Crew leader (Sam).", $this->lastCallTo('sendMessage')['text']);
        self::assertSame('New role: Crew leader', $this->mails[0]['subject']);
        self::assertStringStartsWith("Hi Owner,\n\nJo now has Crew leader, from Sam.\n\n", $this->mails[0]['body']);

        $this->mails = [];
        $this->runNotice('organizer', 'Sam');
        self::assertSame('Someone was made an organizer', $this->mails[0]['subject'], 'The subject was not changed.');
        self::assertStringStartsWith("Hi Owner,\n\nJo organizes now.\n\n", $this->mails[0]['body']);
        self::assertStringEndsWith('Jo was made an organizer by Sam.', $this->lastCallTo('sendMessage')['text']);

        $this->mails = [];
        $this->runNotice('met');
        self::assertStringEndsWith("\n\nJo could be Crew leader.", $this->lastCallTo('sendMessage')['text']);
        self::assertStringStartsWith("Hi Owner,\n\nJo now meets the conditions", $this->mails[0]['body']);
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

    // --- each organizer's notification settings ---------------------------

    public function testAnOrganizerWhoTurnedTheMetRowsTelegramOffGetsOnlyTheEmail(): void
    {
        $owner = $this->organizerRow() + ['notify_prefs' => '{"role_met":{"dm":false,"email":true}}'];
        $this->wpdb->nextResults[] = [$owner];
        $this->wpdb->nextResults[] = [['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com']];
        $this->wpdb->nextResults[] = [['role_slug' => 'welcome', 'n' => 2]];
        $this->wpdb->nextRows[] = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];

        $this->notifier()->run();

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertCount(1, $this->mails);
        // Still recorded, so it is not announced again next hour.
        self::assertArrayHasKey('met:leader', json_decode($this->wpdb->updates[0]['data']['role_notices'], true));
    }

    public function testRolesGivenAndNewOrganizersShareOneRowThatCanBeTurnedOff(): void
    {
        $owner = $this->organizerRow() + ['notify_prefs' => '{"role_news":{"dm":false,"email":false}}'];
        $jo = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];

        $this->wpdb->nextRows[] = $jo;
        $this->wpdb->nextResults[] = [$owner];
        $this->wpdb->nextRows[] = $jo;
        $this->notifier()->roleGiven(8, 'leader', 'Sam');

        $this->wpdb->nextRows[] = $jo + ['is_organizer' => 1];
        $this->wpdb->nextResults[] = [$owner];
        $this->wpdb->nextRows[] = $jo;
        $this->notifier()->organizerGiven(8, 'Sam');

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
        self::assertCount(2, $this->wpdb->updates);
    }

    public function testTheMetRowDoesNotSilenceTheGivenNotice(): void
    {
        $owner = $this->organizerRow() + ['notify_prefs' => '{"role_met":{"dm":false,"email":false}}'];
        $jo = ['id' => 8, 'display_name' => 'Jo', 'email' => 'jo@example.com'];
        $this->wpdb->nextRows[] = $jo;
        $this->wpdb->nextResults[] = [$owner];
        $this->wpdb->nextRows[] = $jo;

        $this->notifier()->roleGiven(8, 'leader');

        self::assertSame(500, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertCount(1, $this->mails);
    }
}
