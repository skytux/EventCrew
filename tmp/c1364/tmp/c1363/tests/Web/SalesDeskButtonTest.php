<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\SignupService;
use EventCrew\Support\Turnstile;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Tests\Support\SalesDeskTestCase;
use EventCrew\Web\SignupController;

/**
 * The "Sales desk" button on the member page: shown under the same rule as
 * /sales, and checked again on the server when it is pressed, which answers
 * either a fresh login link to redirect to or the reason why not. The clock
 * reads 12:00 on the night.
 */
final class SalesDeskButtonTest extends SalesDeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
    }

    private function controller(): SignupController
    {
        $assignments = new AssignmentRepository();
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));

        return new SignupController(
            new PersonRepository(),
            new AuthTokenRepository(),
            new TaskRepository(),
            $assignments,
            new SignupService($assignments, $this->standing(), new PersonRepository(), new TaskRepository()),
            $this->standing(),
            $mailer,
            new ClaimNotifier(new TaskRepository(), $assignments, $mailer, $this->client(), $this->standing()),
            new Turnstile(new Logger()),
            new TicketRedemptionService(
                new PersonRepository(),
                new TaskRepository(),
                new RedemptionRepository(),
                $this->standing(),
                new FreeEntryGate(),
                $this->client(),
                $mailer
            ),
            $this->salesDeskGate(),
            $this->doorLinkSender()
        );
    }

    private function person(int $id, bool $organizer = false): Person
    {
        return Person::fromRow($this->personRow($id, $organizer));
    }

    /**
     * Queues the night list and the gate's reads for person 8 on Welcome task
     * 6 starting at $start, as $status.
     */
    private function queueWelcome(string $start, string $status): void
    {
        $this->wpdb->nextCols[] = [self::NIGHT];
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'welcome', $start, '14:00'), $this->taskRow(7, 'clean', '23:00')];
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, $status);
    }

    public function testTheButtonShowsForAnOrganizer(): void
    {
        $this->wpdb->nextCols[] = [self::NIGHT];

        self::assertTrue($this->controller()->canOpenSalesDesk($this->person(1, true)));
    }

    public function testTheButtonShowsForArrivedWelcomeCrewWithinTenMinutes(): void
    {
        $this->queueWelcome('12:10', 'arrived');

        self::assertTrue($this->controller()->canOpenSalesDesk($this->person(8)));
    }

    public function testTheButtonIsHiddenBeforeArrivalOrTooEarly(): void
    {
        $this->queueWelcome('12:05', 'signed_up');
        self::assertFalse($this->controller()->canOpenSalesDesk($this->person(8)));

        $this->queueWelcome('12:30', 'arrived');
        self::assertFalse($this->controller()->canOpenSalesDesk($this->person(8)));
    }

    public function testTheButtonIsHiddenForOtherCrew(): void
    {
        $this->wpdb->nextCols[] = [self::NIGHT];
        $this->wpdb->nextResults[] = [$this->taskRow(5, 'decorate', '12:00', '13:00')];

        self::assertFalse($this->controller()->canOpenSalesDesk($this->person(8)));
    }

    public function testPressingItGivesAFreshLoginLinkToRedirectTo(): void
    {
        $this->queueWelcome('12:10', 'arrived');
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'welcome', '12:10', '14:00'), $this->taskRow(7, 'clean', '23:00')];

        $sales = $this->controller()->salesDeskFor($this->person(8));

        self::assertSame('', $sales['notice']);
        self::assertStringStartsWith('https://site.test/wp-admin/admin-ajax.php?action=eventcrew_door&t=', $sales['url']);
        self::assertSame('door', $this->wpdb->inserts[0]['data']['purpose']);
    }

    public function testPressingItTooEarlyChecksAgainAndSaysWhy(): void
    {
        $this->queueWelcome('12:30', 'arrived');

        $sales = $this->controller()->salesDeskFor($this->person(8));

        self::assertSame('', $sales['url']);
        self::assertSame(
            'The sales desk link opens 10 minutes before your task, once you are marked arrived.',
            SignupController::noticeText($sales['notice'])
        );
        self::assertSame([], $this->wpdb->inserts);
    }
}
