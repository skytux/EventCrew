<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\HistoryRepository;
use EventCrew\Repositories\NewsletterHistoryRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\AssistantStatus;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\History;
use EventCrew\Support\HistoryText;
use EventCrew\Support\PersonEraser;
use EventCrew\Tests\TestCase;

/**
 * How history rows read (HistoryText, shared by the History page and the
 * bot), the organizer facts the bot gets from them, and erasure.
 */
final class HistoryFactsTest extends TestCase
{
    private function assistantStatus(?HistoryRepository $actions): AssistantStatus
    {
        return new AssistantStatus(
            new TaskRepository(),
            new PersonRepository(),
            new NewsletterSubscriberRepository(),
            new NewsletterHistoryRepository(),
            new FollowupRepository(),
            new GuestRepository(),
            new RedemptionRepository(),
            new TicketBuyerRepository(),
            new FreeEntryGate(),
            new AssignmentRepository(),
            actions: $actions
        );
    }

    /** @return array<string, mixed> */
    private static function row(array $fields = []): array
    {
        return $fields + [
            'id' => 1,
            'created_at' => '2026-07-19 18:30:00',
            'kind' => History::CANCELLED,
            'person_id' => 7,
            'person_name' => 'Sam',
            'other_person_id' => null,
            'other_name' => null,
            'actor_kind' => History::ACTOR_PERSON,
            'actor_person_id' => 7,
            'actor_name' => 'Sam',
            'actor_wp_user_id' => null,
            'channel' => History::CHANNEL_BOT,
            'task_id' => null,
            'event_date' => '2026-08-08',
            'details' => '',
            'source' => '',
        ];
    }

    public function testActionsReadAsShortSentences(): void
    {
        self::assertSame('Sam cancelled', HistoryText::action(self::row()));
        self::assertSame('Sam cancelled late', HistoryText::action(self::row(['kind' => History::LATE_CANCEL])));
        self::assertSame(
            'Kim took over from Sam',
            HistoryText::action(self::row(['kind' => History::REPLACED, 'other_person_id' => 8, 'other_name' => 'Kim']))
        );
        self::assertSame(
            'Sam was taken off: the task was deleted',
            HistoryText::action(self::row(['kind' => History::TAKEN_OFF, 'details' => '{"task_deleted":1}']))
        );
        self::assertSame(
            'Task changed: capacity, notes',
            HistoryText::action(self::row([
                'kind' => History::TASK_CHANGED,
                'details' => '{"fields":["capacity","notes"]}',
            ]))
        );
    }

    public function testAnErasedPersonIsSomeoneErased(): void
    {
        self::assertSame(
            'someone (erased) cancelled',
            HistoryText::action(self::row(['person_id' => null, 'person_name' => null]))
        );
    }

    public function testTheActorSaysWhoAndWhere(): void
    {
        self::assertSame('Sam, bot', HistoryText::actor(self::row()));
        self::assertSame(
            'automatic',
            HistoryText::actor(self::row(['actor_kind' => History::ACTOR_SYSTEM, 'actor_person_id' => null]))
        );
        self::assertSame(
            '',
            HistoryText::actor(self::row(['actor_kind' => '', 'actor_person_id' => null, 'source' => 'backfill']))
        );
    }

    public function testRecentHistoryIsSevenDaysAndSaysWhenItIsCapped(): void
    {
        $rows = array_fill(0, AssistantStatus::HISTORY_LIMIT + 1, self::row());
        $this->wpdb->nextResults[] = $rows;

        $lines = $this->assistantStatus(new HistoryRepository())->historyLines('2026-07-20 12:00:00');

        self::assertStringContainsString("h.created_at >= '2026-07-13 12:00:00'", $this->wpdb->lastQuery());
        self::assertStringContainsString('LIMIT 31 OFFSET 0', $this->wpdb->lastQuery());
        self::assertSame('Recent history (the last 7 days, newest first; who did what, and when):', $lines[0]);
        self::assertCount(1 + AssistantStatus::HISTORY_LIMIT + 1, $lines);
        self::assertStringStartsWith('- Only the latest 30 are listed; there are more.', $lines[31]);
        self::assertStringContainsString(': Sam cancelled (night ', $lines[1]);
        self::assertStringContainsString('by Sam, bot)', $lines[1]);
    }

    public function testRecentHistorySaysWhenNothingIsRecorded(): void
    {
        $lines = $this->assistantStatus(new HistoryRepository())->historyLines('2026-07-20 12:00:00');

        self::assertSame('- Nothing recorded.', $lines[1]);
        self::assertSame([], $this->assistantStatus(null)->historyLines('2026-07-20 12:00:00'));
    }

    public function testAQuestionNamingAPersonAndANightGetsTheirHistory(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 7, 'display_name' => 'Sam Example'],
            ['id' => 8, 'display_name' => 'Al'],
        ];
        $this->wpdb->nextResults[] = [self::row()];
        $this->wpdb->nextResults[] = [];

        $text = $this->assistantStatus(new HistoryRepository())->askedHistoryText(
            'What did sam do, and what happened on 8.8.? Al?',
            '2026-07-20'
        );

        self::assertStringContainsString('History of Sam Example (newest first; kept 365 days):', $text);
        self::assertStringContainsString('Sam cancelled', $text);
        self::assertStringContainsString('History of the night ', $text);
        self::assertStringContainsString('- Nothing recorded.', $text);
        self::assertStringNotContainsString('History of Al', $text, 'a name under three letters is not matched');
        self::assertStringContainsString("h.event_date = '2026-08-08'", $this->wpdb->lastQuery());
        self::assertStringNotContainsString('History page', $text);
    }

    public function testAQuestionNamingNobodyGetsNothing(): void
    {
        $this->wpdb->nextResults[] = [['id' => 7, 'display_name' => 'Sam']];

        $status = $this->assistantStatus(new HistoryRepository());

        self::assertSame('', $status->askedHistoryText('who cancelled?', '2026-07-20'));
        self::assertSame('', $this->assistantStatus(null)->askedHistoryText('what did Sam do?', '2026-07-20'));
    }

    public function testErasureEmptiesThePersonsIdsInTheHistory(): void
    {
        $sink = [];
        History::useSink(static function (array $entry) use (&$sink): void {
            $sink[] = $entry;
        });
        Functions\when('do_action')->justReturn(null);

        (new PersonEraser(
            new PersonRepository(),
            new AssignmentRepository(),
            new RedemptionRepository(),
            new CreditGrantRepository(),
            new AuthTokenRepository(),
            new NotificationsRepository(),
            history: new HistoryRepository()
        ))->erase(7);

        $updates = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_starts_with($sql, 'UPDATE wp_eventcrew_history')
        ));

        self::assertCount(3, $updates);
        self::assertSame(History::PERSON_ERASED, $sink[array_key_last($sink)]['kind']);
        self::assertSame(7, $sink[array_key_last($sink)]['erased_person_id']);
    }
}
