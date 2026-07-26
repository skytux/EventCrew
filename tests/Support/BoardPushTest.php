<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\BoardPush;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * The automated board re-post. Scheduling and the send-once ledger are the
 * logic worth pinning; the re-post itself is BoardService's job and tested
 * there, so these run with the bot unconfigured, where run() still resolves the
 * due dates and records them but the post is inert.
 */
final class BoardPushTest extends TelegramTestCase
{
    private function push(): BoardPush
    {
        $tasks = new TaskRepository();
        $assignments = new AssignmentRepository();
        $telegram = $this->client();
        $board = new BoardService(
            $tasks,
            $assignments,
            new PersonRepository(),
            $telegram,
            new Logger(),
            new ClaimNotifier($tasks, $assignments, new Mailer(new Logger(), new EmailTemplate(new Logger())), $telegram, $this->standing()),
            new SignupService($assignments, new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository()), new PersonRepository(), new TaskRepository())
        );

        return new BoardPush($tasks, new NotificationsRepository(), $board);
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(): array
    {
        return [
            'id' => 5,
            'task_date' => '2026-07-21',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_label' => 'Party',
        ];
    }

    public function testADueOpenDateIsRecordedForBothLeads(): void
    {
        // Bot unconfigured, so the re-post is inert but the scheduling still runs.
        $this->options[TelegramClient::TOKEN_OPTION] = '';
        // A date ~36h out sits inside both the week and the 48h window.
        $this->wpdb->nextCols[] = ['2026-07-21']; // upcomingDates (week pass)
        $this->wpdb->nextResults[] = [$this->taskRow()]; // forDate
        $this->wpdb->nextResults[] = []; // occupancyFor -> nobody, so open
        $this->wpdb->nextCols[] = ['2026-07-21']; // upcomingDates (soon pass)
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = [];

        $this->push()->run();

        $kinds = array_map(static fn (array $i): string => $i['data']['kind'], $this->wpdb->inserts);
        self::assertContains('board_push_168', $kinds);
        self::assertContains('board_push_48', $kinds);
        // Inert bot: nothing was actually posted.
        self::assertNotContains('sendMessage', $this->calledMethods());
    }

    public function testDisabledPushDoesNothing(): void
    {
        $this->options[BoardPush::ENABLED_OPTION] = '0';

        $this->push()->run();

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->telegramCalls);
    }
}
