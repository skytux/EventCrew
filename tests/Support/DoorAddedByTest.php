<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Guest;
use EventCrew\Repositories\HistoryRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\DoorAddedBy;
use EventCrew\Support\History;
use EventCrew\Tests\TestCase;

/**
 * The "added by Lou · 9 Nov 14:02" line on the door lists: only for an entry
 * someone else put there, read with one query per list.
 */
final class DoorAddedByTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_userdata')->alias(
            static fn (int $id): object|false => 3 === $id ? (object) ['display_name' => 'Site Admin'] : false
        );
    }

    private function addedBy(): DoorAddedBy
    {
        return new DoorAddedBy(new HistoryRepository(), new PersonRepository());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        return $row + [
            'id' => 1,
            'kind' => History::SIGNED_UP,
            'person_id' => 8,
            'task_id' => 5,
            'actor_kind' => History::ACTOR_PERSON,
            'actor_person_id' => 8,
            'actor_wp_user_id' => null,
            'created_at' => '2026-11-09 14:02:00',
            'actor_name' => '',
            'actor_email' => '',
        ];
    }

    public function testCrewAssignedByAnOrganizerGetTheLineAndSelfSignUpsDoNot(): void
    {
        $this->wpdb->nextResults[] = [
            $this->row(['person_id' => 8, 'task_id' => 5, 'actor_person_id' => 8]),
            $this->row([
                'id' => 2, 'kind' => History::ASSIGNED, 'person_id' => 9, 'task_id' => 5,
                'actor_kind' => History::ACTOR_ORGANIZER, 'actor_person_id' => 7, 'actor_name' => 'Lou',
            ]),
        ];

        $lines = $this->addedBy()->forNight('2026-11-14', [8 => [5], 9 => [5]], []);

        self::assertSame([9 => 'added by Lou · 9 Nov 14:02'], $lines['workers']);
        self::assertSame([], $lines['credits']);
        self::assertCount(1, $this->wpdb->queries);
        self::assertStringContainsString("h.event_date = '2026-11-14'", $this->wpdb->queries[0]);
    }

    public function testOfSeveralTasksTheFirstJoinDecides(): void
    {
        $this->wpdb->nextResults[] = [
            $this->row(['task_id' => 5, 'created_at' => '2026-11-01 10:00:00']),
            $this->row([
                'id' => 2, 'kind' => History::ASSIGNED, 'task_id' => 6, 'created_at' => '2026-11-02 10:00:00',
                'actor_kind' => History::ACTOR_ORGANIZER, 'actor_person_id' => 7, 'actor_name' => 'Lou',
            ]),
        ];

        self::assertSame([], $this->addedBy()->forNight('2026-11-14', [8 => [5, 6]], [])['workers']);
    }

    public function testARejoinReplacesTheEarlierSignUp(): void
    {
        $this->wpdb->nextResults[] = [
            $this->row(['created_at' => '2026-11-01 10:00:00']),
            $this->row([
                'id' => 2, 'kind' => History::ASSIGNED, 'created_at' => '2026-11-03 18:30:00',
                'actor_kind' => History::ACTOR_ORGANIZER, 'actor_person_id' => 7,
                'actor_name' => '', 'actor_email' => 'lou@example.com',
            ]),
        ];

        // No name set: the email's local part, as Person::name() does.
        self::assertSame(
            [8 => 'added by lou · 3 Nov 18:30'],
            $this->addedBy()->forNight('2026-11-14', [8 => [5]], [])['workers']
        );
    }

    public function testACreditSpentInWpAdminNamesTheWordPressUser(): void
    {
        $this->wpdb->nextResults[] = [
            $this->row([
                'kind' => History::CREDIT_REDEEMED, 'person_id' => 9, 'task_id' => null,
                'actor_kind' => History::ACTOR_WP_USER, 'actor_person_id' => null, 'actor_wp_user_id' => 3,
            ]),
            $this->row(['id' => 2, 'kind' => History::CREDIT_REDEEMED, 'person_id' => 10, 'actor_person_id' => 10]),
        ];

        $lines = $this->addedBy()->forNight('2026-11-14', [], [9, 10, 11]);

        self::assertSame([9 => 'added by Site Admin · 9 Nov 14:02'], $lines['credits']);
    }

    public function testUnknownActorsGiveNoLine(): void
    {
        $this->wpdb->nextResults[] = [
            // Erased actor: the id is emptied.
            $this->row(['actor_kind' => History::ACTOR_ORGANIZER, 'actor_person_id' => null]),
            // A WordPress user that is gone.
            $this->row([
                'id' => 2, 'kind' => History::CREDIT_REDEEMED, 'person_id' => 9,
                'actor_kind' => History::ACTOR_WP_USER, 'actor_person_id' => null, 'actor_wp_user_id' => 4,
            ]),
        ];

        self::assertSame(
            ['workers' => [], 'credits' => []],
            $this->addedBy()->forNight('2026-11-14', [8 => [5]], [9])
        );
    }

    public function testAnEmptyNightAsksNothing(): void
    {
        self::assertSame(['workers' => [], 'credits' => []], $this->addedBy()->forNight('2026-11-14', [], []));
        self::assertSame([], $this->wpdb->queries);
    }

    public function testGuestsNameWhoAddedThemInOneQuery(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 7, 'email' => 'lou@example.com', 'display_name' => 'Lou'],
            ['id' => 12, 'email' => 'sam@example.com', 'display_name' => 'Sam'],
        ];

        $lines = $this->addedBy()->forGuests([
            new Guest(1, '2026-11-14', 'Anna', addedBy: 7, createdAt: '2026-11-09 14:02:00'),
            new Guest(2, '2026-11-14', 'Bo', addedBy: 12, createdAt: '2026-11-10 09:15:00'),
            new Guest(3, '2026-11-14', 'Cai', createdAt: '2026-11-10 09:15:00'),
            new Guest(4, '2026-11-14', 'Dee', addedBy: 99, createdAt: '2026-11-10 09:15:00'),
            new Guest(5, '2026-11-14', 'Eli', addedBy: 7),
        ]);

        self::assertSame([1 => 'added by Lou · 9 Nov 14:02', 2 => 'added by Sam · 10 Nov 09:15'], $lines);
        self::assertCount(1, $this->wpdb->queries);
        self::assertStringContainsString('WHERE id IN (7,12,99)', $this->wpdb->queries[0]);
    }

    public function testGuestsWithNoAdderAskNothing(): void
    {
        self::assertSame([], $this->addedBy()->forGuests([new Guest(1, '2026-11-14', 'Anna')]));
        self::assertSame([], $this->wpdb->queries);
    }
}
