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
use EventCrew\Support\SlotFreedNotice;
use EventCrew\Support\TaskCancelledNotice;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketQr;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

/** The test clock is 2026-07-20 12:00. */
final class TaskCancelledNoticeTest extends TestCase
{
    use AiTextOptions;

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

    /** Each person gets the email in their own language only, hybrid tick or not (Owner, 2026-11-06). */
    public function testEveryoneSignedUpIsEmailedInTheirOwnLanguage(): void
    {
        $this->wpdb->nextRows[] = $this->task('2026-08-01'); // tasks->find
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna', 'language' => 'fi'];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'bo@example.com', 'display_name' => 'Bo'];

        $this->notice()->notify(5, [7, 8]);

        self::assertSame(['anna@example.com', 'bo@example.com'], array_column($this->mails, 'to'));
        [$anna, $bo] = $this->mails;
        self::assertStringContainsString('Tehtävä peruttu', $anna['subject']);
        self::assertStringNotContainsString('Task cancelled', $anna['subject']);
        self::assertStringContainsString('Järjestäjät ovat peruneet tehtävän', $anna['body']);
        self::assertStringNotContainsString('The organizers have cancelled the task', $anna['body']);
        self::assertStringContainsString('Party', $anna['body']);
        // No language chosen: English, as everywhere else.
        self::assertStringContainsString('Task cancelled', $bo['subject']);
        self::assertStringNotContainsString('Tehtävä peruttu', $bo['subject']);
    }

    /**
     * A person who uses the bot also gets a DM in their language; the DM and
     * the email list the event's other open tasks and lead to the board.
     */
    public function testABotUserAlsoGetsADmAndBothListTheOtherOpenTasks(): void
    {
        $sends = [];
        $options = [TelegramClient::TOKEN_OPTION => 'BOT:TOKEN', 'eventcrew_signup_page_id' => 3];
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_json_encode')->alias(static fn (mixed $d): string => (string) json_encode($d));
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_post')->alias(static function (string $url, array $args) use (&$sends): string {
            $sends[] = (array) json_decode((string) $args['body'], true);

            return (string) json_encode(['ok' => true, 'result' => true]);
        });
        $other = ['id' => 6, 'role_slug' => 'bar'] + $this->task('2026-08-01');
        $this->wpdb->nextRows[] = $this->task('2026-08-01');                      // tasks->find
        $this->wpdb->nextResults[] = [$this->task('2026-08-01'), $other];         // otherOpen: the day's tasks
        $this->wpdb->nextResults[] = [['task_id' => 6, 'taken' => 1]];            // otherOpen: occupancy
        $this->wpdb->nextRows[] = [
            'id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna',
            'telegram_chat_id' => 700, 'language' => 'en',
        ];
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'bo@example.com', 'display_name' => 'Bo'];
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $telegram = new TelegramClient(new Logger(), new DohResolver(new Logger()));

        (new TaskCancelledNotice(
            new TaskRepository(),
            new PersonRepository(),
            $mailer,
            null,
            $telegram,
            new SlotFreedNotice(new TaskRepository(), new PersonRepository(), $mailer, $telegram)
        ))->notify(5, [7, 8]);

        self::assertCount(1, $sends, 'only the bot user gets a DM');
        self::assertSame(700, $sends[0]['chat_id']);
        self::assertStringStartsWith('🚫 The organizers have cancelled the task ', (string) $sends[0]['text']);
        self::assertStringContainsString("standing.\n\nOther open tasks at Party:\n- ", (string) $sends[0]['text']);
        $buttons = array_merge(...$sends[0]['reply_markup']['inline_keyboard']);
        self::assertSame(['See open tasks'], array_column($buttons, 'text'));
        self::assertSame(['anna@example.com', 'bo@example.com'], array_column($this->mails, 'to'));
        self::assertStringContainsString('Other open tasks at Party:', $this->mails[1]['body']);
        // Bo has not chosen a language, so he reads English alone (Owner, 2026-11-06).
        self::assertStringNotContainsString('Muut avoimet tehtävät', $this->mails[1]['body']);
    }

    /** MessageAiText: the event's AI text in each language when there is one, else the template's text. */
    public function testTheEmailUsesTheEventsAiTextWhenThereIsOne(): void
    {
        $task = ['event_post_id' => 42] + $this->task('2026-08-01');

        foreach ([['task_cancelled' => ['en' => 'AI EN for {name}.', 'fi' => 'AI FI for {name}.']], []] as $texts) {
            $options = self::aiTextOptions(['task_cancelled'], 42, $texts);
            Functions\when('get_option')->alias(
                static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
            );
            $this->wpdb->nextRows[] = $task;
            $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna'];

            $this->notice()->notify(5, [7]);
        }

        self::assertCount(2, $this->mails);
        // Anna has no language chosen: the English AI text only.
        self::assertStringNotContainsString('AI FI for Anna.', $this->mails[0]['body']);
        self::assertStringContainsString('AI EN for Anna.', $this->mails[0]['body']);
        // The tick is on but nothing was written for the event: the template's text.
        self::assertStringContainsString('The organizers have cancelled the task', $this->mails[1]['body']);
        self::assertStringNotContainsString('AI EN', $this->mails[1]['body']);
    }

    private function noticeWithTickets(): TaskCancelledNotice
    {
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));

        return new TaskCancelledNotice(
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
    }

    /**
     * Q263: when the person's ticket for the night was emailed and no task is
     * left that night, they get one email: the red-band cancel email, which
     * names the deleted task. No separate "Task cancelled" email.
     */
    public function testOneCancelEmailNamingTheTaskWhenNoTaskIsLeft(): void
    {
        $notice = $this->noticeWithTickets();
        $gone = ['id' => 30, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled',
            'ticket_mailed_at' => '2026-07-19 10:00:00'];
        $this->wpdb->nextRows[] = $this->task('2026-08-01', '2026-08-01 11:00:00');
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna'];
        $this->wpdb->nextResults[] = [$gone]; // ticketEmailed
        $this->wpdb->nextResults[] = [$gone]; // the emailed ticket
        $this->wpdb->nextResults[] = [$gone]; // no task left

        $notice->notify(5, [7]);

        self::assertSame(['anna@example.com'], array_column($this->mails, 'to'));
        $mail = $this->mails[0];
        self::assertStringContainsString('Your ticket is cancelled', $mail['subject']);
        self::assertStringContainsString('The organizers have cancelled the task', $mail['body']);
        self::assertStringContainsString('09:00', $mail['body']);
        self::assertStringContainsString('TICKET CANCELLED', $mail['body']);
    }

    /**
     * Q263: when the person still holds another task that night, they get one
     * email: their ticket again with the task left, naming the deleted task.
     */
    public function testOneTicketEmailNamingTheTaskWhenATaskIsLeft(): void
    {
        $notice = $this->noticeWithTickets();
        $gone = ['id' => 30, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled',
            'ticket_mailed_at' => '2026-07-19 10:00:00'];
        $kept = ['id' => 31, 'task_id' => 6, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = $this->task('2026-08-01', '2026-08-01 11:00:00');
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna'];
        $this->wpdb->nextResults[] = [$gone, $kept]; // ticketEmailed
        $this->wpdb->nextResults[] = [$gone, $kept]; // the emailed ticket
        $this->wpdb->nextResults[] = [$gone, $kept]; // the tasks left
        $this->wpdb->nextRows[] = [
            'id' => 6, 'task_date' => '2026-08-01', 'role_slug' => 'bar', 'capacity' => 2,
            'event_post_id' => null, 'event_label' => 'Party',
            'starts_at' => '2026-08-01 21:00:00', 'ends_at' => '2026-08-01 23:00:00',
        ];

        $notice->notify(5, [7]);

        self::assertSame(['anna@example.com'], array_column($this->mails, 'to'));
        $mail = $this->mails[0];
        self::assertStringContainsString('Your ticket', $mail['subject']);
        self::assertStringNotContainsString('cancelled', $mail['subject']);
        self::assertStringContainsString('The organizers have cancelled the task', $mail['body']);
        self::assertStringContainsString('Your tasks on this night:', $mail['body']);
        self::assertStringContainsString('21:00', $mail['body']);
        self::assertStringNotContainsString('Thank you for taking a task!', $mail['body']);
    }

    /**
     * Q263: when no ticket for the night was emailed, the one email is the
     * "Task cancelled" email.
     */
    public function testWithoutAnEmailedTicketTheOneEmailIsTaskCancelled(): void
    {
        $notice = $this->noticeWithTickets();
        $this->wpdb->nextRows[] = $this->task('2026-08-01');
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'anna@example.com', 'display_name' => 'Anna'];
        $this->wpdb->nextResults[] = [['id' => 30, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled',
            'ticket_mailed_at' => null]];

        $notice->notify(5, [7]);

        self::assertSame(['anna@example.com'], array_column($this->mails, 'to'));
        self::assertStringContainsString('Task cancelled', $this->mails[0]['subject']);
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
