<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
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
        self::assertStringContainsString('4', $body); // completed count
        self::assertStringContainsString('2', $body); // credit balance
        self::assertStringContainsString('Mon 20 Jul — Decorate', $body); // a recent task line
    }

    /**
     * Owner 2026-11-07: My info has a cancel button for each coming task, above
     * the menu. It sends the board's leave tap, which asks before cancelling.
     */
    public function testMeHasACancelButtonForEachComingTask(): void
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
        $this->wpdb->nextResults[] = $history; // standing
        $this->wpdb->nextVars[] = 0; // redemptions
        $this->wpdb->nextResults[] = $history; // coming tasks
        $this->wpdb->nextRows[] = $this->taskRow(1);
        $this->wpdb->nextRows[] = ['id' => 2, 'task_date' => '2026-07-27'] + $this->taskRow(2);

        $this->service()->onMe(555, 555);

        $rows = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];
        self::assertSame('✖️ Cancel Decorate, Mon 20 Jul', $rows[0][0]['text']);
        self::assertSame('l:1', $rows[0][0]['callback_data']);
        self::assertSame('✖️ Cancel Decorate, Mon 27 Jul', $rows[1][0]['text']);
        self::assertSame('l:2', $rows[1][0]['callback_data']);
        self::assertSame('mn:ticket', $rows[2][0]['callback_data']);
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
        // and the public chat (999) only gets the nudge.
        $this->service()->onMyHistory(555, 999, false);

        $dm = $this->telegramCalls[0]['body'];
        self::assertSame(555, $dm['chat_id']);
        self::assertStringContainsString('Sat 18 Jul — Decorate', (string) $dm['text']);

        self::assertSame(999, $this->telegramCalls[1]['body']['chat_id']);
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
        $rows = $dm['reply_markup']['inline_keyboard'];
        self::assertCount(1, $rows);
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
}
