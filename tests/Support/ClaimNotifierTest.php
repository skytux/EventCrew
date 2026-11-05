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
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\Roles;
use EventCrew\Support\SignedLink;
use EventCrew\Support\SignupService;
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
        Functions\when('current_time')->justReturn('2026-07-20');
        $cancelled = [
            'id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled',
            'ticket_mailed_at' => '2026-07-19 10:00:00',
        ];
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextResults[] = [];           // the standing line
        $this->wpdb->nextResults[] = [$cancelled]; // forPersonOn: the emailed ticket
        $this->wpdb->nextResults[] = [$cancelled]; // forPersonOn: no task left

        $this->notifierWithTicketMail()->confirmCancellation($this->person(), 5, 'cancelled');

        $subjects = array_column($this->mails, 'subject');
        self::assertCount(2, $subjects);
        self::assertStringContainsString('Your ticket is cancelled', $subjects[1]);
    }

    /** Q228: with another task left that night, the ticket email again, same code, new task list. */
    public function testCancellingOneOfTwoTasksResendsTheTicketWithTheTaskLeft(): void
    {
        Functions\when('current_time')->justReturn('2026-07-20');
        $cancelled = [
            'id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled',
            'ticket_mailed_at' => '2026-07-19 10:00:00',
        ];
        $kept = ['id' => 10, 'task_id' => 6, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextResults[] = [];  // the standing line
        $this->wpdb->nextResults[] = [$cancelled, $kept];
        $this->wpdb->nextResults[] = [$cancelled, $kept];
        $this->wpdb->nextRows[] = ['role_slug' => 'bar', 'id' => 6] + $this->taskRow(); // tasks->find(6)

        $this->notifierWithTicketMail()->confirmCancellation($this->person(), 5, 'cancelled');

        self::assertCount(2, $this->mails);
        self::assertStringContainsString('Your ticket', $this->mails[1]['subject']);
        self::assertStringNotContainsString('cancelled', $this->mails[1]['subject']);
        self::assertStringContainsString('Your tasks on this night:', $this->mails[1]['body']);
        // The code is the first ticket's (assignment 9), so the QR they have keeps working.
        self::assertStringContainsString('token=' . SignedLink::sign('ticket', 9), $this->mails[1]['body']);
    }

    public function testCancellingASignupWhoseTicketWasNotEmailedSendsNoCancelEmail(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextResults[] = [['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled']];

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

    // ---- The Messages page templates (Q297) -------------------------------

    private const MANAGE = "\n\n—\nManage your account or stop these emails: "
        . 'https://site.test/wp-json/eventcrew/v1/manage?token=Nw.'
        . '2e7412c689f539f6489b9a1e298151a2431bfc264f90e62776a73bce4d9e9398';

    /**
     * A bot token, the crew guide, a 48-hour notice and plain-text email, plus
     * $options; the DMs are captured.
     *
     * @param array<string, mixed> $options
     */
    private function captureWithOptions(array $options = []): stdClass
    {
        $sends = $this->captureDms();
        $options += [
            TelegramClient::TOKEN_OPTION => 'BOT:TOKEN',
            Roles::GUIDE_URL_OPTION => 'https://docs.example/guide',
            SignupService::NOTICE_HOURS_OPTION => 48,
            EmailTemplate::HTML_OPTION => false,
        ];
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );
        Functions\when('is_wp_error')->justReturn(false);

        return $sends;
    }

    /**
     * The task of the template tests: with a time, so {when} has one.
     *
     * @return array<string, mixed>
     */
    private function timedTaskRow(): array
    {
        return ['starts_at' => '2026-08-01 18:00:00', 'ends_at' => '2026-08-01 20:00:00'] + $this->taskRow();
    }

    private function signUp(Person $person): void
    {
        $this->wpdb->nextRows[] = $this->timedTaskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->notifier()->confirmSignup($person, 5);
    }

    /** With nothing stored, the sign-up DM and email are what they were before the templates. */
    public function testTheSignupMessagesAreTheSameAsBeforeTheTemplates(): void
    {
        $sends = $this->captureWithOptions();

        $this->signUp($this->personInLanguage(null));

        $about = '📝 About the task: Set up before the dance: close the curtains, check the bathrooms, place the LED'
            . ' candles, decorate the DJ table, prepare the water and fruit, and open the movable walls.';
        self::assertSame(
            "✅ Signed up: Decorate at Party, Sat 1 Aug 18:00–20:00.\n\nShow your ticket at the door - it is the first"
                . " button below.\n\nTo cancel, tap the task again on the group board or in /board in a private chat,"
                . ' or tap Cancel in your reminder. Or find someone to take it over: they send /me to the bot and tap'
                . ' 🔁 Take over a task, and you accept. You can cancel without it affecting your standing up to'
                . ' 48 hours before the task.'
                . "\n\n" . $about . "\n\nYour standing: Beginner · 0 free-entry credits.",
            (string) $sends->items[0]['text']
        );
        self::assertSame('You’re signed up: Decorate for Party', $this->mails[0]['subject']);
        self::assertSame(
            "Hi Sam,\n\nYou're signed up for Decorate at Party, Sat 1 Aug 18:00–20:00. Show your ticket at the door."
                . "\n\nYour standing: Beginner · 0 free-entry credits.\n\nCan't make it? Open the bot and tap the task"
                . ' to cancel. Or find someone to take it over: they send /me to the bot and tap 🔁 Take over a task,'
                . ' and you accept. You can cancel without it affecting your standing up to 48 hours before the task.'
                . "\n\n" . $about . "\n\n"
                . 'Show your ticket: https://site.test/wp-json/eventcrew/v1/ticket?token='
                . SignedLink::sign('ticket', 9) . "\n"
                . 'Add to calendar: https://site.test/wp-json/eventcrew/v1/calendar?token='
                . SignedLink::sign('calendar', 5) . "\n"
                . '📋 Crew guide: https://docs.example/guide'
                . self::MANAGE,
            $this->mails[0]['body']
        );
    }

    /** The same in Finnish, read from the shipped .mo, with no notice hours and no guide. */
    public function testTheFinnishSignupMessagesAreTheSameAsBeforeTheTemplates(): void
    {
        $sends = $this->captureWithOptions([SignupService::NOTICE_HOURS_OPTION => 0, Roles::GUIDE_URL_OPTION => '']);

        $this->signUp($this->personInLanguage('fi'));

        $about = '📝 Tehtävästä: Valmistelu ennen tansseja: sulje verhot, tarkista vessat, aseta LED-kynttilät,'
            . ' somista DJ-pöytä, valmistele vesi ja hedelmät ja avaa siirtoseinät.';
        self::assertSame(
            "✅ Ilmoittauduttu: Somistus, Party, Sat 1 Aug 18:00–20:00.\n\nNäytä lippusi ovella - se on alla"
                . " ensimmäisenä painikkeena.\n\nPeru napauttamalla tehtävää uudelleen ryhmän taululla tai komennolla"
                . ' /board yksityiskeskustelussa, tai napauta Peru muistutuksessasi. Tai etsi joku ottamaan se: hän'
                . ' lähettää botille /me ja napauttaa 🔁 Ota tehtävä, ja sinä hyväksyt.'
                . "\n\n" . $about . "\n\nLuotettavuutesi: Aloittelija · 0 vapaalippua.",
            (string) $sends->items[0]['text'],
            'a notice of 0 hours leaves out the free-cancel sentence'
        );
        self::assertSame('Olet ilmoittautunut: Somistus, Party', $this->mails[0]['subject']);
        self::assertStringStartsWith(
            "Hei Sam,\n\nolet ilmoittautunut tehtävään Somistus, Party, Sat 1 Aug 18:00–20:00. Näytä lippusi ovella."
                . "\n\nLuotettavuutesi: Aloittelija · 0 vapaalippua.\n\nEtkö pääsekään? Avaa botti ja peru"
                . ' napauttamalla tehtävää. Tai etsi joku ottamaan se: hän lähettää botille /me ja napauttaa'
                . ' 🔁 Ota tehtävä, ja sinä hyväksyt.' . "\n\n" . $about . "\n\nNäytä lippusi: ",
            $this->mails[0]['body']
        );
    }

    /** With nothing stored, both cancellation DMs and emails are what they were before the templates. */
    public function testTheCancelMessagesAreTheSameAsBeforeTheTemplates(): void
    {
        $sends = $this->captureWithOptions();
        $late = 'This was a late cancellation, which counts against your standing. Next time, more notice keeps it'
            . ' clear, and so does a replacement: they send /me to the bot and tap 🔁 Take over a task, and you accept.';
        $standing = 'Your standing: Beginner · 0 free-entry credits.';
        $cases = [
            'cancelled' => ['❌ Cancelled', 'Thanks for the notice.'],
            'late_cancel' => ['⚠️ Late cancellation', $late],
        ];

        foreach ($cases as $status => [$kind, $note]) {
            $sends->items = [];
            $this->mails = [];
            $this->wpdb->nextRows[] = $this->timedTaskRow();

            $this->notifier()->confirmCancellation($this->personInLanguage(null), 5, $status);

            self::assertSame(
                $kind . ': Decorate at Party. ' . $note . "\n\n" . $standing,
                (string) $sends->items[0]['text'],
                $status
            );
            self::assertSame('Cancelled: Decorate for Party', $this->mails[0]['subject'], $status);
            self::assertSame(
                "Hi Sam,\n\nYou've cancelled Decorate at Party, and your ticket is now disabled.\n\n" . $note
                    . "\n\n" . $standing . self::MANAGE,
                $this->mails[0]['body'],
                $status
            );
        }
    }

    /**
     * @return array{source: string, texts: array<string, string>, manual: array<string, bool>}
     */
    private static function storedText(string $text): array
    {
        return [
            'source' => $text,
            'texts' => ['fi' => $text, 'en' => $text],
            'manual' => ['fi' => false, 'en' => false],
        ];
    }

    /** A text saved on the Messages page is what is sent, with its placeholders filled. */
    public function testAStoredSignupAndCancelTextIsSent(): void
    {
        $prefix = MessageTemplates::OPTION_PREFIX;
        $sends = $this->captureWithOptions([
            $prefix . 'signup_confirmed' => [
                MessageTemplates::SUBJECT => self::storedText('In: {role}'),
                MessageTemplates::BODY => self::storedText('{name} does {role} at {event}, {when}.{about}|{standing}'),
            ],
            $prefix . 'signup_confirmed_dm' => [MessageTemplates::BODY => self::storedText('DM {role}|{standing}')],
            $prefix . 'cancel_confirmed' => [
                MessageTemplates::SUBJECT => self::storedText('Out: {role}'),
                MessageTemplates::BODY => self::storedText('{name}: {note}'),
            ],
            $prefix . 'cancel_confirmed_dm' => [MessageTemplates::BODY => self::storedText('{cancelled} {role}')],
            Roles::GUIDE_URL_OPTION => '',
        ]);

        $this->signUp($this->personInLanguage(null));

        self::assertSame(
            'DM Decorate|Your standing: Beginner · 0 free-entry credits.',
            (string) $sends->items[0]['text']
        );
        self::assertSame('In: Decorate', $this->mails[0]['subject']);
        self::assertStringStartsWith(
            "Sam does Decorate at Party, Sat 1 Aug 18:00–20:00.\n\n📝 About the task: Set up",
            $this->mails[0]['body']
        );
        self::assertStringContainsString('|Your standing: Beginner', $this->mails[0]['body']);
        // The ticket button is not part of the text, so it is still there.
        self::assertStringContainsString('Show your ticket: https://site.test/', $this->mails[0]['body']);

        $sends->items = [];
        $this->mails = [];
        $this->wpdb->nextRows[] = $this->timedTaskRow();
        $this->notifier()->confirmCancellation($this->personInLanguage(null), 5, 'late_cancel');

        self::assertSame('⚠️ Late cancellation Decorate', (string) $sends->items[0]['text']);
        self::assertSame('Out: Decorate', $this->mails[0]['subject']);
        self::assertStringStartsWith('Sam: This was a late cancellation', $this->mails[0]['body']);
    }

    // ---- Web-only crew (E2) ----------------------------------------------

    /** A crew page (id 7) at https://site.test/crew/; the DMs are captured. */
    private function captureWithCrewPage(): stdClass
    {
        Functions\when('get_permalink')->alias(
            static fn (int $id): string|false => 7 === $id ? 'https://site.test/crew/' : false
        );

        return $this->captureWithOptions(['eventcrew_signup_page_id' => 7, Roles::GUIDE_URL_OPTION => '']);
    }

    private function webOnlyPerson(?string $language = null): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'language' => $language,
        ]);
    }

    /** A person with no Telegram chat gets the crew page link and a Manage my tasks button, not the bot. */
    public function testAWebOnlyPersonGetsTheCrewPageInsteadOfTheBot(): void
    {
        $sends = $this->captureWithCrewPage();

        $this->signUp($this->webOnlyPerson());

        self::assertSame([], $sends->items);
        $body = $this->mails[0]['body'];
        self::assertStringContainsString(
            "Can't make it? Cancel the task under My tasks in the Me tab of the crew page (https://site.test/crew/).",
            $body
        );
        self::assertStringContainsString("\nManage my tasks: https://site.test/crew/?eventcrew_login=", $body);
        self::assertStringNotContainsString('bot', $body);
        self::assertStringNotContainsString('/me', $body);

        $this->mails = [];
        $this->wpdb->nextRows[] = $this->timedTaskRow();
        $this->notifier()->confirmCancellation($this->webOnlyPerson(), 5, 'late_cancel');

        self::assertStringContainsString(
            'This was a late cancellation, which counts against your standing. More notice keeps it clear next time.',
            $this->mails[0]['body']
        );
        self::assertStringNotContainsString('/me', $this->mails[0]['body']);
    }

    /** The same in Finnish. */
    public function testAFinnishWebOnlyPersonGetsTheCrewPageInFinnish(): void
    {
        $this->captureWithCrewPage();

        $this->signUp($this->webOnlyPerson('fi'));

        $body = $this->mails[0]['body'];
        self::assertStringContainsString(
            'Etkö pääsekään? Peru tehtävä kohdassa Tehtäväni crew-sivun Minä-välilehdellä (https://site.test/crew/).',
            $body
        );
        self::assertStringContainsString("\nHallitse tehtäviäni: https://site.test/crew/?eventcrew_login=", $body);
        self::assertStringNotContainsString('botti', $body);
    }

    /** A person with a Telegram chat keeps the bot text, and gets no Manage my tasks button. */
    public function testAPersonWithATelegramChatKeepsTheBotTextWhenThereIsACrewPage(): void
    {
        $this->captureWithCrewPage();

        $this->signUp($this->personInLanguage(null));

        $body = $this->mails[0]['body'];
        self::assertStringContainsString("Can't make it? Open the bot and tap the task to cancel", $body);
        self::assertStringNotContainsString('Manage my tasks', $body);
        self::assertStringNotContainsString('crew page', $body);

        $this->mails = [];
        $this->wpdb->nextRows[] = $this->timedTaskRow();
        $this->notifier()->confirmCancellation($this->personInLanguage(null), 5, 'late_cancel');

        self::assertStringContainsString(
            'so does a replacement: they send /me to the bot and tap 🔁 Take over a task, and you accept.',
            $this->mails[0]['body']
        );
    }
}
