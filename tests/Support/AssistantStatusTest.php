<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\NewsletterHistoryRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\AssistantKnowledge;
use EventCrew\Support\AssistantStatus;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Newsletter;
use EventCrew\Tests\TestCase;
use FakeWpdb;

/**
 * The live "Current status" and "This person" facts of the bot's helper
 * (Owner 2026-11-08: the helper said it had no access to whether the
 * announcement had gone out).
 */
final class AssistantStatusTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<array{0: list<string>, 1: mixed}> SQL fragments => answer, for get_results/get_col. */
    private array $results = [];

    /** @var list<array{0: list<string>, 1: mixed}> SQL fragments => answer, for get_var. */
    private array $vars = [];

    /** @var list<array{0: list<string>, 1: mixed}> SQL fragments => answer, for get_row. */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->wpdb = new class ($test) extends FakeWpdb {
            public function __construct(private readonly AssistantStatusTest $test)
            {
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- wpdb's own method name.
            public function get_results(string $sql, string $output = 'OBJECT'): mixed
            {
                $this->queries[] = $sql;

                return $this->test->answer($sql, 'results') ?? [];
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- wpdb's own method name.
            public function get_row(string $sql, string $output = 'OBJECT'): mixed
            {
                $this->queries[] = $sql;

                return $this->test->answer($sql, 'row');
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- wpdb's own method name.
            public function get_col(string $sql): mixed
            {
                $this->queries[] = $sql;

                return $this->test->answer($sql, 'results') ?? [];
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- wpdb's own method name.
            public function get_var(string $sql): mixed
            {
                $this->queries[] = $sql;

                return $this->test->answer($sql, 'var');
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->options = [
            Newsletter::ENABLED_OPTION => '1',
            FollowupMail::ENABLED_OPTION => '1',
        ];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('post_type_exists')->justReturn(false);
        Functions\when('get_transient')->justReturn('');
    }

    /** The answer of the first rule whose fragments are all in $sql. */
    public function answer(string $sql, string $kind): mixed
    {
        $rules = match ($kind) {
            'var' => $this->vars,
            'row' => $this->rows,
            default => $this->results,
        };

        foreach ($rules as [$needles, $answer]) {
            $hit = true;

            foreach ($needles as $needle) {
                $hit = $hit && str_contains($sql, $needle);
            }

            if ($hit) {
                return $answer;
            }
        }

        return null;
    }

    private function service(): AssistantStatus
    {
        $tasks = new TaskRepository();

        return new AssistantStatus(
            $tasks,
            new PersonRepository(),
            new NewsletterSubscriberRepository(),
            new NewsletterHistoryRepository(),
            new FollowupRepository(),
            new GuestRepository(),
            new RedemptionRepository(),
            new TicketBuyerRepository(),
            new FreeEntryGate(),
            new AssignmentRepository()
        );
    }

    /** @param array<string, mixed> $state */
    private function newsletter(array $state): string
    {
        $this->options[Newsletter::STATE_OPTION] = $state;
        $this->vars[] = [['newsletter_subscribers', 'COUNT(*)'], '42'];

        return implode("\n", $this->service()->newsletterLines(
            AssistantStatus::newsletterState(),
            '2026-07-20',
            '2026-07-20 12:00:00'
        ));
    }

    public function testNoPlanAndNoComingEventSaysSo(): void
    {
        $text = $this->newsletter([]);

        self::assertStringContainsString('switched on, sent 7 days before each event', $text);
        self::assertStringContainsString('Confirmed subscribers: 42.', $text);
        self::assertStringContainsString('- None has been sent yet.', $text);
        self::assertStringContainsString('No announcement is planned, and no coming event is waiting', $text);
    }

    public function testAPlannedAnnouncementGivesItsEventsPreviewAndSendTime(): void
    {
        $text = $this->newsletter(['plan' => [
            'id' => 3,
            'status' => Newsletter::PLAN_PLANNED,
            'send_on' => '2026-07-25',
            'send_at' => '2026-07-25 18:00:00',
            'preview_at' => '2026-07-19 02:00:00',
            'previewed' => true,
            'events' => [['id' => 12, 'date' => '2026-08-01', 'title' => 'Summer Dance']],
            'sent' => 0,
            'failed' => 0,
        ]]);

        self::assertStringContainsString(
            '- Planned, not sent yet: Summer Dance 2026-08-01 (Saturday). Preview to the organizers'
                . ' 2026-07-19 (Sunday) 02:00: sent. Event texts written: not yet. Sends 2026-07-25 (Saturday) 18:00.'
                . ' /stop announcement stops it.',
            $text
        );
    }

    public function testAnAnnouncementBeingSentGivesItsProgress(): void
    {
        $text = $this->newsletter(['plan' => [
            'status' => Newsletter::PLAN_SENDING,
            'send_at' => '2026-07-20 10:00:00',
            'events' => [['id' => 12, 'date' => '2026-08-01', 'title' => 'Summer Dance']],
            'sent' => 20,
            'failed' => 1,
        ]]);

        self::assertStringContainsString(
            '- Being sent now (about 2026-07-20 (Monday) 10:00): Summer Dance 2026-08-01 (Saturday).'
                . ' So far 20 sent, 1 failed.',
            $text
        );
    }

    public function testTheLastAnnouncementSentGivesWhenWhatAndHowMany(): void
    {
        $this->results[] = [['newsletter_sends'], [
            ['id' => 1, 'plan_id' => 1, 'lang' => '', 'sent_at' => '2026-07-01 18:00:00',
                'subject' => 'Old', 'sent' => 3, 'failed' => 0],
            ['id' => 2, 'plan_id' => 2, 'lang' => '', 'sent_at' => '2026-07-13 18:00:00',
                'subject' => 'Summer Dance on Saturday', 'sent' => 40, 'failed' => 2],
        ]];

        $text = $this->newsletter([
            'last' => ['at' => '2026-07-13 18:05:00', 'sent' => 40, 'failed' => 2, 'events' => 1],
            'ledger' => [12 => ['s' => Newsletter::LEDGER_SENT, 'd' => '2026-07-21']],
        ]);

        self::assertStringContainsString(
            '- Last one sent: 2026-07-13 (Monday) 18:05, 1 event(s), subject "Summer Dance on Saturday",'
                . ' 40 emails sent, 2 failed.',
            $text
        );
        self::assertStringContainsString(
            '- Coming events and their announcement: event 12 2026-07-21 (Tuesday): sent.',
            $text
        );
    }

    /** @return array<string, mixed> */
    private static function taskRow(int $id, string $role, int $capacity): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-08-01',
            'role_slug' => $role,
            'capacity' => $capacity,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => '2026-08-01 20:00:00',
            'ends_at' => '2026-08-01 23:00:00',
        ];
    }

    /** Queues one night: two tasks, an organizer, guests, credits, buyers. */
    private function night(): void
    {
        $this->results = [
            [['eventcrew_tasks', "task_date = '2026-08-01'"], [
                self::taskRow(1, 'organize', 1),
                self::taskRow(2, 'bar', 3),
            ]],
            [['COUNT(*) AS taken'], [['task_id' => 1, 'taken' => 1], ['task_id' => 2, 'taken' => 2]]],
            [
                ['p.display_name'],
                [['task_id' => 1, 'display_name' => 'Anna'], ['task_id' => 2, 'display_name' => 'Ben']],
            ],
            [
                ['ticket_mail_status', 'eventcrew_ticket_buyers'],
                [['status' => 'sent', 'n' => 10], ['status' => '', 'n' => 2]],
            ],
            [['ticket_mail_status', 'eventcrew_guests'], [['status' => 'sent', 'n' => 3]]],
            [['eventcrew_guests', 'event_date'], [
                ['id' => 1, 'event_date' => '2026-08-01', 'name' => 'Guest One', 'plus_ones' => 1,
                    'email' => 'guest@example.test'],
                ['id' => 2, 'event_date' => '2026-08-01', 'name' => 'Guest Two', 'plus_ones' => 0],
            ]],
            [['eventcrew_redemptions', 'redeemed_for'], [
                ['id' => 5, 'person_id' => 9, 'redeemed_for' => '2026-08-01'],
            ]],
        ];
        $this->vars = [
            [['eventcrew_ticket_buyers', 'source NOT IN'], '15'],
            [['eventcrew_ticket_buyers', 'COUNT(*)'], '17'],
        ];
    }

    public function testANightSumsUpTasksOrganizeDoorAndSwitches(): void
    {
        $this->night();
        $this->options[FreeEntryGate::OPTION] = ['2026-08-01'];
        $this->options[FollowupMail::STATE_OPTION] = ['stopped' => ['2026-08-01' => 1]];

        $text = implode("\n", $this->service()->nightLines('2026-08-01', [
            'ledger' => [12 => ['s' => Newsletter::LEDGER_STOPPED, 'd' => '2026-08-01']],
            'plan' => null,
            'last' => null,
        ]));

        self::assertSame(
            "Night 2026-08-01 (Saturday), Party:\n"
                . "- Tasks (signed up/places): Organize 20:00–23:00 1/no limit; bar 20:00–23:00 2/3.\n"
                . "- On Organize: Anna.\n"
                . "- Free-entry tickets given for credits: 1. Guest list: 2 entries (3 people with plus-ones).\n"
                . '- Ticket holders: 17 (15 imported from the ticket shop, 2 sold at the door or desk).'
                . " Ticket emails to buyers: 10 sent, 0 failed, 2 not sent; to guests: 3 sent, 0 failed, 0 not sent.\n"
                . '- Free entry: closed by an organizer (/freeentry open reopens it). Announcement: stopped.'
                . ' Post-event email: stopped.',
            $text
        );
    }

    /** @return string The facts build() gives. */
    private function facts(bool $organizer, int $personId = 0): string
    {
        return (new AssistantKnowledge(new TaskRepository(), $this->service()))
            ->build([], $organizer, false, $personId)['facts'];
    }

    public function testTheStatusSectionIsForOrganizersOnly(): void
    {
        self::assertStringNotContainsString('## Current status', $this->facts(false));
        self::assertStringNotContainsString('Organizers:', $this->facts(false));

        $facts = $this->facts(true);

        self::assertStringContainsString('## Current status', $facts);
        self::assertStringContainsString('Announcement email (the newsletter to subscribers): switched on', $facts);
        self::assertStringContainsString('Post-event email (to ticket holders the day after a night, 12:00)', $facts);
    }

    public function testNoEmailAddressAppearsInTheStatusOrThePersonFacts(): void
    {
        $this->night();
        $this->results = [
            [['DISTINCT task_date'], ['2026-08-01']],
            [['a.*, s.task_date'], [
                ['id' => 3, 'task_id' => 2, 'person_id' => 9, 'status' => 'signed_up', 'task_date' => '2026-08-01'],
            ]],
            [['is_organizer = 1'], [
                ['id' => 7, 'email' => 'lou@example.test', 'display_name' => 'Lou', 'is_organizer' => 1],
                ['id' => 8, 'email' => 'kim@example.test', 'display_name' => '', 'is_organizer' => 1],
            ]],
            ...$this->results,
        ];
        $this->rows[] = [['eventcrew_tasks', 'WHERE id'], self::taskRow(2, 'bar', 3)];

        $facts = $this->facts(true, 9);

        self::assertStringContainsString('Organizers: Lou, kim.', $facts);
        self::assertStringContainsString('## This person', $facts);
        self::assertStringContainsString('Night 2026-08-01 (Saturday), Party:', $facts);
        self::assertStringNotContainsString('@', $facts);
    }

    public function testThePersonSectionListsOwnTasksAndTickets(): void
    {
        $this->results = [
            [['a.*, s.task_date'], [
                ['id' => 3, 'task_id' => 2, 'person_id' => 9, 'status' => 'signed_up', 'task_date' => '2026-08-01'],
                ['id' => 4, 'task_id' => 2, 'person_id' => 9, 'status' => 'completed', 'task_date' => '2026-07-01'],
            ]],
            [['eventcrew_assignments', 'person_id'], [
                ['id' => 3, 'task_id' => 2, 'person_id' => 9, 'status' => 'signed_up'],
            ]],
        ];
        $this->rows[] = [['eventcrew_tasks', 'WHERE id'], self::taskRow(2, 'bar', 3)];
        Functions\when('add_query_arg')->justReturn('https://site.test/ticket');
        Functions\when('rest_url')->justReturn('https://site.test/wp-json/');

        $text = $this->service()->personText(9);

        self::assertStringContainsString('- Coming tasks: 2026-08-01 (Saturday) bar 20:00–23:00 (Party).', $text);
        self::assertStringContainsString('- Coming tickets: 2026-08-01 (Saturday) bar · Party.', $text);
        self::assertStringContainsString(
            '- Latest past tasks: 2026-07-01 (Wednesday) bar 20:00–23:00 (Party): ',
            $text
        );
        self::assertSame('', $this->service()->personText(0));
    }
}
