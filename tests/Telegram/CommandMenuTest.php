<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Telegram\CommandMenu;

/**
 * The bot's command menus: the group and private-chat lists, each person's
 * own list for organizers and welcome crew, the Finnish copy of every list,
 * and the refreshes that add and remove the personal lists.
 */
final class CommandMenuTest extends TelegramTestCase
{
    private const EVERYONE = ['board', 'me', 'tasks', 'tickets', 'settings', 'help'];

    /**
     * @return array<string, mixed>
     */
    private function personRow(bool $organizer = false, ?int $telegramUserId = 555): array
    {
        return [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => $organizer ? 1 : 0,
            'telegram_user_id' => $telegramUserId,
        ];
    }

    /**
     * The setMyCommands and deleteMyCommands calls, in order, as
     * [method, scope, language_code, command names, descriptions].
     *
     * @return array<int, array{0: string, 1: array<string, mixed>, 2: string, 3: array<int, string>, 4: array<int, string>}>
     */
    private function menuCalls(): array
    {
        $calls = [];

        foreach ($this->telegramCalls as $call) {
            if (! in_array($call['method'], ['setMyCommands', 'deleteMyCommands'], true)) {
                continue;
            }

            $commands = (array) ($call['body']['commands'] ?? []);
            $calls[] = [
                $call['method'],
                (array) ($call['body']['scope'] ?? []),
                (string) ($call['body']['language_code'] ?? ''),
                array_column($commands, 'command'),
                array_column($commands, 'description'),
            ];
        }

        return $calls;
    }

    public function testInstallSendsTheGroupAndPrivateListsInEnglishAndFinnish(): void
    {
        // No nearby nights and no organizers: no personal lists.
        $this->commandMenu()->install();

        $calls = $this->menuCalls();

        self::assertCount(4, $calls);
        self::assertSame(['setMyCommands', ['type' => 'all_group_chats'], '', ['board', 'help']], array_slice($calls[0], 0, 4));
        self::assertSame(['setMyCommands', ['type' => 'all_group_chats'], 'fi', ['board', 'help']], array_slice($calls[1], 0, 4));
        self::assertSame(['setMyCommands', ['type' => 'all_private_chats'], '', self::EVERYONE], array_slice($calls[2], 0, 4));
        self::assertSame(['setMyCommands', ['type' => 'all_private_chats'], 'fi', self::EVERYONE], array_slice($calls[3], 0, 4));

        self::assertSame('Show the open tasks', $calls[0][4][0]);
        self::assertSame('Näytä avoimet tehtävät', $calls[1][4][0]);
        self::assertSame('How this works, commands and FAQ', $calls[2][4][5]);
        self::assertSame('Miten tämä toimii, komennot ja UKK', $calls[3][4][5]);

        foreach ($calls as $call) {
            foreach ($call[4] as $description) {
                self::assertStringNotContainsString('DM', $description);
                self::assertGreaterThanOrEqual(3, mb_strlen($description));
                self::assertLessThanOrEqual(256, mb_strlen($description));
            }

            foreach ($call[3] as $command) {
                self::assertMatchesRegularExpression('/^[a-z0-9_]{1,32}$/', $command);
            }
        }

        self::assertSame([], $this->options[CommandMenu::PEOPLE_OPTION]);
    }

    public function testInstallGivesAnOrganizerTheOrganizerCommands(): void
    {
        $this->wpdb->nextCols[] = [];                                       // nearbyDates
        $this->wpdb->nextResults[] = [$this->personRow(true)];             // organizers

        $this->commandMenu()->install();

        $calls = array_slice($this->menuCalls(), 4);
        $expected = array_merge(self::EVERYONE, ['roster', 'door', 'people']);

        self::assertCount(2, $calls);
        self::assertSame(['setMyCommands', ['type' => 'chat', 'chat_id' => 555], '', $expected], array_slice($calls[0], 0, 4));
        self::assertSame(['setMyCommands', ['type' => 'chat', 'chat_id' => 555], 'fi', $expected], array_slice($calls[1], 0, 4));
        self::assertSame('Vapaaliput, roolit, järjestäjät ja passit', $calls[1][4][array_search('people', $expected, true)]);
        self::assertSame([7], $this->options[CommandMenu::PEOPLE_OPTION]);
    }

    public function testTheDailyRefreshGivesWelcomeCrewOnlyTheWelcomeCrewCommands(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-01'];                          // nearbyDates
        $this->wpdb->nextResults[] = [];                                   // organizers
        $this->wpdb->nextCols[] = [7];                                     // personIdsAssignedOn
        $this->wpdb->nextRows[] = $this->personRow();                      // find
        $this->wpdb->nextResults[] = [['id' => 6, 'task_date' => '2026-08-01', 'role_slug' => 'welcome', 'capacity' => 2]];
        $this->wpdb->nextRows[] = ['id' => 30, 'task_id' => 6, 'person_id' => 7, 'status' => 'signed_up']; // findFor

        $this->commandMenu()->refreshIfDue();

        $calls = $this->menuCalls();
        $expected = array_merge(self::EVERYONE, ['roster', 'door']);

        self::assertCount(2, $calls);
        self::assertSame(['setMyCommands', ['type' => 'chat', 'chat_id' => 555], '', $expected], array_slice($calls[0], 0, 4));
        self::assertSame(['setMyCommands', ['type' => 'chat', 'chat_id' => 555], 'fi', $expected], array_slice($calls[1], 0, 4));
        self::assertSame([7], $this->options[CommandMenu::PEOPLE_OPTION]);
        self::assertIsInt($this->options[CommandMenu::REFRESHED_OPTION]);
    }

    public function testTheDailyRefreshSkipsSomeoneOnANightWhoIsNotWelcomeCrew(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-01'];                          // nearbyDates
        $this->wpdb->nextResults[] = [];                                   // organizers
        $this->wpdb->nextCols[] = [7];                                     // personIdsAssignedOn
        $this->wpdb->nextRows[] = $this->personRow();                      // find
        $this->wpdb->nextResults[] = [['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3]];

        $this->commandMenu()->refreshIfDue();

        self::assertSame([], $this->menuCalls());
        self::assertSame([], $this->options[CommandMenu::PEOPLE_OPTION]);
    }

    public function testTheDailyRefreshDeletesTheListOfSomeoneWhoNoLongerQualifies(): void
    {
        $this->options[CommandMenu::PEOPLE_OPTION] = [7];
        $this->wpdb->nextCols[] = [];                                      // nearbyDates
        $this->wpdb->nextResults[] = [];                                   // organizers
        $this->wpdb->nextRows[] = $this->personRow();                      // find, for the delete

        $this->commandMenu()->refreshIfDue();

        self::assertSame(
            [
                ['deleteMyCommands', ['type' => 'chat', 'chat_id' => 555], '', [], []],
                ['deleteMyCommands', ['type' => 'chat', 'chat_id' => 555], 'fi', [], []],
            ],
            $this->menuCalls()
        );
        self::assertSame([], $this->options[CommandMenu::PEOPLE_OPTION]);
    }

    public function testTheDailyRefreshWaitsADay(): void
    {
        $this->options[CommandMenu::REFRESHED_OPTION] = time() - 3600;

        $this->commandMenu()->refreshIfDue();

        self::assertSame([], $this->telegramCalls);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testNothingIsSentWithoutABotToken(): void
    {
        unset($this->options[\EventCrew\Telegram\TelegramClient::TOKEN_OPTION]);

        $this->commandMenu()->install();
        $this->commandMenu()->refreshIfDue();
        $this->commandMenu()->refreshPerson(7);

        self::assertSame([], $this->telegramCalls);
    }

    public function testADemotedOrganizerHasTheirListDeleted(): void
    {
        $this->options[CommandMenu::PEOPLE_OPTION] = [3, 7];
        $this->wpdb->nextRows[] = $this->personRow(false);                 // find: no longer an organizer
        $this->wpdb->nextCols[] = [];                                      // nearbyDates: not welcome crew

        $this->commandMenu()->refreshPerson(7);

        self::assertSame(
            [
                ['deleteMyCommands', ['type' => 'chat', 'chat_id' => 555], '', [], []],
                ['deleteMyCommands', ['type' => 'chat', 'chat_id' => 555], 'fi', [], []],
            ],
            $this->menuCalls()
        );
        self::assertSame([3], $this->options[CommandMenu::PEOPLE_OPTION]);
    }

    public function testANewOrganizerGetsTheirList(): void
    {
        $this->options[CommandMenu::PEOPLE_OPTION] = [3];
        $this->wpdb->nextRows[] = $this->personRow(true);

        $this->commandMenu()->refreshPerson(7);

        $calls = $this->menuCalls();
        self::assertCount(2, $calls);
        self::assertSame('setMyCommands', $calls[0][0]);
        self::assertContains('people', $calls[0][3]);
        self::assertSame([3, 7], $this->options[CommandMenu::PEOPLE_OPTION]);
    }

    public function testSomeoneWithoutTelegramGetsNoList(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(true, null);

        $this->commandMenu()->refreshPerson(7);

        self::assertSame([], $this->telegramCalls);
    }

    public function testATelegramErrorOnAPersonalListIsLoggedAsInfo(): void
    {
        $this->telegramErrors['setMyCommands'] = 'Bad Request: chat not found';
        $this->wpdb->nextRows[] = $this->personRow(true);

        $this->commandMenu()->refreshPerson(7);

        $logs = (array) $this->options['eventcrew_recent_logs'];
        self::assertCount(2, $logs);
        self::assertSame(['INFO', 'INFO'], array_column($logs, 'level'));
        self::assertStringContainsString('chat not found', (string) $logs[0]['message']);
    }

    public function testArrivingOnASalesDeskTaskRefreshesThatPerson(): void
    {
        $this->wpdb->nextRows[] = ['id' => 30, 'task_id' => 6, 'person_id' => 7, 'status' => 'arrived']; // assignment
        $this->wpdb->nextRows[] = ['id' => 6, 'task_date' => '2026-08-01', 'role_slug' => 'welcome', 'capacity' => 2]; // task
        $this->wpdb->nextRows[] = $this->personRow();                      // person
        $this->wpdb->nextCols[] = ['2026-08-01'];                          // nearbyDates
        $this->wpdb->nextResults[] = [['id' => 6, 'task_date' => '2026-08-01', 'role_slug' => 'welcome', 'capacity' => 2]];
        $this->wpdb->nextRows[] = ['id' => 30, 'task_id' => 6, 'person_id' => 7, 'status' => 'arrived']; // findFor

        $this->commandMenu()->onStatusChanged(30, 'arrived');

        $calls = $this->menuCalls();
        self::assertCount(2, $calls);
        self::assertSame(array_merge(self::EVERYONE, ['roster', 'door']), $calls[0][3]);
        self::assertSame([7], $this->options[CommandMenu::PEOPLE_OPTION]);
    }

    public function testArrivingOnAnotherTaskChangesNoList(): void
    {
        $this->wpdb->nextRows[] = ['id' => 30, 'task_id' => 5, 'person_id' => 7, 'status' => 'arrived'];
        $this->wpdb->nextRows[] = ['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3];
        // Only read if the role check were missing: then a list would be sent.
        $this->wpdb->nextRows[] = $this->personRow(true);

        $this->commandMenu()->onStatusChanged(30, 'arrived');
        $this->commandMenu()->onStatusChanged(30, 'no_show');

        self::assertSame([], $this->telegramCalls);
    }

    public function testBootListensForOrganizerChangesAndArrivals(): void
    {
        $menu = $this->commandMenu();
        $menu->boot();

        self::assertNotFalse(has_action(CommandMenu::ORGANIZER_CHANGED_HOOK, [$menu, 'refreshPerson']));
        self::assertNotFalse(has_action('eventcrew/assignment_status_changed', [$menu, 'onStatusChanged']));
    }
}
