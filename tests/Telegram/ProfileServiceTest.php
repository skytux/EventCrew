<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AppInstallPrompt;
use EventCrew\Telegram\DmNote;
use EventCrew\Telegram\ProfileService;

final class ProfileServiceTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
    }

    private function service(): ProfileService
    {
        return new ProfileService(
            new PersonRepository(),
            new AssignmentRepository(),
            new TaskRepository(),
            $this->standing(),
            $this->client()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $id): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-07-20',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Dance',
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    public function testMeReportsStandingCreditsAndRecentTasks(): void
    {
        // findByTelegramUserId -> a verified person
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];
        // standing->for(7): historyFor (four completions -> rated, two credits)
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-20'],
            ['id' => 2, 'task_id' => 2, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-19'],
            ['id' => 3, 'task_id' => 3, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-18'],
            ['id' => 4, 'task_id' => 4, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-17'],
        ];
        $this->wpdb->nextVars[] = 0; // countFor redemptions
        // recentLines: historyFor again, then tasks->find for the first three
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-20'],
            ['id' => 2, 'task_id' => 2, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-19'],
            ['id' => 3, 'task_id' => 3, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-18'],
        ];
        $this->wpdb->nextRows[] = $this->taskRow(1);
        $this->wpdb->nextRows[] = $this->taskRow(2);
        $this->wpdb->nextRows[] = $this->taskRow(3);

        $this->service()->onMe(555, 555);

        $body = (string) $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('Sam', $body);
        self::assertStringContainsString('Good standing', $body);
        // Owner 2026-11-09: the bar alone, so the score and the level show once.
        self::assertSame(1, substr_count($body, '%'));
        self::assertSame(1, substr_count($body, 'Good standing'));
        self::assertStringContainsString('4', $body); // completed count
        self::assertStringContainsString('2', $body); // credit balance
        self::assertStringContainsString('Mon 20 Jul — Decorate', $body); // a recent task line
    }

    /**
     * Owner 2026-11-07: the Tasks screen has a cancel button for each coming
     * task, above the board, Take over a task and Past tasks. It sends the board's leave tap, which
     * asks before cancelling.
     */
    public function testTasksHasACancelButtonForEachComingTask(): void
    {
        Functions\when('current_time')->justReturn('2026-07-10');
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];
        $history = [
            ['id' => 2, 'task_id' => 2, 'person_id' => 7, 'status' => 'signed_up', 'task_date' => '2026-07-27'],
            ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'signed_up', 'task_date' => '2026-07-20'],
            ['id' => 3, 'task_id' => 3, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-03'],
        ];
        $this->wpdb->nextResults[] = $history; // coming tasks
        $this->wpdb->nextRows[] = $this->taskRow(1);
        $this->wpdb->nextRows[] = ['id' => 2, 'task_date' => '2026-07-27'] + $this->taskRow(2);

        $this->service()->onMyTasks(555, 555);

        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(
            "📋 Tasks\nComing up:\n• Mon 20 Jul — Decorate (Dance)\n• Mon 27 Jul — Decorate (Dance)",
            $dm['text']
        );
        $rows = $dm['reply_markup']['inline_keyboard'];
        self::assertSame('✖️ Cancel Decorate, Mon 20 Jul', $rows[0][0]['text']);
        self::assertSame('l:1', $rows[0][0]['callback_data']);
        self::assertSame('✖️ Cancel Decorate, Mon 27 Jul', $rows[1][0]['text']);
        self::assertSame('l:2', $rows[1][0]['callback_data']);
        self::assertSame(['mn:board', 'mn:replace', 'mn:hist', 'mn:me'], array_column(array_merge(...array_slice($rows, 2)), 'callback_data'));
    }

    private function sam(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];
    }

    /**
     * $count past tasks, newest first, as historyFor gives them; their tasks
     * are not looked up (get_row returns nothing), so each reads "a task".
     *
     * @return list<array<string, mixed>>
     */
    private function pastEntries(int $count): array
    {
        $entries = [];

        for ($i = 1; $i <= $count; $i++) {
            $entries[] = ['id' => $i, 'task_id' => $i, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-06-30'];
        }

        return $entries;
    }

    /**
     * Owner 2026-11-08: all past tasks, 25 to a page, with ◀️ ▶️.
     */
    public function testPastTasksComeTwentyFiveToAPage(): void
    {
        Functions\when('current_time')->justReturn('2026-07-10');

        // Exactly 25: one page, no page buttons, no "page 1 of 1".
        $this->sam();
        $this->wpdb->nextResults[] = $this->pastEntries(25);
        $this->service()->onMyHistory(555, 555);
        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(26, substr_count((string) $dm['text'], "\n") + 1);
        self::assertStringNotContainsString('page', (string) $dm['text']);
        self::assertSame([[['text' => '⬅️ Back', 'callback_data' => 'mn:mytasks']]], $dm['reply_markup']['inline_keyboard']);

        // 26: the first page has 25 and ▶️ to page 2.
        $this->sam();
        $this->wpdb->nextResults[] = $this->pastEntries(26);
        $this->service()->onMyHistory(555, 555);
        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(25, substr_count((string) $dm['text'], '• '));
        self::assertStringEndsWith("\n\npage 1 of 2", (string) $dm['text']);
        self::assertSame('mn:hist:2', $dm['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        // Page 2 has the 26th and ◀️ back to page 1; a page beyond the last
        // shows the last.
        foreach ([2, 7] as $page) {
            $this->sam();
            $this->wpdb->nextResults[] = $this->pastEntries(26);
            $this->service()->onMyHistory(555, 555, true, $page);
            $dm = $this->lastCallTo('sendMessage');
            self::assertSame(1, substr_count((string) $dm['text'], '• '));
            self::assertStringEndsWith('page 2 of 2', (string) $dm['text']);
            $rows = $dm['reply_markup']['inline_keyboard'];
            self::assertSame([['text' => '◀️', 'callback_data' => 'mn:hist']], $rows[0]);
            self::assertSame('mn:mytasks', $rows[1][0]['callback_data']);
        }
    }

    public function testNoPastTasksSaysSo(): void
    {
        Functions\when('current_time')->justReturn('2026-07-10');
        $this->sam();
        $this->wpdb->nextResults[] = [];

        $this->service()->onMyHistory(555, 555, true, 3);

        $dm = $this->lastCallTo('sendMessage');
        self::assertSame('No past tasks yet — your history starts once you’ve worked one.', $dm['text']);
        self::assertSame(['mn:mytasks'], array_column(array_merge(...$dm['reply_markup']['inline_keyboard']), 'callback_data'));
    }

    /**
     * Owner 2026-11-08: coming tasks page too, each page with the cancel
     * buttons of its own tasks.
     */
    public function testComingTasksPageWithTheirCancelButtons(): void
    {
        Functions\when('current_time')->justReturn('2026-07-10');
        $coming = [];

        // historyFor is newest first: id 26 is the latest, id 1 the soonest.
        for ($i = 26; $i >= 1; $i--) {
            $coming[] = ['id' => $i, 'task_id' => $i, 'person_id' => 7, 'status' => 'signed_up', 'task_date' => '2026-07-20'];
        }

        foreach ([1 => 25, 2 => 1] as $page => $count) {
            $this->sam();
            $this->wpdb->nextResults[] = $coming;

            for ($i = 1; $i <= 26; $i++) {
                $this->wpdb->nextRows[] = ['id' => $i] + $this->taskRow($i);
            }

            $this->service()->onMyTasks(555, 555, true, $page);

            $dm = $this->lastCallTo('sendMessage');
            self::assertStringStartsWith("📋 Tasks · page {$page} of 2\n", (string) $dm['text']);
            self::assertSame($count, substr_count((string) $dm['text'], '• '));
            $data = array_column(array_merge(...$dm['reply_markup']['inline_keyboard']), 'callback_data');
            $cancels = array_values(array_filter($data, static fn (string $d): bool => str_starts_with($d, 'l:')));
            self::assertSame(1 === $page ? array_map(static fn (int $i): string => 'l:' . $i, range(1, 25)) : ['l:26'], $cancels);
            self::assertContains(1 === $page ? 'mn:mytasks:2' : 'mn:mytasks', $data);
        }
    }

    /**
     * Owner 2026-11-07: My info is only the facts - no explanations - and one
     * full-width button per row: Tickets, Tasks, Settings, Help, and Admin
     * at the bottom for an organizer.
     */
    public function testMeIsOnlyTheFactsWithOneButtonPerRow(): void
    {
        Functions\when('current_time')->justReturn('2026-07-10');

        foreach ([false, true] as $organizer) {
            $this->wpdb->nextRows[] = [
                'id' => 7,
                'email' => 'sam@example.com',
                'display_name' => 'Sam',
                'email_verified_at' => '2026-07-01 00:00:00',
                'is_organizer' => $organizer ? 1 : 0,
                'telegram_user_id' => 555,
            ];
            $history = [
                ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'signed_up', 'task_date' => '2026-07-20'],
            ];
            $this->wpdb->nextResults[] = $history; // standing
            $this->wpdb->nextVars[] = 0; // redemptions
            $this->wpdb->nextResults[] = $history; // coming tasks
            $this->wpdb->nextRows[] = $this->taskRow(1);

            $this->service()->onMe(555, 555);

            $dm = $this->lastCallTo('sendMessage');
            $lines = explode("\n", (string) $dm['text']);
            // Owner, Q344 2026-11-07: a title line, the blocks apart, a few sparkles.
            self::assertSame(['✨ My info ✨', '', '🌟 Sam'], array_slice($lines, 0, 3));
            self::assertNotSame('', $lines[3]); // the standing
            self::assertSame(
                [
                    'You get a standing score after 3 more tasks.',
                    '',
                    'Completed tasks: 0',
                    'Free-entry credits: 0',
                    '',
                    '💫 Coming up:',
                    '• Mon 20 Jul — Decorate (Dance)',
                ],
                array_slice($lines, 4)
            );

            $rows = $dm['reply_markup']['inline_keyboard'];
            $expected = ['mn:tix', 'mn:mytasks', 'mn:set', 'mn:help'];

            if ($organizer) {
                $expected[] = 'mn:admin';
            }

            self::assertSame($expected, array_map(static fn (array $row): string => $row[0]['callback_data'], $rows));
            self::assertSame([1], array_unique(array_map('count', $rows)));
        }
    }

    public function testMeAsksAnUnknownUserToSetUpFirst(): void
    {
        // No person row queued -> findByTelegramUserId returns null.
        $this->service()->onMe(999, 999);

        self::assertStringContainsString('/start', (string) $this->lastCallTo('sendMessage')['text']);
    }

    public function testWebDmsAOneTimeSignInLinkToTheBoard(): void
    {
        Functions\when('get_permalink')->justReturn('https://site.test/signup/');
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        $this->options['eventcrew_signup_page_id'] = 42;

        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];

        $this->service()->onWeb(555, 555);

        // The link is a named button, never a raw address in the text.
        $dm = $this->lastCallTo('sendMessage');
        self::assertStringNotContainsString('eventcrew_login', (string) $dm['text']);
        self::assertStringContainsString(
            'https://site.test/signup/?eventcrew_login=',
            (string) $dm['reply_markup']['inline_keyboard'][0][0]['url']
        );
        // A single-use token was issued (a row written to auth_tokens).
        self::assertNotEmpty($this->wpdb->inserts);
    }

    public function testWebAsksAnUnknownUserToSetUpFirst(): void
    {
        $this->service()->onWeb(999, 999);

        self::assertStringContainsString('/start', (string) $this->lastCallTo('sendMessage')['text']);
    }

    public function testAppInviteDmsASignInLinkThatOpensTheGetTheAppPopIn(): void
    {
        Functions\when('get_permalink')->justReturn('https://site.test/signup/');
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        $this->options['eventcrew_signup_page_id'] = 42;

        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];

        // A group tap: the DM goes to the person, the tap gets the toast.
        DmNote::holdTap('cbq');
        $this->service()->onAppInvite(555, -100, false);

        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(555, $dm['chat_id']);
        self::assertStringContainsString('Chrome-based browsers', (string) $dm['text']);
        self::assertStringNotContainsString('eventcrew_login', (string) $dm['text']);

        $url = (string) $dm['reply_markup']['inline_keyboard'][0][0]['url'];
        self::assertStringContainsString('https://site.test/signup/?eventcrew_login=', $url);
        self::assertStringContainsString(AppInstallPrompt::INVITE_PARAM . '=1', $url);
        self::assertNotEmpty($this->wpdb->inserts, 'a single-use token was issued');

        self::assertSame('📬 Sent you a DM.', (string) $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame('', DmNote::takeTap());
    }

    public function testAppInviteAsksAnUnknownTapperToSetUpFirstInTheGroup(): void
    {
        $this->service()->onAppInvite(999, -100, false);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(-100, $sent['chat_id']);
        self::assertStringContainsString('/start', (string) $sent['text']);
    }

    public function testMyHistoryListsPastTasksInTheDm(): void
    {
        // A verified person...
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];
        // ...whose history has one past task (today is 2026-07-20).
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-18'],
        ];
        $this->wpdb->nextRows[] = $this->taskRow(1);

        // From the group (isPrivate = false): the list lands in the DM (555),
        // and the public chat (999) gets nothing.
        $this->service()->onMyHistory(555, 999, false);

        $dm = $this->telegramCalls[0]['body'];
        self::assertSame(555, $dm['chat_id']);
        self::assertStringContainsString('Sat 18 Jul — Decorate', (string) $dm['text']);

        self::assertSame([], array_filter(array_column($this->telegramCalls, 'body'), static fn (array $b): bool => 999 === ($b['chat_id'] ?? null)));
    }
    public function testMyTicketsGivesEachUpcomingTicketANamedButtonAndNoRawLinks(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];
        // One slot still ahead and one already worked (today is 2026-07-20).
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'signed_up'],
            ['id' => 2, 'task_id' => 2, 'person_id' => 7, 'status' => 'completed'],
        ];
        $this->wpdb->nextRows[] = ['task_date' => '2026-07-25'] + $this->taskRow(1);
        $this->wpdb->nextRows[] = ['id' => 2, 'task_date' => '2026-07-18'] + $this->taskRow(2);
        $this->wpdb->nextResults[] = []; // no free-entry redemptions
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );

        $this->service()->onMyTickets(555, 555);

        $dm = $this->lastCallTo('sendMessage');
        $text = (string) $dm['text'];
        self::assertStringContainsString('2026-07-25', $text);
        self::assertStringContainsString('2026-07-18', $text);
        self::assertStringNotContainsString('token=', $text);

        // Only the upcoming ticket gets a button; the past one is just listed.
        // Then 🎟 Free-entry ticket and the way back to My info.
        $rows = $dm['reply_markup']['inline_keyboard'];
        self::assertCount(3, $rows);
        self::assertSame('mn:ticket', $rows[1][0]['callback_data']);
        self::assertSame('mn:me', $rows[2][0]['callback_data']);
        self::assertStringContainsString('2026-07-25', $rows[0][0]['text']);
        self::assertStringContainsString('eventcrew/v1/ticket?token=', $rows[0][0]['url']);
    }

    public function testMyTicketsSaysWhenATicketWasAlreadyUsed(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'signed_up'],
            ['id' => 2, 'task_id' => 2, 'person_id' => 7, 'status' => 'completed'],
        ];
        $this->wpdb->nextRows[] = ['task_date' => '2026-07-25'] + $this->taskRow(1);
        $this->wpdb->nextRows[] = ['id' => 2, 'task_date' => '2026-07-18'] + $this->taskRow(2);
        $this->wpdb->nextResults[] = []; // no free-entry redemptions
        $this->wpdb->nextResults[] = [ // checked in at the door on 18 July
            ['event_date' => '2026-07-18', 'kind' => 'crew', 'checked_in_at' => '2026-07-18 21:05:00'],
        ];
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );

        $this->service()->onMyTickets(555, 555);

        $text = (string) $this->lastCallTo('sendMessage')['text'];
        self::assertSame(1, substr_count($text, '🟠 Already used · 21:05'));
        self::assertMatchesRegularExpression('/2026-07-18[^\n]*🟠 Already used · 21:05/u', $text);
    }

    /**
     * Owner 2026-11-08: "Add the time for the tasks in the task list" - each
     * coming task shows its own times, else its event's; with a line that
     * long the section gets an empty line under each line (DM spacing rule).
     */
    public function testComingTasksShowTheirTimeElseTheEventsTime(): void
    {
        Functions\when('current_time')->justReturn('2026-07-10');
        Functions\when('post_type_exists')->justReturn(true);
        $event = (object) ['ID' => 41, 'post_type' => 'eventmesh_event', 'post_title' => 'Dance', 'post_status' => 'publish'];
        Functions\when('get_post')->alias(static fn (int $id): ?object => 41 === $id ? $event : null);
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key): string => [
            '_eventmesh_starts_at' => '2026-07-27T21:00:00',
            '_eventmesh_ends_at' => '2026-07-28T02:00:00',
        ][$key] ?? '');
        $expected = [
            // Every line fits: no empty lines.
            'Dance' => "📋 Tasks\nComing up:\n"
                . "• Mon 20 Jul 18:15–19:15 — Decorate (Dance)\n"
                . "• Mon 27 Jul 21:00–02:00 (+1) — Decorate (Dance)\n"
                . '• Wed 29 Jul — Decorate (Dance)',
            // One line likely to wrap spaces out the whole section.
            'Summer Salsa Night' => "📋 Tasks\n\nComing up:\n\n"
                . "• Mon 20 Jul 18:15–19:15 — Decorate (Summer Salsa Night)\n\n"
                . "• Mon 27 Jul 21:00–02:00 (+1) — Decorate (Dance)\n\n"
                . '• Wed 29 Jul — Decorate (Dance)',
        ];

        foreach ($expected as $label => $text) {
            $this->wpdb->nextRows[] = [
                'id' => 7,
                'email' => 'sam@example.com',
                'display_name' => 'Sam',
                'email_verified_at' => '2026-07-01 00:00:00',
                'telegram_user_id' => 555,
            ];
            $this->wpdb->nextResults[] = [
                ['id' => 3, 'task_id' => 3, 'person_id' => 7, 'status' => 'signed_up', 'task_date' => '2026-07-29'],
                ['id' => 2, 'task_id' => 2, 'person_id' => 7, 'status' => 'signed_up', 'task_date' => '2026-07-27'],
                ['id' => 1, 'task_id' => 1, 'person_id' => 7, 'status' => 'signed_up', 'task_date' => '2026-07-20'],
            ];
            $this->wpdb->nextRows[] = [
                'starts_at' => '2026-07-20 18:15:00',
                'ends_at' => '2026-07-20 19:15:00',
                'event_label' => $label,
            ] + $this->taskRow(1);
            $this->wpdb->nextRows[] = ['task_date' => '2026-07-27', 'event_post_id' => 41] + $this->taskRow(2);
            $this->wpdb->nextRows[] = ['id' => 3, 'task_date' => '2026-07-29'] + $this->taskRow(3);

            $this->service()->onMyTasks(555, 555);

            self::assertSame($text, $this->lastCallTo('sendMessage')['text']);
        }
    }
}
