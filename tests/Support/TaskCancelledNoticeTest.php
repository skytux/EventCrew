<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\TaskCancelledNotice;
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
