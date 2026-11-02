<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\Language;
use EventCrew\Telegram\HelpService;

/**
 * /help, /door, /people, the Cancel button, /me history, /me tickets and
 * /roster with a date, driven through the router so the routing decision is
 * what is under test.
 */
final class HelpServiceTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path
        );
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function help(): HelpService
    {
        return new HelpService(
            new PersonRepository(),
            new DoorAccess(new TaskRepository(), new AssignmentRepository()),
            $this->client()
        );
    }

    private function person(bool $organizer = false): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => $organizer ? 1 : 0,
            'telegram_user_id' => 555,
        ];
    }

    /**
     * @param array<string, mixed> $chat
     */
    private function send(string $text, array $chat = ['id' => 555, 'type' => 'private']): void
    {
        $this->updateRouter()->dispatch([
            'message' => ['text' => $text, 'chat' => $chat, 'from' => ['id' => 555, 'first_name' => 'Sam']],
        ]);
    }

    private function lastText(): string
    {
        return (string) $this->lastCallTo('sendMessage')['text'];
    }

    public function testAMemberSeesOnlyTheEveryoneSection(): void
    {
        $this->person();
        $this->wpdb->nextCols[] = ['2026-08-01'];                                                   // nearbyDates
        $this->wpdb->nextResults[] = [['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3]];

        $this->send('/help');

        $text = $this->lastText();
        self::assertStringStartsWith("EventCrew bot – commands\n\nEveryone\n/board – the open tasks", $text);
        self::assertStringContainsString('buttons: 🎟 free-entry ticket, 🔁 take over a task', $text);
        self::assertStringNotContainsString('Welcome crew', $text);
        self::assertStringNotContainsString('Organizers', $text);
    }

    public function testTheWelcomeCrewOfANearbyNightSeeTheirSection(): void
    {
        $this->person();
        $this->wpdb->nextCols[] = ['2026-08-01'];                                                         // nearbyDates
        $this->wpdb->nextResults[] = [['id' => 6, 'task_date' => '2026-08-01', 'role_slug' => 'welcome', 'capacity' => 2]];
        $this->wpdb->nextRows[] = ['id' => 30, 'task_id' => 6, 'person_id' => 7, 'status' => 'signed_up']; // findFor

        $this->send('/help');

        $text = $this->lastText();
        self::assertStringContainsString("Welcome crew  (date: 1.8. or 2026-08-01; default: next night)\n/roster [date]", $text);
        self::assertStringContainsString('/door [date] – buttons: 📋 door list, 👥 guest list, 💳 sales desk', $text);
        self::assertStringNotContainsString('Organizers', $text);
    }

    public function testAnOrganizerSeesAllThreeSections(): void
    {
        $this->person(true);

        $this->send('/help');

        $text = $this->lastText();
        self::assertStringContainsString("\n\nWelcome crew  (date:", $text);
        self::assertStringContainsString("\n\nOrganizers\n/door add [date] Name +N ; note", $text);
        self::assertStringContainsString('/people – buttons: 🎁 give credits', $text);
        self::assertStringContainsString('/board in a private chat – also ➕ to put a person on a task', $text);
    }

    public function testInTheGroupHelpIsTheEveryoneSectionInEnglish(): void
    {
        Language::run('fi', function (): void {
            $this->help()->onHelp(555, -100, false);
        });

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(-100, $sent['chat_id']);
        self::assertStringStartsWith("EventCrew bot – commands\n\nEveryone\n", $sent['text']);
        self::assertStringEndsWith("\n\nSend me /help in a private chat for more.", $sent['text']);
        self::assertStringNotContainsString('Organizers', $sent['text']);
    }

    public function testTheSetupNudgeInTheGroupIsEnglish(): void
    {
        // Asked by someone who reads Finnish, answered in the group.
        Language::run('fi', function (): void {
            $this->send('/me history', ['id' => -100, 'type' => 'supergroup']);
        });

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(-100, $sent['chat_id']);
        self::assertSame('Set yourself up first: send /start to the bot.', $sent['text']);
    }

    public function testInAPrivateChatHelpIsInTheAskersLanguage(): void
    {
        Language::run('fi', function (): void {
            $this->help()->onHelp(555, 555, true);
        });

        self::assertStringStartsWith("EventCrew-botti – komennot\n\nKaikki\n", $this->lastText());
    }

    public function testCancelDropsEveryWaitingQuestion(): void
    {
        $this->transients['eventcrew_tg_await_replace_target_555'] = 1;
        $this->transients['eventcrew_tg_await_email_555'] = 1;

        $this->tapCancel();

        self::assertSame([], $this->transients);
        self::assertSame('Stopped. Nothing is waiting for an answer.', $this->lastText());
    }

    public function testCancelWithNothingWaitingSaysSo(): void
    {
        $this->tapCancel();

        self::assertSame('Nothing was waiting for an answer.', $this->lastText());
    }

    public function testMeHistoryRunsTheHistory(): void
    {
        $this->person();
        $this->wpdb->nextResults[] = []; // no past tasks

        $this->send('/me history');

        self::assertSame('No past tasks yet — your history starts once you’ve worked one.', $this->lastText());
    }

    public function testMeTicketsRunsTheTicketList(): void
    {
        $this->person();

        $this->send('/me tickets');

        self::assertStringStartsWith('🎟 Your tickets', $this->lastText());
    }

    private function tapCancel(): void
    {
        $this->updateRouter()->dispatch(['callback_query' => [
            'id' => 'cbq',
            'from' => ['id' => 555],
            'message' => ['chat' => ['id' => 555, 'type' => 'private']],
            'data' => 'mn:cancel',
        ]]);
    }

    /**
     * @return array<int, string>
     */
    private function lastButtons(): array
    {
        $keyboard = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'] ?? [];

        return array_column(array_merge(...$keyboard), 'callback_data');
    }

    public function testDoorFromSomeoneNotSetUpGetsTheNudge(): void
    {
        $this->wpdb->nextRows[] = null; // nobody with this Telegram account

        $this->send('/door');

        self::assertSame('Set yourself up first: send /start to the bot.', $this->lastText());
    }

    public function testDoorFromAMemberWithoutAWelcomeTaskIsRefused(): void
    {
        $this->person();
        $this->wpdb->nextCols[] = ['2026-08-01'];                                                   // nearbyDates
        $this->wpdb->nextResults[] = [['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3]];

        $this->send('/door');

        self::assertSame(
            'Only organizers and the welcome crew of a coming night can use /door.',
            $this->lastText()
        );
    }

    public function testDoorGivesAnOrganizerTheThreeButtonsWithTheNightTyped(): void
    {
        $this->person(true);

        $this->send('/door 1.8.');

        self::assertSame('🚪 The door on 1.8.: choose what to open.', $this->lastText());
        self::assertSame(['mn:dl:1.8.', 'mn:gl:1.8.', 'mn:sales'], $this->lastButtons());
    }

    public function testDoorFromTheWelcomeCrewInTheGroupGoesToTheDm(): void
    {
        $this->person();
        $this->wpdb->nextCols[] = ['2026-08-01'];                                                         // nearbyDates
        $this->wpdb->nextResults[] = [['id' => 6, 'task_date' => '2026-08-01', 'role_slug' => 'welcome', 'capacity' => 2]];
        $this->wpdb->nextRows[] = ['id' => 30, 'task_id' => 6, 'person_id' => 7, 'status' => 'signed_up']; // findFor

        $this->send('/door', ['id' => -100, 'type' => 'supergroup']);

        $menu = $this->telegramCalls[0]['body'];
        self::assertSame(555, $menu['chat_id']);
        self::assertSame('🚪 The door: choose what to open.', $menu['text']);
        self::assertSame(['mn:dl:', 'mn:gl:', 'mn:sales'], array_column(array_merge(...$menu['reply_markup']['inline_keyboard']), 'callback_data'));
        self::assertSame(-100, $this->lastCallTo('sendMessage')['chat_id']);
    }

    public function testPeopleIsOnlyForOrganizers(): void
    {
        $this->person();
        $this->send('/people');
        self::assertSame('Only organizers can use /people.', $this->lastText());

        $this->person(true);
        $this->send('/people');
        self::assertSame('👥 People: choose what to do.', $this->lastText());
        self::assertSame(['mn:gift', 'mn:roles', 'mn:elig'], $this->lastButtons());
    }

    public function testMeComesWithItsButtons(): void
    {
        $this->person();
        $this->wpdb->nextResults[] = [];

        $this->send('/me');

        self::assertSame(['mn:ticket', 'mn:replace', 'mn:web', 'mn:notif', 'mn:lang'], $this->lastButtons());
    }

    public function testRosterTakesADateAndExplainsTheMarks(): void
    {
        $this->person(true);
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 11, 'task_id' => 5, 'person_id' => 8, 'status' => 'signed_up'],
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'bo@example.com', 'display_name' => 'Bo'];

        $this->send('/roster 1.8.');

        $text = $this->lastText();
        self::assertStringStartsWith('📋 Sat 1 Aug', $text);
        self::assertStringEndsWith("\n\n✓ done · ⏰ late · ✗ no-show", $text);
    }

    public function testRosterWithAnUnreadableDateSaysHowToWriteIt(): void
    {
        $this->person(true);

        $this->send('/roster friday');

        self::assertSame('I could not read that date. Write it like 1.8. or 2026-08-01.', $this->lastText());
    }
}
