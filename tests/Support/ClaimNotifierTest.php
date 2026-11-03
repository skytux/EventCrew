<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Language;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Roles;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketQr;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;
use stdClass;

/**
 * The shared confirmation emails both channels send. Light coverage: a signup
 * mails a ticket (with a calendar hold) and also confirms in the Telegram DM.
 */
final class ClaimNotifierTest extends TestCase
{
    /** @var array<int, array{to: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    private function notifier(): ClaimNotifier
    {
        return new ClaimNotifier(
            new TaskRepository(),
            new AssignmentRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new TelegramClient(new Logger(), new DohResolver(new Logger())),
            new StandingCalculator(new AssignmentRepository(), new RedemptionRepository(), new CreditGrantRepository())
        );
    }

    private function person(): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(): array
    {
        return [
            'id' => 5,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    public function testSignupConfirmationCarriesTheTicketLink(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow();                                        // tasks->find
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up']; // findFor

        $this->notifier()->confirmSignup($this->person(), 5);

        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        self::assertStringContainsString('wp-json/eventcrew/v1/ticket?token=', $this->mails[0]['body']);
        // Also an add-to-calendar hold, the strongest nudge against a no-show.
        self::assertStringContainsString('wp-json/eventcrew/v1/calendar?token=', $this->mails[0]['body']);
    }

    private function notifierWithTicketMail(): ClaimNotifier
    {
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));

        return new ClaimNotifier(
            new TaskRepository(),
            new AssignmentRepository(),
            $mailer,
            new TelegramClient(new Logger(), new DohResolver(new Logger())),
            new StandingCalculator(new AssignmentRepository(), new RedemptionRepository(), new CreditGrantRepository()),
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

    /** Decision 1: a sign-up also gets the separate "Your ticket" email, with an EC-C code. */
    public function testASignupAlsoGetsTheSeparateTicketEmail(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->notifierWithTicketMail()->confirmSignup($this->person(), 5);

        self::assertCount(2, $this->mails);
        self::assertStringContainsString('Your ticket', $this->mails[1]['subject']);
        self::assertSame('sam@example.com', $this->mails[1]['to']);
        $marked = array_values(array_filter(
            $this->wpdb->updates,
            static fn (array $u): bool => 'wp_eventcrew_assignments' === $u['table']
        ));
        self::assertSame(['id' => 9], $marked[0]['where']);
        self::assertArrayHasKey('ticket_mailed_at', $marked[0]['data']);
    }

    /** Decision 4: cancelling a sign-up whose ticket was emailed sends "Your ticket is cancelled". */
    public function testCancellingASignupWithAnEmailedTicketSendsTheCancelEmail(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = [
            'id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled',
            'ticket_mailed_at' => '2026-07-19 10:00:00',
        ];

        $this->notifierWithTicketMail()->confirmCancellation($this->person(), 5, 'cancelled');

        $subjects = array_column($this->mails, 'subject');
        self::assertCount(2, $subjects);
        self::assertStringContainsString('Your ticket is cancelled', $subjects[1]);
    }

    public function testCancellingASignupWhoseTicketWasNotEmailedSendsNoCancelEmail(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled'];

        $this->notifierWithTicketMail()->confirmCancellation($this->person(), 5, 'cancelled');

        self::assertCount(1, $this->mails);
    }

    public function testSignupAlsoConfirmsInTheTelegramDm(): void
    {
        // A bot token and a captured transport turn the DM into an assertion.
        $sends = [];
        Functions\when('get_option')->alias(
            static fn (string $name): mixed => TelegramClient::TOKEN_OPTION === $name ? 'BOT:TOKEN' : false
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $d): string => (string) json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$sends): string {
            $sends[] = (array) json_decode((string) $args['body'], true);

            return (string) json_encode(['ok' => true, 'result' => true]);
        });

        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->notifier()->confirmSignup($this->telegramPerson(), 5);

        self::assertCount(1, $sends);
        self::assertSame(4242, $sends[0]['chat_id']);
        self::assertStringContainsString('Signed up', (string) $sends[0]['text']);
    }

    public function testAMutedPersonStillGetsTheCommitmentDm(): void
    {
        $sends = [];
        Functions\when('get_option')->alias(
            static fn (string $name): mixed => TelegramClient::TOKEN_OPTION === $name ? 'BOT:TOKEN' : false
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $d): string => (string) json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$sends): string {
            $sends[] = (array) json_decode((string) $args['body'], true);

            return (string) json_encode(['ok' => true, 'result' => true]);
        });

        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->notifier()->confirmSignup($this->telegramPerson(true), 5);

        // Signup is a commitment confirmation - locked on - so a muted account
        // still gets the DM (and the email).
        self::assertCount(1, $sends);
        self::assertCount(1, $this->mails);
    }

    private function telegramPerson(bool $muted = false): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 4242,
            'notify_muted' => $muted ? 1 : 0,
        ]);
    }
    public function testSignupConfirmationOffersTheGuideAsANamedButtonAndAction(): void
    {
        $sends = [];
        Functions\when('get_option')->alias(
            static fn (string $name): mixed => match ($name) {
                TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
                Roles::GUIDE_URL_OPTION => 'https://docs.example/guide',
                default => false,
            }
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $d): string => (string) json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$sends): string {
            $sends[] = (array) json_decode((string) $args['body'], true);

            return (string) json_encode(['ok' => true, 'result' => true]);
        });

        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->notifier()->confirmSignup($this->telegramPerson(), 5);

        // Every link is a named button: ticket, calendar, then the guide. None
        // of them appears as a raw address in the text.
        $text = (string) $sends[0]['text'];
        self::assertStringContainsString('About the task', $text);
        self::assertStringNotContainsString('docs.example', $text);
        self::assertStringNotContainsString('token=', $text);

        $rows = $sends[0]['reply_markup']['inline_keyboard'];
        self::assertStringContainsString('eventcrew/v1/ticket?token=', $rows[0][0]['url']);
        self::assertStringContainsString('eventcrew/v1/calendar?token=', $rows[1][0]['url']);
        self::assertSame('https://docs.example/guide', $rows[2][0]['url']);

        self::assertStringContainsString('About the task', $this->mails[0]['body']);
        self::assertStringContainsString('Crew guide', $this->mails[0]['body']);
        self::assertStringContainsString('https://docs.example/guide', $this->mails[0]['body']);
    }

    /**
     * A person who chose Finnish gets the DM and the email in Finnish, read
     * from the shipped .mo.
     */
    public function testAPersonWhoChoseFinnishGetsTheDmAndEmailInFinnish(): void
    {
        $sends = $this->captureDms();

        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->notifier()->confirmSignup($this->personInLanguage('fi'), 5);

        self::assertCount(1, $sends->items);
        self::assertStringContainsString('✅ Ilmoittauduttu:', (string) $sends->items[0]['text']);
        self::assertStringNotContainsString('Signed up', (string) $sends->items[0]['text']);
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('olet ilmoittautunut tehtävään', $this->mails[0]['body']);
        // The language was the person's for this message only.
        self::assertNull(Language::current());
    }

    public function testAPersonWhoHasNotChosenGetsEnglish(): void
    {
        $sends = $this->captureDms();

        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->notifier()->confirmSignup($this->personInLanguage(null), 5);

        self::assertStringContainsString('✅ Signed up:', (string) $sends->items[0]['text']);
        self::assertStringContainsString('signed up for', $this->mails[0]['body']);
        self::assertStringNotContainsString('ilmoittautunut', $this->mails[0]['body']);
    }

    private function personInLanguage(?string $language): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 4242,
            'language' => $language,
        ]);
    }

    /**
     * A bot token and a captured transport, so the DM becomes an assertion.
     */
    private function captureDms(): stdClass
    {
        $sends = new stdClass();
        $sends->items = [];
        Functions\when('get_option')->alias(
            static fn (string $name): mixed => TelegramClient::TOKEN_OPTION === $name ? 'BOT:TOKEN' : false
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $d): string => (string) json_encode($d));
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_post')->alias(static function (string $url, array $args) use ($sends): string {
            $sends->items[] = (array) json_decode((string) $args['body'], true);

            return (string) json_encode(['ok' => true, 'result' => true]);
        });

        return $sends;
    }
}
