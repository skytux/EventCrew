<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * The self-service credit spend, shared by the bot and the web profile. The
 * rules that decide a credit may be spent (and record it) are what matter here;
 * routing to it is covered in UpdateRouterTest.
 */
final class TicketRedemptionServiceTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function service(): TicketRedemptionService
    {
        return new TicketRedemptionService(
            new PersonRepository(),
            new TaskRepository(),
            new RedemptionRepository(),
            $this->standing(),
            new FreeEntryGate(),
            $this->client()
        );
    }

    /**
     * Queues the reads redeem() makes once it is past the date/closed/redeemed
     * guards: the person's history (for the credit balance) and the day's task
     * (for the event the credit is recorded against). $completed completions earn
     * intdiv($completed, 2) credits.
     */
    private function queueBalanceAndEvent(int $completed): void
    {
        $rows = [];
        for ($i = 0; $i < $completed; $i++) {
            $rows[] = ['id' => $i + 1, 'task_id' => $i + 1, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-01'];
        }

        $this->wpdb->nextResults[] = $rows; // historyFor (balance)
        $this->wpdb->nextVars[] = 0;        // countFor redemptions
        $this->wpdb->nextVars[] = 0;        // sumFor grants
        $this->wpdb->nextResults[] = [      // forDate (eventContext)
            ['id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3, 'event_post_id' => null, 'event_label' => 'Party'],
        ];
    }

    public function testRedeemSpendsACreditAndReturnsATicketLink(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates (eligible)
        $this->wpdb->nextResults[] = [];          // forPerson -> not already redeemed
        $this->queueBalanceAndEvent(2);           // two completions -> one credit

        $result = $this->service()->redeem(7, '2026-08-01');

        self::assertSame(TicketRedemptionService::TICKET_READY, $result['code']);
        self::assertStringContainsString('wp-json/eventcrew/v1/ticket?token=', $result['url']);
        self::assertCount(1, $this->wpdb->inserts); // the redemption was recorded
    }

    public function testRedeemRefusesWithNoCredit(): void
    {
        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates
        $this->wpdb->nextResults[] = [];          // forPerson -> not redeemed
        $this->wpdb->nextResults[] = [];          // historyFor -> no completions -> no credit
        $this->wpdb->nextVars[] = 0;              // countFor
        $this->wpdb->nextVars[] = 0;              // sumFor

        $result = $this->service()->redeem(7, '2026-08-01');

        self::assertSame(TicketRedemptionService::NO_CREDIT, $result['code']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testRedeemRefusesAClosedDate(): void
    {
        $this->options[FreeEntryGate::OPTION] = ['2026-08-01'];
        $this->wpdb->nextCols[] = ['2026-08-01']; // upcomingDates (a real event, but closed)

        $result = $this->service()->redeem(7, '2026-08-01');

        self::assertSame(TicketRedemptionService::ENTRY_CLOSED, $result['code']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testRedeemRefusesADateWithNoEvent(): void
    {
        $this->wpdb->nextCols[] = ['2026-09-09']; // upcomingDates does not include the asked date

        $result = $this->service()->redeem(7, '2026-08-01');

        self::assertSame(TicketRedemptionService::NOT_ELIGIBLE, $result['code']);
        self::assertSame([], $this->wpdb->inserts);
    }
}
