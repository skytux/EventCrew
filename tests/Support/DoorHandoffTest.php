<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\DoorDesk;
use EventCrew\Support\DoorHandoff;
use EventCrew\Support\DoorSession;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * The sales desk's "Other device" links: who "Login as" offers, who it
 * refuses, and that a link made on the page works once and for 15 minutes.
 * The clock reads 2026-07-20 12:00.
 */
final class DoorHandoffTest extends SalesDeskTestCase
{
    private function desk(): DoorDesk
    {
        return new DoorDesk(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new RedemptionRepository(),
            new GuestRepository(),
            new TicketBuyerRepository(),
            new CheckinRepository(),
            new AuthTokenRepository(),
            $this->standing(),
            new TicketRedemptionService(
                new PersonRepository(),
                new TaskRepository(),
                new RedemptionRepository(),
                $this->standing(),
                new FreeEntryGate(),
                $this->client(),
                new Mailer(new Logger(), new EmailTemplate(new Logger()))
            ),
            $this->salesDeskGate(),
            new CreditGrantRepository()
        );
    }

    private function handoff(): DoorHandoff
    {
        return new DoorHandoff(
            $this->desk(),
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            new AuthTokenRepository(),
            $this->salesDeskGate()
        );
    }

    /**
     * Queues the reads of the allowed list for tonight: organizers 1 and 2,
     * a Welcome task with person 8 marked arrived and person 9 only signed
     * up, then SalesDeskGate's check of person 8.
     */
    private function queueAllowedList(): void
    {
        $welcome = $this->taskRow(5, 'welcome', '11:00', '23:00');

        $this->wpdb->nextResults[] = [$this->personRow(1, true), $this->personRow(2, true)]; // organizers
        $this->wpdb->nextResults[] = [$welcome];                                              // tasks->forDate
        $this->wpdb->nextResults[] = [
            $this->assignmentRow(10, 5, 8, 'arrived'),
            $this->assignmentRow(11, 5, 9, 'signed_up'),
        ];                                                                                    // forTask(5)
        $this->wpdb->nextRows[] = $this->personRow(8);                                        // find(8)
        $this->wpdb->nextResults[] = [$welcome];                                              // check(8): forDate
        $this->wpdb->nextRows[] = $this->assignmentRow(10, 5, 8, 'arrived');                 // check(8): findFor
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenRow(int $personId, string $expires = '2026-07-20 12:15:00', ?string $used = null): array
    {
        return ['id' => 4, 'person_id' => $personId, 'expires_at' => $expires, 'used_at' => $used];
    }

    /**
     * The conditional UPDATEs that stamp a token used.
     *
     * @return array<int, string>
     */
    private function updatesOfTokens(): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $q): bool => str_contains($q, 'UPDATE') && str_contains($q, 'used_at IS NULL')
        ));
    }

    /**
     * @return array{cookie: ?string, seconds: int, message: string}
     */
    private function spend(string $raw): array
    {
        return $this->desk()->login($raw, self::NIGHT, DoorSession::linkSignature($raw, self::NIGHT));
    }

    public function testLoginAsListsOrganizersAndArrivedDoorCrewButNotTheSessionHolder(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(1, true); // the session
        $this->queueAllowedList();

        $people = $this->handoff()->people(DoorSession::mint(1, self::NIGHT));

        self::assertSame([['id' => 2, 'name' => 'Person 2'], ['id' => 8, 'name' => 'Person 8']], $people);
    }

    public function testALinkForTheSessionHolderLastsFifteenMinutes(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(1, true);

        $link = $this->handoff()->link(DoorSession::mint(1, self::NIGHT));

        self::assertSame(DoorHandoff::OK, $link['code']);
        self::assertStringContainsString('action=eventcrew_door', $link['url']);
        self::assertCount(1, $this->wpdb->inserts);
        self::assertSame(1, $this->wpdb->inserts[0]['data']['person_id']);
        self::assertSame(DoorSession::HANDOFF_PURPOSE, $this->wpdb->inserts[0]['data']['purpose']);
        self::assertSame('2026-07-20 12:15:00', $this->wpdb->inserts[0]['data']['expires_at']);
    }

    public function testALoginAsLinkIsMadeForAnArrivedWelcomeCrewMember(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(1, true);
        $this->queueAllowedList();

        $link = $this->handoff()->link(DoorSession::mint(1, self::NIGHT), 8);

        self::assertSame(DoorHandoff::OK, $link['code']);
        self::assertSame('Person 8', $link['name']);
        self::assertSame(8, $this->wpdb->inserts[0]['data']['person_id']);
    }

    public function testLoginAsIsRefusedForAPersonNotAllowedTonight(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(1, true);
        $this->queueAllowedList();

        // Person 9 is on the Welcome task but not marked arrived.
        $link = $this->handoff()->link(DoorSession::mint(1, self::NIGHT), 9);

        self::assertSame(DoorHandoff::NOT_ALLOWED, $link['code']);
        self::assertSame('', $link['url']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testNoLinkAndNoListWithoutAValidDeskSession(): void
    {
        $cookie = DoorSession::mint(1, self::NIGHT);
        $forged = substr($cookie, 0, -1) . ('0' === substr($cookie, -1) ? '1' : '0');

        self::assertSame(DoorHandoff::NO_SESSION, $this->handoff()->link('')['code']);
        self::assertSame(DoorHandoff::NO_SESSION, $this->handoff()->link($forged, 2)['code']);
        // Yesterday's session ended at 06:00 this morning.
        self::assertSame(DoorHandoff::NO_SESSION, $this->handoff()->link(DoorSession::mint(1, '2026-07-19'))['code']);
        self::assertNull($this->handoff()->people($forged));
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testNoLinkFromASessionWhoseNightHasClosed(): void
    {
        // A Welcome crew session after the event end (Clean starts at 11:30).
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'welcome', '10:00', '11:00'), $this->taskRow(7, 'clean', '11:30')];

        self::assertSame(DoorHandoff::NO_SESSION, $this->handoff()->link(DoorSession::mint(8, self::NIGHT))['code']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testALinkMadeOnThePageWorksOnce(): void
    {
        // First use: not an ordinary door token, found as a page-made one,
        // and stamped used by the conditional UPDATE.
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->tokenRow(2);
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = $this->personRow(2, true);

        $first = $this->spend('raw');

        self::assertNotNull($first['cookie']);
        self::assertSame(['person_id' => 2, 'date' => self::NIGHT], DoorSession::read($first['cookie']));
        self::assertCount(1, $this->updatesOfTokens());

        // Second use: the row now carries used_at.
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->tokenRow(2, '2026-07-20 12:15:00', '2026-07-20 12:00:00');

        $second = $this->spend('raw');

        self::assertNull($second['cookie']);
        self::assertStringContainsString('already used', $second['message']);
    }

    public function testTwoDevicesSpendingTheSameLinkAtOnceGetOneSessionBetweenThem(): void
    {
        // Both read the row unused; the second one's UPDATE changes nothing.
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->tokenRow(2);
        $this->wpdb->nextQueryResults[] = 0;

        self::assertNull($this->spend('raw')['cookie']);
    }

    public function testALinkMadeOnThePageStopsWorkingAfterFifteenMinutes(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(1, true);
        $link = $this->handoff()->link(DoorSession::mint(1, self::NIGHT));
        $expires = (string) $this->wpdb->inserts[0]['data']['expires_at'];
        parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $query);

        // One second past the 15 minutes.
        Functions\when('current_time')->alias(
            static function (string $format): string|int {
                $at = (int) strtotime('2026-07-20 12:15:01');

                if ('mysql' === $format) {
                    return '2026-07-20 12:15:01';
                }

                return 'timestamp' === $format ? $at : gmdate($format, $at);
            }
        );

        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->tokenRow(1, $expires);
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = $this->personRow(1, true);

        $login = $this->desk()->login((string) $query['t'], (string) $query['d'], (string) $query['s']);

        self::assertNull($login['cookie']);
        self::assertSame([], $this->updatesOfTokens());
    }

    public function testALoginAsLinkIsRefusedWhenThePersonIsNoLongerAllowedAtUse(): void
    {
        // Person 8 was unmarked arrived after the link was made.
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextRows[] = $this->tokenRow(8);
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = $this->personRow(8);
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'welcome', '11:00', '23:00')];
        $this->wpdb->nextRows[] = $this->assignmentRow(10, 5, 8, 'signed_up');

        $login = $this->spend('raw');

        self::assertNull($login['cookie']);
    }
}
