<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\MonthlyReportRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MonthlyReport;
use EventCrew\Tests\TestCase;

/**
 * The organizers' monthly report: what it counts from the database, the
 * table it mails, and that it goes once a month to the organizers only.
 */
final class MonthlyReportTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    private Mailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->mailer = new Mailer(new Logger(), new EmailTemplate(new Logger(), true), true);

        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
        Functions\when('add_action')->justReturn(true);
        Functions\when('remove_action')->justReturn(true);
        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://example.test/wp-admin/' . $path
        );
        Functions\when('get_userdata')->alias(
            static fn (int $id): object|false => 3 === $id ? (object) ['display_name' => 'Lou Admin'] : false
        );
    }

    public function testDoneForOthersGroupsTheMonthsActionsByWhoActed(): void
    {
        $actions = [
            self::action('2026-10-03 18:05:00', 'organizer', 7, 'Kim', 'assigned', 'bot', 'Sam', 'bar', '2026-10-10'),
            self::action(
                '2026-10-04 12:00:00',
                'wp_user',
                3,
                '',
                'settings_changed',
                'admin',
                null,
                '',
                null,
                '{"options":["send_window"]}'
            ),
            self::action('2026-10-14 21:03:00', 'organizer', 7, 'Kim', 'cancelled', 'bot', 'Ann', 'door', '2026-10-17'),
            self::action('2026-10-20 09:30:00', 'wp_user', 99, '', 'task_created', 'admin', null, 'bar', '2026-10-24'),
        ];

        $done = $this->report()->doneForOthers($actions);

        self::assertSame(
            "DONE FOR OTHERS\n\n"
            // The most actions first; each line in time order, with the channel.
            . "Kim · 2\n"
            // The role's label (its slug here, with no roles set up).
            . "Sat 3 Oct 18:05 — Sam was put on the task · bar 20:00 · 10.10. (bot)\n"
            . "Wed 14 Oct 21:03 — Ann cancelled · door 20:00 · 17.10. (bot)\n\n"
            // A WordPress user by their display name, one with none by number; a tie in name order.
            . "Lou Admin · 1\n"
            . "Sun 4 Oct 12:00 — Settings changed: send window (wp-admin)\n\n"
            . "user #99 · 1\n"
            . "Tue 20 Oct 09:30 — Task added · bar 20:00 · 24.10. (wp-admin)\n\n"
            . 'Full list on the History page: https://example.test/wp-admin/admin.php?page=eventcrew-history',
            $done['text']
        );
        self::assertStringContainsString('>Kim · 2</p>', $done['html']);
        self::assertStringContainsString(
            'href="https://example.test/wp-admin/admin.php?page=eventcrew-history"',
            $done['html']
        );

        // In the email, after the funnel.
        $mail = $this->report()->compose($this->stats('2026-10', 1.0), $this->stats('2026-09', 1.0), $actions);
        self::assertStringContainsString("share of the row above\n\nDONE FOR OTHERS\n\nKim · 2", $mail['text']);
    }

    public function testDoneForOthersListsAtMostAHundredPerActor(): void
    {
        $actions = [];

        for ($i = 0; $i < MonthlyReport::ACTIONS_PER_ACTOR + 3; $i++) {
            $actions[] = self::action(
                sprintf('2026-10-%02d 10:%02d:00', 1 + intdiv($i, 60), $i % 60),
                'organizer',
                7,
                'Kim',
                'signed_up',
                'bot',
                'Sam'
            );
        }

        $text = $this->report()->doneForOthers($actions)['text'];

        self::assertStringContainsString('Kim · 103', $text);
        self::assertSame(MonthlyReport::ACTIONS_PER_ACTOR, substr_count($text, 'Sam signed up'));
        self::assertStringContainsString("Sam signed up (bot)\nand 3 more\n\nFull list", $text);
    }

    public function testAMonthWithNothingDoneForOthersSaysSo(): void
    {
        $done = $this->report()->doneForOthers([]);

        self::assertSame("DONE FOR OTHERS\nNothing done for others.", $done['text']);
        self::assertStringContainsString('Nothing done for others.', $done['html']);
        self::assertStringNotContainsString('href', $done['html']);
    }

    public function testTheReportReadsTheMonthsActionsOnceForAllOrganizers(): void
    {
        $this->queueMonth();
        $this->queueMonth();
        $this->wpdb->nextResults[] = [
            self::organizerRow(1, 'one@example.test'),
            self::organizerRow(2, 'two@example.test'),
        ];
        $this->wpdb->nextResults[] = [
            self::action('2026-10-03 18:05:00', 'organizer', 1, 'Organizer 1', 'assigned', 'bot', 'Sam'),
        ];

        self::assertSame(2, $this->report()->run($this->at('2026-11-01 09:00')));

        $captured = $this->mailer->captured();
        $reads = array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, "actor_kind IN ('organizer', 'wp_user')")
        );

        self::assertCount(1, $reads);
        self::assertStringContainsString("h.created_at >= '2026-10-01 00:00:00'", (string) current($reads));
        self::assertStringContainsString('Sam was put on the task', $captured[0]['message']);
        self::assertStringContainsString('Sam was put on the task', $captured[1]['message']);
    }

    public function testItCountsTheMonthFromTheTables(): void
    {
        $this->queueMonth();

        $stats = $this->report()->stats('2026-10');

        self::assertSame(
            [
                'month' => '2026-10',
                'new_people' => 4,
                // 1, 2, 3 in the bot log and 3, 9 acting in the bot: 1, 2, 3, 9.
                'bot' => 4,
                'web' => 2,
                // 7 on the web page, 4 in the bot, 1 by an organizer in wp-admin.
                'signups' => 12,
                'signups_web' => 7,
                'signups_bot' => 4,
                'cancellations' => 3,
                'cancellations_web' => 0,
                'cancellations_bot' => 3,
                'no_shows' => 1,
                // 2 + 1 + 0 done sign-ups.
                'completed' => 3,
                'free_entries' => 5,
                'buyers' => ['2026-10-10' => 40, '2026-10-24' => 25],
                'funnel' => [
                    // 1, 2, 3, 9 (bot), 5, 7 (web) and 4 (a task only).
                    'active' => 7,
                    'signed_up' => 3,
                    'completed' => 2,
                ],
                // Nothing counted, and no Burst table.
                'clicks' => 0,
                'visits' => 0,
            ],
            $stats
        );

        $queries = implode("\n", $this->wpdb->queries);

        // The bot log keeps UTC: October in Helsinki is 30.9. 21:00 to 31.10. 22:00 UTC.
        self::assertStringContainsString(
            "created_at >= '2026-09-30 21:00:00' AND created_at < '2026-10-31 22:00:00'",
            $queries
        );
        // The history and the people table keep site time.
        self::assertStringContainsString(
            "created_at >= '2026-10-01 00:00:00' AND created_at < '2026-11-01 00:00:00'",
            $queries
        );
        // Nights by their day.
        self::assertStringContainsString("task_date >= '2026-10-01' AND t.task_date < '2026-11-01'", $queries);
        self::assertStringContainsString("kind IN ('signed_up', 'assigned')", $queries);
        self::assertStringContainsString('GROUP BY channel', $queries);
        self::assertStringContainsString("kind IN ('cancelled', 'late_cancel')", $queries);
        self::assertStringContainsString("a.status IN ('no_show')", $queries);
        self::assertStringContainsString("kind IN ('credit', 'credit_x', 'transfer')", $queries);
        self::assertStringContainsString("SUM(a.status IN ('completed', 'arrived_late'))", $queries);
        self::assertStringContainsString('deleted_at IS NULL', $queries);
        self::assertStringNotContainsString('email', $queries);
        self::assertStringNotContainsString('display_name', $queries);
    }

    public function testTheEmailIsATableWithLastMonthInBrackets(): void
    {
        $mail = $this->report()->compose($this->stats('2026-10', 1.0), $this->stats('2026-09', 0.5));

        self::assertSame('Monthly report 10/2026', $mail['subject']);
        self::assertSame('EventCrew numbers for 10/2026. In brackets: 9/2026.', $mail['intro']);

        self::assertStringContainsString('New people: 4 (2)', $mail['text']);
        self::assertStringContainsString('Used the bot: 10 (5)', $mail['text']);
        self::assertStringContainsString('Free entries used: 6 (3)', $mail['text']);
        self::assertStringContainsString('Ticket buyers: 65 (32)', $mail['text']);
        // Sign-ups and cancellations split by where they were done.
        self::assertStringContainsString(
            "Sign-ups: 12 (6)\n\u{00A0}\u{00A0}· Web page: 8 (4)\n\u{00A0}\u{00A0}· Bot: 4 (2)\nCancellations: 2 (1)",
            $mail['text']
        );
        // Each night with its event's name, no brackets.
        self::assertStringContainsString("Sat 10 Oct · Night 2026-10-10: 40\n", $mail['text']);
        // The funnel with the share of the row above.
        self::assertStringContainsString('Active: 20 (10)', $mail['text']);
        self::assertStringContainsString('Signed up: 8 · 40% (4)', $mail['text']);
        self::assertStringContainsString('Completed: 6 · 75% (3)', $mail['text']);

        self::assertStringContainsString('<table role="presentation"', $mail['html']);
        self::assertStringContainsString('>Completed tasks</td>', $mail['html']);
        self::assertStringContainsString('>(2)</td>', $mail['html']);
        self::assertStringContainsString('Ticket buyers per event', $mail['html']);
    }

    public function testOtherCodeCanAddRows(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $rows): mixed {
                if (MonthlyReport::ROWS_FILTER === $hook) {
                    $rows[] = ['label' => 'Opened', 'key' => 'opened'];
                    $rows[] = 'not a row';
                }

                return $rows;
            }
        );

        $stats = $this->stats('2026-10', 1.0) + ['opened' => 9];
        $mail = $this->report()->compose($stats, $this->stats('2026-09', 1.0));

        // A key the month before lacks counts as 0.
        self::assertStringContainsString(
            "Free entries used: 6 (6)\nEmail button clicks: 0 (0)\nVisits from campaign links: 0 (0)\n"
            . "Opened: 9 (0)\nTicket buyers",
            $mail['text']
        );
    }

    public function testItAddsTheClicksAndCampaignVisitsOfTheMonth(): void
    {
        $this->options['eventcrew_stats_2026_10'] = [
            'clicks' => ['claim_notifier' => ['ticket' => 3, 'calendar' => 1], 'ticket' => ['open' => 2]],
            'campaigns' => ['newsletter' => 5, 'flyer' => 2],
            'visits' => 7,
        ];
        $this->queueMonth();
        $this->wpdb->nextVars[3] = 'wp_burst_statistics';
        $this->wpdb->nextRows[] = ['views' => '120', 'visitors' => '45'];

        $stats = $this->report()->stats('2026-10');

        self::assertSame(6, $stats['clicks']);
        self::assertSame(7, $stats['visits']);
        self::assertSame(3, $stats['click:claim_notifier:ticket']);
        self::assertSame(5, $stats['campaign:newsletter']);
        self::assertSame(120, $stats['burst_views']);
        self::assertSame(45, $stats['burst_visitors']);
    }

    public function testItShowsTheTopClicksAndCampaignsUnderTheirTotals(): void
    {
        $stats = $this->stats('2026-10', 1.0) + [
            'clicks' => 6,
            'visits' => 7,
            'click:claim_notifier:ticket' => 3,
            'click:ticket:open' => 3,
            'click:ticket_offer:accept' => 0,
            'campaign:newsletter' => 5,
            'campaign:flyer' => 2,
            'burst_views' => 120,
            'burst_visitors' => 45,
        ];
        $before = $this->stats('2026-09', 1.0) + ['clicks' => 2, 'click:ticket:open' => 2];

        $text = $this->report()->compose($stats, $before)['text'];

        // The biggest first, a tie in name order, a zero left out.
        self::assertStringContainsString(
            "Email button clicks: 6 (2)\n\u{00A0}\u{00A0}· claim notifier · ticket: 3 (0)\n"
            . "\u{00A0}\u{00A0}· ticket · open: 3 (2)\nVisits from campaign links: 7 (0)\n"
            . "\u{00A0}\u{00A0}· newsletter: 5 (0)\n\u{00A0}\u{00A0}· flyer: 2 (0)\n"
            . "Page views (Burst): 120 (0)\nVisitors (Burst): 45 (0)",
            $text
        );
        self::assertStringNotContainsString('ticket offer', $text);
    }

    public function testNoBurstTableMeansNoBurstRows(): void
    {
        $text = $this->report()->compose($this->stats('2026-10', 1.0), $this->stats('2026-09', 1.0))['text'];

        self::assertStringNotContainsString('Burst', $text);
    }

    public function testAMonthWithNoBuyersAndNobodySaysSo(): void
    {
        $empty = $this->stats('2026-10', 0.0);
        $empty['buyers'] = [];

        $mail = $this->report()->compose($empty, $empty);

        self::assertStringContainsString('No ticket buyers.', $mail['html']);
        self::assertStringContainsString('Signed up: 0 · – (0)', $mail['text']);
    }

    public function testItGoesToEveryOrganizerOnceOnThe1st(): void
    {
        $this->queueMonth();
        $this->queueMonth();
        $this->wpdb->nextResults[] = [
            self::organizerRow(1, 'one@example.test'),
            self::organizerRow(2, 'two@example.test'),
            self::organizerRow(3, 'gone@example.test', '2026-01-01 00:00:00'),
        ];

        $sent = $this->report()->run($this->at('2026-11-01 09:00'));

        self::assertSame(2, $sent);
        self::assertSame(['one@example.test', 'two@example.test'], array_column($this->mailer->captured(), 'to'));
        self::assertSame('Monthly report 10/2026', $this->mailer->captured()[0]['subject']);
        self::assertSame('2026-10', $this->options[MonthlyReport::SENT_OPTION]);

        // The next hour: already sent, nothing is read or mailed.
        $queries = count($this->wpdb->queries);
        self::assertSame(0, $this->report()->run($this->at('2026-11-01 10:00')));
        self::assertCount($queries, $this->wpdb->queries);
    }

    public function testAMissed1stIsCaughtUpInTheFirstWeekOnly(): void
    {
        self::assertSame(0, $this->report()->run($this->at('2026-11-08 09:00')));
        self::assertSame([], $this->wpdb->queries);

        $this->queueMonth();
        $this->queueMonth();
        $this->wpdb->nextResults[] = [self::organizerRow(1, 'one@example.test')];

        self::assertSame(1, $this->report()->run($this->at('2026-11-07 09:00')));
    }

    public function testNothingGoesWhileSwitchedOff(): void
    {
        MonthlyReport::save(false);

        self::assertFalse(MonthlyReport::isOn());
        self::assertSame(0, $this->report()->run($this->at('2026-11-01 09:00')));
        self::assertSame([], $this->mailer->captured());

        MonthlyReport::save(true);
        self::assertTrue(MonthlyReport::isOn());
    }

    public function testItIsOnByDefault(): void
    {
        self::assertTrue(MonthlyReport::isOn());
    }

    public function testTheTestReportGoesToTheGivenAddressOnly(): void
    {
        $this->queueMonth();
        $this->queueMonth();

        self::assertTrue($this->report()->sendTest('admin@example.test', null, $this->at('2026-11-20 12:00')));

        $captured = $this->mailer->captured();

        self::assertCount(1, $captured);
        self::assertSame('admin@example.test', $captured[0]['to']);
        self::assertSame('Monthly report 10/2026', $captured[0]['subject']);
        // Not a member: no sign-in footer.
        self::assertStringNotContainsString(Mailer::PREVIEW_LINK, $captured[0]['message']);
        // No organizer was read and nothing was noted as sent.
        self::assertArrayNotHasKey(MonthlyReport::SENT_OPTION, $this->options);
        self::assertFalse($this->report()->sendTest('not-an-address'));
    }

    public function testJanuaryReportsDecember(): void
    {
        self::assertSame('2026-12', MonthlyReport::previousMonth($this->at('2027-01-01 09:00')));
        self::assertSame('2026-02', MonthlyReport::previousMonth($this->at('2026-03-31 09:00')));
        self::assertSame('1/2027', MonthlyReport::monthLabel('2027-01'));
        self::assertSame('33%', MonthlyReport::percent(1, 3));
        self::assertSame('–', MonthlyReport::percent(1, 0));
    }

    private function report(): MonthlyReport
    {
        return new MonthlyReport(
            new MonthlyReportRepository(),
            new PersonRepository(),
            $this->mailer,
            null,
            static fn (string $date): string => 'Night ' . $date
        );
    }

    /** One month's query results, in the order stats() reads them. */
    private function queueMonth(): void
    {
        // get_col: the bot log, the history in the bot, the history on the web page.
        $this->wpdb->nextCols[] = ['1', '2', '3'];
        $this->wpdb->nextCols[] = ['3', '9'];
        $this->wpdb->nextCols[] = ['5', '7'];
        // get_results: the people with tasks, sign-ups and cancellations per
        // channel, then the buyers per night.
        $this->wpdb->nextResults[] = [
            ['person_id' => '1', 'n_all' => '2', 'n_matched' => '2'],
            ['person_id' => '4', 'n_all' => '1', 'n_matched' => '1'],
            ['person_id' => '5', 'n_all' => '1', 'n_matched' => '0'],
        ];
        $this->wpdb->nextResults[] = [
            ['channel' => 'web', 'n' => '7'],
            ['channel' => 'bot', 'n' => '4'],
            ['channel' => 'admin', 'n' => '1'],
        ];
        $this->wpdb->nextResults[] = [['channel' => 'bot', 'n' => '3']];
        $this->wpdb->nextResults[] = [
            ['event_date' => '2026-10-10', 'n' => '40'],
            ['event_date' => '2026-10-24', 'n' => '25'],
        ];
        // get_var: new people, no-shows, free entries, then no Burst table.
        array_push($this->wpdb->nextVars, '4', '1', '5', null);
    }

    /**
     * Ready stats for compose(), scaled by $f.
     *
     * @return array<string, mixed>
     */
    private function stats(string $month, float $f): array
    {
        $n = static fn (int $value): int => (int) floor($value * $f);

        return [
            'month' => $month,
            'new_people' => $n(4),
            'bot' => $n(10),
            'web' => $n(6),
            'signups' => $n(12),
            'signups_web' => $n(8),
            'signups_bot' => $n(4),
            'cancellations' => $n(2),
            'cancellations_web' => $n(0),
            'cancellations_bot' => $n(2),
            'no_shows' => $n(1),
            'completed' => $n(7),
            'free_entries' => $n(6),
            'buyers' => ['2026-10-10' => $n(40), '2026-10-24' => $n(25)],
            'funnel' => ['active' => $n(20), 'signed_up' => $n(8), 'completed' => $n(6)],
        ];
    }

    /** @return array<string, mixed> */
    private static function organizerRow(int $id, string $email, ?string $anonymizedAt = null): array
    {
        return [
            'id' => $id,
            'email' => $email,
            'display_name' => 'Organizer ' . $id,
            'is_organizer' => 1,
            'anonymized_at' => $anonymizedAt,
            'notes' => '',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }

    /**
     * One row as MonthlyReportRepository::adminActions() gives it.
     *
     * @return array<string, mixed>
     */
    private static function action(
        string $at,
        string $actorKind,
        int $actorId,
        string $actorName,
        string $kind,
        string $channel,
        ?string $person = null,
        string $role = '',
        ?string $night = null,
        string $details = ''
    ): array {
        return [
            'created_at' => $at,
            'actor_kind' => $actorKind,
            'actor_person_id' => 'organizer' === $actorKind ? (string) $actorId : null,
            'actor_wp_user_id' => 'wp_user' === $actorKind ? (string) $actorId : null,
            'actor_name' => '' === $actorName ? null : $actorName,
            'channel' => $channel,
            'kind' => $kind,
            'person_id' => null === $person ? null : '12',
            'person_name' => $person,
            'other_person_id' => null,
            'other_name' => null,
            'event_date' => $night,
            'details' => $details,
            'task_role_slug' => $role,
            'task_starts_at' => '' === $role ? null : $night . ' 20:00:00',
        ];
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Helsinki'));
    }
}
