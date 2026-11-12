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
use EventCrew\Telegram\MenuButtons;

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

        $this->tapMenu(MenuButtons::HELP_COMMANDS);

        $text = $this->lastText();
        self::assertStringStartsWith("Test Site bot – commands\n\nEveryone\n/board – the open tasks", $text);
        self::assertStringContainsString('/tasks – cancel, take over a task, past tasks', $text);
        self::assertStringEndsWith('/help – how this works, commands and FAQ', $text);
        self::assertSame([MenuButtons::HELP], $this->lastButtons());
        self::assertStringNotContainsString('Welcome crew', $text);
        self::assertStringNotContainsString('Organizers', $text);
    }

    public function testTheWelcomeCrewOfANearbyNightSeeTheirSection(): void
    {
        $this->person();
        $this->wpdb->nextCols[] = ['2026-08-01'];                                                         // nearbyDates
        $this->wpdb->nextResults[] = [['id' => 6, 'task_date' => '2026-08-01', 'role_slug' => 'welcome', 'capacity' => 2]];
        $this->wpdb->nextRows[] = ['id' => 30, 'task_id' => 6, 'person_id' => 7, 'status' => 'signed_up']; // findFor

        $this->tapMenu(MenuButtons::HELP_COMMANDS);

        $text = $this->lastText();
        self::assertStringContainsString("Welcome crew  (date: 1.8. or 2026-08-01; default: next night)\n/roster [date]", $text);
        self::assertStringContainsString(
            '/roster [date] – who works that night; the sales desk marks 👋 arrived,'
            . ' organizers 👋 arrived, ✓ done, ⏰ late, ✗ no-show',
            $text
        );
        self::assertStringContainsString('/door [date] – buttons: 📋 door list, 👥 guest list, 💳 sales desk', $text);
        self::assertStringNotContainsString('Organizers', $text);
    }

    public function testAnOrganizerSeesAllThreeSections(): void
    {
        $this->person(true);

        $this->tapMenu(MenuButtons::HELP_COMMANDS);

        $text = $this->lastText();
        self::assertStringContainsString("\n\nWelcome crew  (date:", $text);
        self::assertStringContainsString("\n\nOrganizers\n/door add [date] Name +N ; note", $text);
        self::assertStringContainsString('/people – buttons: 🎁 give credits', $text);
        self::assertStringContainsString('/board in a private chat – also ➕ to put a person on a task', $text);
    }

    public function testAnUnknownCommandGetsTheHelpAnswer(): void
    {
        $this->person();
        $this->wpdb->nextCols[] = [];                                                               // nearbyDates

        $this->send('/shifts');

        self::assertStringStartsWith("Test Site bot – commands\n\nEveryone\n/board", $this->lastText());
    }

    public function testAGroupCommandForAnotherBotIsLeftAlone(): void
    {
        $this->options['eventcrew_telegram_bot_username'] = 'CrewBot';

        $this->send('/poll@OtherBot', ['id' => -100, 'type' => 'supergroup']);

        self::assertNull($this->lastCallTo('sendMessage'));
    }

    public function testABareUnknownGroupCommandIsLeftAlone(): void
    {
        // The bot reads every group message (the group chat on the web), so
        // a bare /x may be another bot's.
        $this->options['eventcrew_telegram_bot_username'] = 'CrewBot';

        $this->send('/shifts', ['id' => -100, 'type' => 'supergroup']);

        self::assertNull($this->lastCallTo('sendMessage'));
    }

    public function testAnUnknownGroupCommandForThisBotGetsTheGroupHelp(): void
    {
        $this->options['eventcrew_telegram_bot_username'] = 'CrewBot';

        $this->send('/shifts@crewbot', ['id' => -100, 'type' => 'supergroup']);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(-100, $sent['chat_id']);
        self::assertStringStartsWith("Test Site bot – commands\n\nEveryone\n", $sent['text']);
    }

    public function testInTheGroupHelpIsTheEveryoneSectionInEnglish(): void
    {
        Language::run('fi', function (): void {
            $this->help()->onHelp(555, -100, false);
        });

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(-100, $sent['chat_id']);
        self::assertStringStartsWith("Test Site bot – commands\n\nEveryone\n", $sent['text']);
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
            $this->help()->onCommands(555, 555, true);
        });

        self::assertStringStartsWith("Test Site -botti – komennot\n\nKaikki\n", $this->lastText());
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

    /**
     * Owner 2026-11-07: /tasks, /tickets and /settings are commands of their
     * own, the same screens as the buttons under /me, each with a way back.
     */
    public function testTheScreensUnderMeAreCommandsToo(): void
    {
        $this->person();
        $this->send('/settings');
        self::assertSame('⚙️ Settings', $this->lastText());
        self::assertSame(['mn:name', 'mn:email', 'mn:notif', 'mn:lang', 'mn:web', 'mn:signout', 'mn:delete', 'mn:me'], $this->lastButtons());

        $this->person();
        $this->send('/tickets');
        self::assertStringStartsWith('🎟 Your tickets', $this->lastText());
        self::assertSame(['mn:ticket', 'mn:me'], $this->lastButtons());

        $this->person();
        $this->send('/tasks');
        self::assertSame("📋 Tasks\nNo coming tasks.", $this->lastText());
        self::assertSame(['mn:board', 'mn:replace', 'mn:hist', 'mn:me'], $this->lastButtons());
    }

    /** The Tasks screen's deep link is "mytasks": "tasks" is the board's Task info. */
    public function testTheTasksScreenHasItsOwnStartVerb(): void
    {
        $this->person();
        $this->send('/start mytasks');

        self::assertStringStartsWith('📋 Tasks', $this->lastText());
    }

    public function testTheAdminButtonOpensTheOrganizerScreens(): void
    {
        $this->person(true);
        $this->tapMenu('mn:admin');

        self::assertSame('🛠 Admin', $this->lastText());
        self::assertSame(['mn:roster', 'mn:door', 'mn:people', 'mn:me'], $this->lastButtons());
    }

    public function testTheAdminButtonIsRefusedToAMember(): void
    {
        $this->person();
        $this->tapMenu('mn:admin');

        self::assertSame('Only organizers can open this.', $this->lastText());
    }

    /** Owner 2026-11-08: /help and the Help button under /me open the Help menu. */
    public function testHelpOpensTheHelpMenuWithAWayBack(): void
    {
        $this->person();
        $this->tapMenu('mn:help');

        self::assertSame('❓ Help', $this->lastText());
        self::assertSame(['mn:hguide', 'mn:hcmd', 'mn:faq', 'mn:me'], $this->lastButtons());
        $rows = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];
        self::assertSame(['📖 How this works?', '⌨️ Commands', '💡 FAQ', '⬅️ Back'], array_column(array_merge(...$rows), 'text'));

        foreach ($rows as $row) {
            self::assertCount(1, $row);
        }

        $this->person();
        $this->send('/help');
        self::assertSame('❓ Help', $this->lastText());
    }

    public function testAnOrganizersHelpMenuHasTheOrganizerGuideAndTheCrewGuideLink(): void
    {
        $this->options['eventcrew_guide_url'] = 'https://docs.example/guide';
        $this->person(true);

        $this->send('/help');

        $buttons = array_merge(...$this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard']);
        self::assertSame(['mn:hguide', 'mn:hcmd', 'mn:faq', 'mn:horg', 'mn:me'], $this->lastButtons());
        self::assertContains(['text' => '📋 Crew guide', 'url' => 'https://docs.example/guide'], $buttons);
    }

    public function testTheFaqIsOneHtmlMessageWithBackToHelp(): void
    {
        $this->tapMenu(MenuButtons::FAQ);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame('HTML', $sent['parse_mode']);
        self::assertStringStartsWith("<b>💡 FAQ</b>\n\n<b>❓ How do I sign up for a task?</b>\n", $sent['text']);
        self::assertStringContainsString('<b>❓ How do I cancel a task?</b>', $sent['text']);
        self::assertLessThan(4096, mb_strlen($sent['text']));
        self::assertSame([MenuButtons::HELP], $this->lastButtons());
    }

    public function testEveryFaqPageFitsInOneMessageInEveryLanguage(): void
    {
        foreach (['en', 'fi', 'de', 'ru', 'ja'] as $language) {
            $pages = Language::run($language, static fn (): array => HelpService::faqPages());

            self::assertNotEmpty($pages[0]);

            foreach ($pages as $page) {
                self::assertLessThan(4000, \EventCrew\Telegram\MessagePages::textLength($page));
            }
        }
    }

    public function testALaterFaqPageIsReachedByItsButton(): void
    {
        $this->tapMenu('mn:faq:9');

        // Past the last page: the last page, still with Back to Help.
        $sent = $this->lastCallTo('sendMessage');
        self::assertStringStartsWith('<b>💡 FAQ', $sent['text']);
        self::assertSame(MenuButtons::HELP, $this->lastBackTarget());
    }

    public function testHowThisWorksSendsTheWelcomeGuideAgainWithBackToHelp(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
        ];

        $this->helpWithWelcome()->onGuide(555, 555);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(555, $sent['chat_id']);
        self::assertSame('HTML', $sent['parse_mode']);
        self::assertStringContainsString('Welcome to the Test Site crew!', $sent['text']);
        self::assertSame(MenuButtons::HELP, $this->lastBackTarget());
    }

    public function testTheOrganizerGuideIsRefusedToAMember(): void
    {
        $this->person();

        $this->helpWithWelcome()->onGuide(555, 555, true, true);

        self::assertSame('Only organizers can open this.', $this->lastText());
    }

    private function helpWithWelcome(): HelpService
    {
        return new HelpService(
            new PersonRepository(),
            new DoorAccess(new TaskRepository(), new AssignmentRepository()),
            $this->client(),
            $this->welcomeSender()
        );
    }

    public function testHowThisWorksForSomeoneNotSetUpGetsTheNudge(): void
    {
        $this->wpdb->nextRows[] = null;

        $this->tapMenu(MenuButtons::HELP_GUIDE);

        self::assertStringContainsString('/start', $this->lastText());
    }

    /**
     * Owner 2026-11-07: every menu sub-screen ends with one ⬅️ Back row that
     * opens its parent; the top-level screens and question screens do not.
     */
    public function testTheMenuKeyboardsEndWithBackToTheirParent(): void
    {
        $parents = [
            'mn:me' => [MenuButtons::settings(), MenuButtons::admin(), ['inline_keyboard' => MenuButtons::ticketsRows()],
                ['inline_keyboard' => MenuButtons::tasksRows()], MenuButtons::back()],
            'mn:admin' => [MenuButtons::door('1.8.', true), MenuButtons::people()],
            'mn:door' => [MenuButtons::withBack(null, MenuButtons::DOOR)],
        ];

        foreach ($parents as $parent => $keyboards) {
            foreach ($keyboards as $keyboard) {
                $last = end($keyboard['inline_keyboard']);
                self::assertSame([['text' => '⬅️ Back', 'callback_data' => $parent]], $last);

                foreach (array_merge(...$keyboard['inline_keyboard']) as $button) {
                    self::assertLessThanOrEqual(64, strlen((string) ($button['callback_data'] ?? '')));
                }
            }
        }

        // The welcome crew's /door is their top level: no Back.
        $rows = MenuButtons::door('1.8.')['inline_keyboard'];
        self::assertNotContains('mn:admin', array_column(array_merge(...$rows), 'callback_data'));
        // Question screens keep Cancel and get no Back.
        self::assertSame([MenuButtons::cancelRow()], MenuButtons::cancel()['inline_keyboard']);
    }

    public function testPastTasksGoBackToTasks(): void
    {
        $this->person();
        $this->wpdb->nextResults[] = []; // no past tasks

        $this->tapMenu('mn:hist');

        self::assertSame('mn:mytasks', $this->lastBackTarget());
    }

    /** Owner 2026-11-08: ◀️ ▶️ send the page in the data, "mn:hist:2". */
    public function testAPageTapOpensThatPageOfPastTasks(): void
    {
        Functions\when('current_time')->justReturn('2026-07-10');
        $this->person();
        $past = [];

        for ($i = 1; $i <= 26; $i++) {
            $past[] = ['id' => $i, 'task_id' => $i, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-06-30'];
        }

        $this->wpdb->nextResults[] = $past;

        $this->tapMenu('mn:hist:2');

        self::assertSame(1, substr_count($this->lastText(), '• '));
        self::assertSame(['mn:hist', 'mn:mytasks'], $this->lastButtons());
    }

    public function testWebSignInWithoutAPageGoesBackToSettings(): void
    {
        $this->person();

        $this->tapMenu('mn:web');

        self::assertStringStartsWith('There’s no public sign-up page', $this->lastText());
        self::assertSame(['mn:set'], $this->lastButtons());
    }

    public function testBackTapsOpenTheParentScreens(): void
    {
        $this->person(true);
        $this->tapMenu('mn:set');
        self::assertSame('⚙️ Settings', $this->lastText());

        $this->person(true);
        $this->tapMenu('mn:admin');
        self::assertSame('🛠 Admin', $this->lastText());

        $this->person(true);
        $this->tapMenu('mn:people');
        self::assertSame('mn:admin', $this->lastBackTarget());

        $this->person(true);
        $this->tapMenu('mn:tix');
        self::assertStringStartsWith('🎟 Your tickets', $this->lastText());

        $this->person(true);
        $this->wpdb->nextCols[] = [];
        $this->tapMenu('mn:door');
        self::assertSame('mn:admin', $this->lastBackTarget());
    }

    private function lastBackTarget(): string
    {
        $keyboard = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'] ?? [];
        $last = end($keyboard);
        self::assertSame('⬅️ Back', $last[0]['text'] ?? null);

        return (string) $last[0]['callback_data'];
    }

    private function tapMenu(string $data): void
    {
        $this->updateRouter()->dispatch(['callback_query' => [
            'id' => 'cbq',
            'from' => ['id' => 555],
            'message' => ['chat' => ['id' => 555, 'type' => 'private']],
            'data' => $data,
        ]]);
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
        // An organizer also gets Back to Admin, as the last row.
        self::assertSame(['mn:dl:1.8.', 'mn:gl:1.8.', 'mn:sales', 'mn:admin'], $this->lastButtons());
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
        self::assertSame(['mn:gift', 'mn:roles', 'mn:actas', 'mn:elig', 'mn:admin'], $this->lastButtons());
    }

    public function testMeComesWithItsButtons(): void
    {
        $this->person();
        $this->wpdb->nextResults[] = [];

        $this->send('/me');

        self::assertSame(['mn:tix', 'mn:mytasks', 'mn:set', 'mn:help'], $this->lastButtons());
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
        self::assertStringEndsWith("\n\n👋 arrived · ✓ done · ⏰ late · ✗ no-show", $text);
    }

    public function testRosterWithAnUnreadableDateSaysHowToWriteIt(): void
    {
        $this->person(true);

        $this->send('/roster friday');

        self::assertSame('I could not read that date. Write it like 1.8. or 2026-08-01.', $this->lastText());
    }
}
