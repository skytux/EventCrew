<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\TaskCancelledNotice;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketQr;
use EventCrew\Tests\TestCase;

/** The test clock is 2026-07-20 12:00. */
final class TaskCancelledNoticeTest extends TestCase
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $default
        );
        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_permalink')->justReturn(false);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    private function notice(): TaskCancelledNotice
    {
        return new TaskCancelledNotice(
            new TaskRepository(),
            new PersonRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    /** @return array<string, mixed> */
    private function task(string $date, ?string $endsAt = null): array
    {
        return [
            'id' => 5, 'task_date' => $date, 'role_slug' => 'clean', 'capacity' => 3,
            'event_post_id' => null, 'event_label' => 'Party',
            'starts_at' => null === $endsAt ? null : $date . ' 09:00:00', 'ends_at' => $endsAt,
        ];
    }

    public function testEveryoneSignedUpIsEmailedInFinnishAndEnglish(): void
    {
        $this->wpdb->nextRows[] = $this->task('2026-08-01'); // tasks->find
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna'];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'bo@example.com', 'display_name' => 'Bo'];

        $this->notice()->notify(5, [7, 8]);

        self::assertSame(['anna@example.com', 'bo@example.com'], array_column($this->mails, 'to'));
        $mail = $this->mails[0];
        self::assertStringContainsString('Tehtävä peruttu', $mail['subject']);
        self::assertStringContainsString('Task cancelled', $mail['subject']);
        self::assertStringContainsString('Järjestäjät ovat peruneet tehtävän', $mail['body']);
        self::assertStringContainsString('The organizers have cancelled the task', $mail['body']);
        self::assertStringContainsString('Party', $mail['body']);
    }

    /**
     * Q228: the person's emailed ticket for the night follows the change.
     * With no task left that night it is cancelled (the red-band email).
     */
    public function testTheEmailedTicketIsCancelledWhenNoTaskIsLeft(): void
    {
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $notice = new TaskCancelledNotice(
            new TaskRepository(),
            new PersonRepository(),
            $mailer,
            new TicketMail(
                new TicketBuyerRepository(),
                new GuestRepository(),
                new TaskRepository(),
                $mailer,
                new TicketQr(),
                new AssignmentRepository(),
                new RedemptionRepository()
            )
        );
        $gone = ['id' => 30, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled',
            'ticket_mailed_at' => '2026-07-19 10:00:00'];
        $this->wpdb->nextRows[] = $this->task('2026-08-01');
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna'];
        $this->wpdb->nextResults[] = [$gone]; // the emailed ticket
        $this->wpdb->nextResults[] = [$gone]; // no task left

        $notice->notify(5, [7]);

        self::assertSame(['anna@example.com', 'anna@example.com'], array_column($this->mails, 'to'));
        self::assertStringContainsString('Task cancelled', $this->mails[0]['subject']);
        self::assertStringContainsString('Your ticket is cancelled', $this->mails[1]['subject']);
    }

    public function testNothingIsSentForATaskOnAPastDate(): void
    {
        $this->wpdb->nextRows[] = $this->task('2026-07-19');
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna'];

        $this->notice()->notify(5, [7]);

        self::assertSame([], $this->mails);
    }

    public function testNothingIsSentForATaskThatHasEndedToday(): void
    {
        $this->wpdb->nextRows[] = $this->task('2026-07-20', '2026-07-20 11:00:00');
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna'];

        $this->notice()->notify(5, [7]);

        self::assertSame([], $this->mails);
    }

    public function testATaskLaterTodayStillSends(): void
    {
        $this->wpdb->nextRows[] = $this->task('2026-07-20', '2026-07-20 23:00:00');
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna'];

        $this->notice()->notify(5, [7]);

        self::assertCount(1, $this->mails);
    }

    public function testNoSignUpsReadsNothingAndSendsNothing(): void
    {
        $this->notice()->notify(5, []);

        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testAPersonWithAPlaceholderAddressIsSkipped(): void
    {
        $this->wpdb->nextRows[] = $this->task('2026-08-01');
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'person-7@example.invalid', 'display_name' => 'Anna'];

        $this->notice()->notify(5, [7]);

        self::assertSame([], $this->mails);
    }
}
