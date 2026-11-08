<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\HistoryRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\History;
use EventCrew\Support\HistoryRecorder;
use EventCrew\Tests\TestCase;

/**
 * The recorder: what one recorded action becomes as rows of the history
 * table, who it names as the actor, and that it never stores values.
 */
final class HistoryRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string|false => json_encode($value));
    }

    private function recorder(): HistoryRecorder
    {
        return new HistoryRecorder(new HistoryRepository());
    }

    /** Queues the assignment lookup: person 7 on task 3, the night 2026-08-08. */
    private function queueAssignment(int $personId = 7): void
    {
        $this->wpdb->nextRows[] = ['person_id' => $personId, 'task_id' => 3, 'task_date' => '2026-08-08'];
    }

    public function testOwnSignUpFromTheBotNamesThePersonFoundByTelegramId(): void
    {
        History::actAs(History::CHANNEL_BOT, null, 555);
        $this->queueAssignment();
        $this->wpdb->nextVars[] = '7';

        $rows = $this->recorder()->rows(['kind' => History::SIGNED_UP, 'assignment_id' => 41]);

        self::assertCount(1, $rows);
        self::assertSame(History::SIGNED_UP, $rows[0]['kind']);
        self::assertSame(7, $rows[0]['person_id']);
        self::assertSame(3, $rows[0]['task_id']);
        self::assertSame('2026-08-08', $rows[0]['event_date']);
        self::assertSame(History::ACTOR_PERSON, $rows[0]['actor_kind']);
        self::assertSame(7, $rows[0]['actor_person_id']);
        self::assertSame(History::CHANNEL_BOT, $rows[0]['channel']);
        self::assertSame('', $rows[0]['details']);
        self::assertSame('', $rows[0]['source']);
        self::assertSame('2026-07-20 12:00:00', $rows[0]['created_at']);
    }

    public function testSignUpByAnOrganizerIsAssigned(): void
    {
        History::actAs(History::CHANNEL_WEB, 9);
        $this->queueAssignment();
        $this->wpdb->nextRows[] = ['is_organizer' => 1, 'role_slugs' => ''];

        $rows = $this->recorder()->rows(['kind' => History::SIGNED_UP, 'assignment_id' => 41]);

        self::assertSame(History::ASSIGNED, $rows[0]['kind']);
        self::assertSame(History::ACTOR_ORGANIZER, $rows[0]['actor_kind']);
        self::assertSame(9, $rows[0]['actor_person_id']);
    }

    public function testUnknownAssignmentRecordsNothing(): void
    {
        self::assertSame([], $this->recorder()->rows(['kind' => History::SIGNED_UP, 'assignment_id' => 41]));
    }

    public function testOwnCancellationAndATakeOffByAWordPressUser(): void
    {
        $this->queueAssignment();
        $own = $this->recorder()->rows([
            'kind' => History::STATUS,
            'assignment_id' => 41,
            'status' => AssignmentStatus::CANCELLED,
            'actor_person_id' => 7,
        ]);

        $this->queueAssignment();
        $admin = $this->recorder()->rows([
            'kind' => History::STATUS,
            'assignment_id' => 41,
            'status' => AssignmentStatus::CANCELLED,
            'actor_wp_user_id' => 2,
        ]);

        self::assertSame(History::CANCELLED, $own[0]['kind']);
        self::assertSame(History::TAKEN_OFF, $admin[0]['kind']);
        self::assertSame(History::ACTOR_WP_USER, $admin[0]['actor_kind']);
        self::assertSame(2, $admin[0]['actor_wp_user_id']);
        self::assertSame(History::CHANNEL_ADMIN, $admin[0]['channel']);
    }

    public function testStatusMapping(): void
    {
        $expected = [
            AssignmentStatus::LATE_CANCEL => History::LATE_CANCEL,
            AssignmentStatus::NO_SHOW => History::NO_SHOW,
            AssignmentStatus::REPLACED => History::REPLACED,
        ];

        foreach ($expected as $status => $kind) {
            $this->queueAssignment();
            $rows = $this->recorder()->rows([
                'kind' => History::STATUS,
                'assignment_id' => 41,
                'status' => $status,
                'actor_person_id' => 7,
            ]);

            self::assertSame($kind, $rows[0]['kind'], $status);
            self::assertSame('', $rows[0]['details']);
        }
    }

    public function testAnyOtherStatusIsMarkedWithTheStatusOnly(): void
    {
        $this->queueAssignment();

        $rows = $this->recorder()->rows([
            'kind' => History::STATUS,
            'assignment_id' => 41,
            'status' => AssignmentStatus::ARRIVED,
            'actor_person_id' => 7,
        ]);

        self::assertSame(History::MARKED, $rows[0]['kind']);
        self::assertSame('{"status":"' . AssignmentStatus::ARRIVED . '"}', $rows[0]['details']);
    }

    public function testWithNoContextAndNoUserTheSystemActed(): void
    {
        Functions\when('get_current_user_id')->justReturn(0);
        $this->queueAssignment();

        $rows = $this->recorder()->rows([
            'kind' => History::STATUS,
            'assignment_id' => 41,
            'status' => AssignmentStatus::NO_SHOW,
        ]);

        self::assertSame(History::ACTOR_SYSTEM, $rows[0]['actor_kind']);
        self::assertNull($rows[0]['actor_person_id']);
        self::assertNull($rows[0]['actor_wp_user_id']);
        self::assertSame(History::CHANNEL_SYSTEM, $rows[0]['channel']);
    }

    public function testWithNoContextTheSignedInWordPressUserActed(): void
    {
        $rows = $this->recorder()->rows([
            'kind' => History::TASK_DELETED,
            'task_id' => 3,
            'event_date' => '2026-08-08',
        ]);

        self::assertSame(History::TASK_DELETED, $rows[0]['kind']);
        self::assertSame(History::ACTOR_WP_USER, $rows[0]['actor_kind']);
        self::assertSame(1, $rows[0]['actor_wp_user_id']);
    }

    public function testTaskChangeKeepsTheNamesOfChangedFieldsOnly(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'task_date' => '2026-08-08',
            'starts_at' => '20:00:00',
            'notes' => 'old private note',
            'capacity' => 2,
        ];

        $rows = $this->recorder()->rows([
            'kind' => History::TASK_CHANGED,
            'task_id' => 3,
            'fields' => ['starts_at' => '20:00:00', 'notes' => 'new private note', 'capacity' => 3, 'Bad Key' => 1],
        ]);

        self::assertSame(History::TASK_CHANGED, $rows[0]['kind']);
        self::assertSame('2026-08-08', $rows[0]['event_date']);
        self::assertSame('{"fields":["notes","capacity"]}', $rows[0]['details']);
        self::assertStringNotContainsString('private', $rows[0]['details']);
    }

    public function testTaskChangeThatChangesNothingRecordsNothing(): void
    {
        $this->wpdb->nextRows[] = ['id' => 3, 'task_date' => '2026-08-08', 'capacity' => 2];

        self::assertSame([], $this->recorder()->rows([
            'kind' => History::TASK_CHANGED,
            'task_id' => 3,
            'fields' => ['capacity' => '2'],
        ]));
    }

    public function testPersonChangeMakesOneRowForTheFlagAndOneForTheRoles(): void
    {
        $this->wpdb->nextRows[] = ['is_organizer' => 0, 'role_slugs' => 'bar,door'];

        $rows = $this->recorder()->rows([
            'kind' => History::PERSON_CHANGED,
            'person_id' => 7,
            'actor_wp_user_id' => 2,
            'is_organizer' => 1,
            'role_slugs' => 'door,kitchen',
        ]);

        self::assertCount(2, $rows);
        self::assertSame(History::ORGANIZER_CHANGED, $rows[0]['kind']);
        self::assertSame('{"organizer":1}', $rows[0]['details']);
        self::assertSame(History::ROLES_CHANGED, $rows[1]['kind']);
        self::assertSame('{"added":["kitchen"],"removed":["bar"]}', $rows[1]['details']);
    }

    public function testPersonChangeWithoutARealChangeRecordsNothing(): void
    {
        $this->wpdb->nextRows[] = ['is_organizer' => 1, 'role_slugs' => 'bar'];

        self::assertSame([], $this->recorder()->rows([
            'kind' => History::PERSON_CHANGED,
            'person_id' => 7,
            'is_organizer' => true,
            'role_slugs' => 'bar',
        ]));
    }

    public function testTicketTransferResolvesGiverReceiverAndNight(): void
    {
        $this->wpdb->nextRows[] = ['giver_person_id' => 7, 'receiver_person_id' => 8, 'event_date' => '2026-08-08'];

        $rows = $this->recorder()->rows(['kind' => History::TICKET_GIVEN, 'transfer_id' => 5, 'actor_person_id' => 7]);

        self::assertSame(7, $rows[0]['person_id']);
        self::assertSame(8, $rows[0]['other_person_id']);
        self::assertSame('2026-08-08', $rows[0]['event_date']);
        self::assertSame(History::ACTOR_PERSON, $rows[0]['actor_kind']);
    }

    public function testRedemptionResolvesPersonAndNight(): void
    {
        $this->wpdb->nextRows[] = ['person_id' => 7, 'redeemed_for' => '2026-08-08'];

        $rows = $this->recorder()->rows([
            'kind' => History::REDEMPTION_CANCELLED,
            'redemption_id' => 4,
            'actor_person_id' => 7,
        ]);

        self::assertSame(7, $rows[0]['person_id']);
        self::assertSame('2026-08-08', $rows[0]['event_date']);
    }

    public function testAPersonWhoErasedThemselvesIsNotNamedAsTheActor(): void
    {
        $rows = $this->recorder()->rows([
            'kind' => History::PERSON_ERASED,
            'erased_person_id' => 7,
            'actor_person_id' => 7,
        ]);

        self::assertSame(History::PERSON_ERASED, $rows[0]['kind']);
        self::assertNull($rows[0]['person_id']);
        self::assertNull($rows[0]['actor_person_id']);
        self::assertSame(History::ACTOR_PERSON, $rows[0]['actor_kind']);
    }

    public function testMutedAndSinklessRecordingWriteNothing(): void
    {
        History::record(History::TASK_CREATED, ['task_id' => 3]);

        $recorder = $this->recorder();
        History::useSink([$recorder, 'handle']);
        History::muted(static function (): void {
            History::record(History::TASK_CREATED, ['task_id' => 3, 'event_date' => '2026-08-08']);
        });

        self::assertSame([], $this->wpdb->inserts);

        History::record(History::TASK_CREATED, ['task_id' => 3, 'event_date' => '2026-08-08']);

        self::assertCount(1, $this->wpdb->inserts);
        self::assertSame('wp_eventcrew_history', $this->wpdb->inserts[0]['table']);
        self::assertSame(History::TASK_CREATED, $this->wpdb->inserts[0]['data']['kind']);
    }

    public function testDetailsDropListItemsUntilTheyFit(): void
    {
        $names = [];

        for ($i = 0; $i < 30; $i++) {
            $names[] = 'option_name_' . $i;
        }

        $json = HistoryRecorder::details(['options' => $names]);
        $decoded = json_decode($json, true);

        self::assertLessThanOrEqual(191, strlen($json));
        self::assertIsArray($decoded);
        self::assertSame(30, count($decoded['options']) + $decoded['more']);
        self::assertSame('', HistoryRecorder::details([]));
    }

    public function testOnlyOrganizerSettingsCountAsSettings(): void
    {
        self::assertTrue(HistoryRecorder::isSetting('eventcrew_telegram_bot_token'));
        self::assertTrue(HistoryRecorder::isSetting('eventcrew_reward_threshold'));
        self::assertFalse(HistoryRecorder::isSetting('blogname'));
        self::assertFalse(HistoryRecorder::isSetting('eventcrew_'));
        self::assertFalse(HistoryRecorder::isSetting(History::PURGED_OPTION));
    }

    public function testSettingsRowNamesTheOptionsNeverTheirValues(): void
    {
        $recorder = $this->recorder();
        $recorder->optionUpdated('eventcrew_telegram_bot_token', 'old-secret', 'new-secret');
        $recorder->optionUpdated('eventcrew_reward_threshold', 3, 3);
        $recorder->optionAdded('eventcrew_llm_api_key', 'sk-secret');
        $recorder->optionUpdated('blogname', 'a', 'b');
        $recorder->recordSettings();

        self::assertCount(1, $this->wpdb->inserts);
        $data = $this->wpdb->inserts[0]['data'];
        self::assertSame(History::SETTINGS_CHANGED, $data['kind']);
        self::assertSame('{"options":["telegram_bot_token","llm_api_key"]}', $data['details']);
        self::assertStringNotContainsString('secret', $data['details']);

        $recorder->recordSettings();

        self::assertCount(1, $this->wpdb->inserts);
    }
}
