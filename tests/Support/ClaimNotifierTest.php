<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\ExtraTaskCredits;
use EventCrew\Support\Language;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\NewsletterWriter;
use EventCrew\Support\Roles;
use EventCrew\Support\SecondThoughts;
use EventCrew\Support\ShortCode;
use EventCrew\Support\SignedLink;
use EventCrew\Support\SignupService;
use EventCrew\Support\SlotFreedNotice;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketQr;
use EventCrew\Telegram\BoardDjText;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\Telegram\FakeEvents;
use EventCrew\Tests\TestCase;
use stdClass;

/**
 * The shared confirmation emails both channels send. Light coverage: a signup
 * mails a ticket (with a calendar hold) and also confirms in the Telegram DM.
 */
final class ClaimNotifierTest extends TestCase
{
    use FakeEvents;

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

    /**
     * Owner, 2026-11-07: one email on sign-up. The sign-up email carries the
     * ticket area with the crew ticket's QR code (EC-C code), short code and
     * link at the bottom; no separate "Your ticket" email follows. The
     * sign-up is marked as having had its ticket emailed.
     */
    public function testASignupGetsOneEmailWithTheTicketInIt(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => EmailTemplate::HTML_OPTION === $name ? '1' : $default
        );
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->notifierWithTicketMail()->confirmSignup($this->person(), 5);

        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        self::assertStringNotContainsString('Your ticket', $this->mails[0]['subject']);
        $body = $this->mails[0]['body'];
        $qr = new TicketQr();
        $data = TicketMail::workCode(7, (string) $this->taskRow()['task_date']);
        self::assertSame(1, substr_count($body, $qr->table($data, $qr->fitCell($data, 150, '', 4))));
        self::assertStringContainsString('Crew ticket', $body);
        self::assertStringContainsString(ShortCode::forPerson(7, (string) $this->taskRow()['task_date']), $body);
        self::assertStringContainsString('token=' . TicketMail::workToken(7, (string) $this->taskRow()['task_date']), $body);
        self::assertStringNotContainsString(Mailer::TICKETS_MARK, $body);
        self::assertStringNotContainsString('{tickets}', $body);
        // The ticket comes after the sign-up text, and the email stays light
        // so the QR code stays dark on white.
        self::assertGreaterThan((int) strpos($body, 'Thank you for joining the crew'), (int) strpos($body, 'Crew ticket'));
        self::assertStringContainsString('content="light only"', $body);
        $marked = array_values(array_filter(
            $this->wpdb->updates,
            static fn (array $u): bool => 'wp_eventcrew_assignments' === $u['table']
        ));
        self::assertSame(['id' => 9], $marked[0]['where']);
        self::assertArrayHasKey('ticket_mailed_at', $marked[0]['data']);
    }

    /**
     * The sign-up task's event: post 55, "Ecstatic Dance" on 1.8.2026 at
     * 21:00 with its picture ($image), venue and DJ, and the short DJ text
     * in English. HTML emails are on unless $html is false: then the email
     * is its plain-text part alone.
     */
    private function pictureEvent(string $image = 'https://site.test/dance.jpg', bool $html = true): void
    {
        $this->fakeEvents();
        $this->event(55, '2026-08-01', 'Ecstatic Dance', [
            '_eventmesh_image_url' => $image,
            '_eventmesh_venue_name' => 'Example Hall Helsinki',
        ]);
        Functions\when('get_the_post_thumbnail_url')->justReturn('');
        Filters\expectApplied(NewsletterWriter::DJ_FILTER)->zeroOrMoreTimes()->andReturnUsing(
            static fn (mixed $value, int $postId): array => 55 === $postId
                ? [['name' => 'Esimerkki', 'description' => 'Plays ecstatic journeys.']]
                : []
        );
        $text = 'Esimerkki takes you on a journey.';
        $options = [
            EmailTemplate::HTML_OPTION => $html ? '1' : '0',
            BoardDjText::OPTION => [55 => [
                'hash' => md5(BoardDjText::source(55)),
                'text' => $text,
                'texts' => [],
                'text_hash' => md5($text),
            ]],
        ];
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );
        $this->wpdb->nextRows[] = ['event_post_id' => 55, 'event_label' => 'Ecstatic Dance'] + $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
    }

    /**
     * Owner, 2026-11-08: the sign-up email, which carries the ticket, shows
     * the event's picture right under its "Show your ticket" button, with the
     * alt text that says who plays, what, when and where, and the DJ text
     * under it, as the ticket email does; the other buttons follow. The
     * plain text has the DJ text right after the ticket link.
     */
    public function testTheSignupEmailShowsTheEventPictureUnderTheTicketButton(): void
    {
        $this->pictureEvent();

        $this->notifierWithTicketMail()->confirmSignup($this->person(), 5);

        self::assertCount(1, $this->mails);
        $body = $this->mails[0]['body'];
        $button = (int) strpos($body, 'Show your ticket</a>');
        $picture = (int) strpos($body, '<img src="https://site.test/dance.jpg"');
        $dj = (int) strpos($body, 'Esimerkki takes you on a journey.</p>');

        self::assertGreaterThan(0, $button);
        self::assertSame(1, substr_count($body, 'dance.jpg'));
        // Nothing between the button and the picture but the button's own end.
        self::assertSame(
            '</td></tr></table><div style="margin:0 0 24px"><div style="margin:26px 0 0">',
            substr($body, $button + 20, $picture - $button - 20)
        );
        self::assertStringContainsString(
            'alt="Esimerkki · Ecstatic Dance · Sat 1.8.2026 21:00 · Example Hall Helsinki"',
            $body
        );
        self::assertGreaterThan($picture, $dj);
        self::assertGreaterThan($dj, (int) strpos($body, 'Add to calendar</a>'));
        self::assertGreaterThan($dj, (int) strpos($body, 'Crew ticket'));

        $this->pictureEvent(html: false);
        $this->notifierWithTicketMail()->confirmSignup($this->person(), 5);
        $text = $this->mails[1]['body'];
        self::assertStringNotContainsString('dance.jpg', $text);
        self::assertMatchesRegularExpression(
            '~Show your ticket: \S+\n\nEsimerkki takes you on a journey\.\n\nAdd to calendar: ~u',
            $text
        );
    }

    /** An event with no picture: no picture block in the sign-up email, no DJ text. */
    public function testWithoutAPictureTheSignupEmailHasNoPictureBlock(): void
    {
        $this->pictureEvent('');

        $this->notifierWithTicketMail()->confirmSignup($this->person(), 5);

        self::assertCount(1, $this->mails);
        self::assertStringNotContainsString('<img src="https://site.test', $this->mails[0]['body']);
        self::assertStringNotContainsString('Esimerkki takes you', $this->mails[0]['body']);

        $this->pictureEvent('', false);
        $this->notifierWithTicketMail()->confirmSignup($this->person(), 5);
        self::assertStringNotContainsString('Esimerkki takes you', $this->mails[1]['body']);
    }

    /**
     * A saved sign-up text from before {tickets} existed still gets the
     * ticket, at the end.
     */
    public function testASavedTextWithoutTheTicketsLineStillGetsTheTicket(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => MessageTemplates::OPTION_PREFIX . 'signup_confirmed' === $name
                ? [MessageTemplates::BODY => self::storedText("Hi {name},\n\nSee you on {when}.")]
                : $default
        );
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->notifierWithTicketMail()->confirmSignup($this->person(), 5);

        self::assertCount(1, $this->mails);
        self::assertStringContainsString('See you on', $this->mails[0]['body']);
        self::assertGreaterThan((int) strpos($this->mails[0]['body'], 'See you on'), (int) strpos($this->mails[0]['body'], 'Crew ticket'));
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
        $this->wpdb->nextResults[] = [$cancelled, $kept];
        $this->wpdb->nextResults[] = [$cancelled, $kept];
        $this->wpdb->nextRows[] = ['role_slug' => 'bar', 'id' => 6] + $this->taskRow(); // tasks->find(6)

        $this->notifierWithTicketMail()->confirmCancellation($this->person(), 5, 'cancelled');

        self::assertCount(2, $this->mails);
        self::assertStringContainsString('Your ticket', $this->mails[1]['subject']);
        self::assertStringNotContainsString('cancelled', $this->mails[1]['subject']);
        self::assertStringContainsString('Your tasks on this night:', $this->mails[1]['body']);
        // The working ticket's code stays the same, so the QR they have keeps working.
        self::assertStringContainsString(
            'token=' . TicketMail::workToken(7, (string) $this->taskRow()['task_date']),
            $this->mails[1]['body']
        );
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

        // Every link is a named button: ticket, calendar, cancel, then the
        // guide. None of them appears as a raw address in the text.
        $text = (string) $sends[0]['text'];
        self::assertStringContainsString('Set up before the dance', $text);
        self::assertStringNotContainsString('docs.example', $text);
        self::assertStringNotContainsString('token=', $text);

        $rows = $sends[0]['reply_markup']['inline_keyboard'];
        self::assertStringContainsString('eventcrew/v1/ticket?token=', $rows[0][0]['url']);
        self::assertStringContainsString('eventcrew/v1/calendar?token=', $rows[1][0]['url']);
        self::assertSame('lc:5', $rows[2][0]['callback_data']);
        self::assertSame('https://docs.example/guide', $rows[3][0]['url']);

        self::assertStringContainsString('Set up before the dance', $this->mails[0]['body']);
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
        self::assertStringContainsString('kiitos, että liityit crew’hun!', $this->mails[0]['body']);
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
        self::assertStringContainsString('Thank you for joining the crew!', $this->mails[0]['body']);
        self::assertStringNotContainsString('liityit', $this->mails[0]['body']);
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

    /**
     * With nothing stored: the when line, the task's description under it,
     * the ticket line; the DM leaves the cancelling to its Cancel button,
     * which asks first and offers the hand-over.
     */
    public function testTheSignupMessagesAreTheSameAsBeforeTheTemplates(): void
    {
        $sends = $this->captureWithOptions();

        $this->signUp($this->personInLanguage(null));

        $about = 'Set up before the dance: close the curtains, check the bathrooms, place the LED'
            . ' candles, decorate the DJ table, prepare the water and fruit, and open the movable walls.';
        self::assertSame(
            "✅ Signed up: Decorate at Party, Sat 1 Aug 18:00–20:00.\n\n" . $about
                . "\n\nShow your ticket at the door – it is the first button below."
                . ' You can cancel without it affecting your standing up to 48 hours before the task.'
                . "\n\nYour standing: Beginner · 0 free-entry credits."
                . "\n\nCan’t make it? Tap Cancel below: there you can also hand the task over to someone else.",
            (string) $sends->items[0]['text']
        );
        self::assertSame('Thank you Sam! ✨ You’re on the crew for Party', $this->mails[0]['subject']);
        self::assertSame(
            "Hi Sam,\n\nThank you for joining the crew! You’re on Decorate at Party, Sat 1 Aug 18:00–20:00."
                . "\n\n" . $about
                . "\n\nShow your ticket at the door. We can’t wait to see you there ✨"
                . "\n\nYour standing: Beginner · 0 free-entry credits.\n\nCan’t make it? Tap Cancel below."
                . ' Or find someone to take it over: they send /me to the bot and tap 🔁 Take over a task,'
                . ' and you accept. You can cancel without it affecting your standing up to 48 hours before the task.'
                . "\n\n"
                . 'Show your ticket: https://site.test/wp-json/eventcrew/v1/ticket?token='
                . TicketMail::workToken(7, '2026-08-01') . "\n"
                . 'Add to calendar: https://site.test/wp-json/eventcrew/v1/calendar?token='
                . SignedLink::sign('calendar', 5) . "\n"
                . '📋 Crew guide: https://docs.example/guide'
                . self::MANAGE,
            $this->mails[0]['body']
        );
    }

    /**
     * The HTML sign-up email opens with the "Thank you" hero (EmailHero);
     * the cancel email, sent through the same Mailer, has none.
     */
    public function testOnlyTheHtmlSignupEmailOpensWithTheThankYouHero(): void
    {
        $this->captureWithOptions([EmailTemplate::HTML_OPTION => '1']);

        $this->signUp($this->personInLanguage(null));

        self::assertStringContainsString('class="ec-shimmer-light"', $this->mails[0]['body']);
        self::assertStringContainsString('>Thank you Sam!</h1>', $this->mails[0]['body']);
        self::assertStringContainsString('Decorate · Sat 1 Aug 18:00–20:00', $this->mails[0]['body']);

        $this->mails = [];
        $this->wpdb->nextRows[] = $this->timedTaskRow();
        $this->notifier()->confirmCancellation($this->personInLanguage(null), 5, 'cancelled');

        self::assertStringNotContainsString('Thank you Sam!', $this->mails[0]['body']);
        self::assertStringNotContainsString('linear-gradient(135deg,#2b1d57', $this->mails[0]['body']);
    }

    /** The same in Finnish, read from the shipped .mo, with no notice hours and no guide. */
    public function testTheFinnishSignupMessagesAreTheSameAsBeforeTheTemplates(): void
    {
        $sends = $this->captureWithOptions([SignupService::NOTICE_HOURS_OPTION => 0, Roles::GUIDE_URL_OPTION => '']);

        $this->signUp($this->personInLanguage('fi'));

        $about = 'Valmistelu ennen tansseja: sulje verhot, tarkista vessat, aseta LED-kynttilät,'
            . ' somista DJ-pöytä, valmistele vesi ja hedelmät ja avaa siirtoseinät.';
        self::assertSame(
            "✅ Ilmoittauduttu: Somistus, Party, la 1.8. 18:00–20:00.\n\n" . $about
                . "\n\nNäytä lippusi ovella – se on alla ensimmäisenä painikkeena."
                . "\n\nLuotettavuutesi: Aloittelija · 0 vapaalippua."
                . "\n\nEtkö pääsekään? Napauta alta Peru: siellä voit myös antaa tehtävän jollekulle toiselle.",
            (string) $sends->items[0]['text'],
            'a notice of 0 hours leaves out the free-cancel sentence'
        );
        self::assertSame('Kiitos Sam! ✨ Olet mukana crew’ssä: Party', $this->mails[0]['subject']);
        self::assertStringStartsWith(
            "Hei Sam,\n\nkiitos, että liityit crew’hun! Olet tehtävässä Somistus, Party, la 1.8. 18:00–20:00."
                . "\n\n" . $about
                . "\n\nNäytä lippusi ovella. Odotamme jo innolla näkemistäsi ✨"
                . "\n\nLuotettavuutesi: Aloittelija · 0 vapaalippua.\n\nEtkö pääsekään? Napauta alta Peru."
                . ' Tai etsi joku ottamaan se: hän lähettää botille /me ja napauttaa'
                . ' 🔁 Ota tehtävä, ja sinä hyväksyt.' . "\n\nNäytä lippusi: ",
            $this->mails[0]['body']
        );
    }

    /**
     * With nothing stored, both cancellation DMs and emails are what they
     * were before the templates, but the standing line only after a late
     * cancellation: nothing else changes the standing.
     */
    public function testTheCancelMessagesAreTheSameAsBeforeTheTemplates(): void
    {
        $sends = $this->captureWithOptions();
        $late = 'Thanks for letting us know. This close to the task it counts against your standing; cancelling 48'
            . ' hours or more before has no effect and gives someone else time to take the place. You can also hand'
            . ' a task over: the other person sends /me to the bot and taps 🔁 Take over a task, and you accept.';
        // Q351: a late cancel says Sign up again undoes it; an early one why it costs nothing.
        $late .= ' Changed your mind? Tap Sign up again while the place is still free: you are back on the task'
            . ' and the late cancellation is removed.';
        $early = 'Thanks for the notice. This is an early cancellation: it is more than 48 hours before the task,'
            . ' so it has no effect on your standing.';
        $standing = 'Your standing: Beginner · 0 free-entry credits.';
        $cases = [
            'cancelled' => ['❌ Cancelled', $early],
            'late_cancel' => ['⚠️ Late cancellation', $late],
        ];

        foreach ($cases as $status => [$kind, $note]) {
            $sends->items = [];
            $this->mails = [];
            $this->wpdb->nextRows[] = $this->timedTaskRow();

            $this->notifier()->confirmCancellation($this->personInLanguage(null), 5, $status);

            $line = 'late_cancel' === $status ? "\n\n" . $standing : '';
            self::assertSame(
                $kind . ': Decorate at Party, Sat 1 Aug 18:00–20:00. Your ticket is now disabled. ' . $note . $line,
                (string) $sends->items[0]['text'],
                $status
            );
            self::assertSame('Cancelled: Decorate for Party', $this->mails[0]['subject'], $status);
            self::assertSame(
                "Hi Sam,\n\nYou’ve cancelled Decorate at Party, Sat 1 Aug 18:00–20:00, and your ticket is now disabled.\n\n"
                    . $note
                    . $line . self::MANAGE,
                $this->mails[0]['body'],
                $status
            );
        }
    }

    /**
     * Q351: a free cancel close to the task (inside the second thoughts
     * window) does not claim to be early; it says the task was just taken.
     */
    public function testAFreeCancelCloseToTheTaskSaysItWasJustTaken(): void
    {
        $sends = $this->captureWithOptions();
        $this->wpdb->nextRows[] = ['starts_at' => '2026-07-21 10:00:00', 'task_date' => '2026-07-21'] + $this->taskRow();

        $this->notifier()->confirmCancellation($this->personInLanguage(null), 5, 'cancelled');

        self::assertStringContainsString(
            'Thanks for the notice. You took this task only minutes ago, so cancelling it has no effect on your standing.',
            (string) $sends->items[0]['text']
        );
        self::assertStringNotContainsString('Your standing', (string) $sends->items[0]['text']);
    }

    /** Q351: the late-cancel note in Finnish says Ilmoittaudu taas undoes it. */
    public function testTheFinnishLateCancelSaysHowToUndoIt(): void
    {
        $sends = $this->captureWithOptions();
        $this->wpdb->nextRows[] = $this->timedTaskRow();

        $this->notifier()->confirmCancellation($this->personInLanguage('fi'), 5, 'late_cancel');

        self::assertStringContainsString(
            'Muutitko mielesi? Napauta Ilmoittaudu taas, kun paikka on vielä vapaa',
            (string) $sends->items[0]['text']
        );
        self::assertStringContainsString('myöhäinen peruutus poistuu.', $this->mails[0]['body']);
    }

    /**
     * A cancellation offers to undo it in one tap ([Sign up again], the
     * join-only j: callback, which runs the board's sign-up with its checks)
     * and lists the event's other open tasks, with the board one tap away.
     */
    public function testACancellationOffersSignUpAgainAndTheOtherOpenTasks(): void
    {
        $sends = $this->captureWithOptions([
            'eventcrew_signup_page_id' => 3,
            'eventcrew_telegram_bot_username' => 'crewbot',
        ]);
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        $other = ['id' => 6, 'role_slug' => 'bar', 'task_date' => '2099-08-01'] + $this->taskRow();
        $this->wpdb->nextRows[] = $this->timedTaskRow();              // tasks->find
        $this->wpdb->nextResults[] = [$this->timedTaskRow(), $other]; // otherOpen: the tasks of the day
        $this->wpdb->nextResults[] = [['task_id' => 6, 'taken' => 1]]; // otherOpen: occupancy

        $notifier = new ClaimNotifier(
            new TaskRepository(),
            new AssignmentRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new TelegramClient(new Logger(), new DohResolver(new Logger())),
            new StandingCalculator(new AssignmentRepository(), new RedemptionRepository(), new CreditGrantRepository()),
            null,
            null,
            new SlotFreedNotice(
                new TaskRepository(),
                new PersonRepository(),
                new Mailer(new Logger(), new EmailTemplate(new Logger())),
                new TelegramClient(new Logger(), new DohResolver(new Logger()))
            )
        );
        $notifier->confirmCancellation($this->personInLanguage(null), 5, 'cancelled');

        $dm = $sends->items[0];
        self::assertStringStartsWith(
            "❌ Cancelled: Decorate at Party, Sat 1 Aug 18:00–20:00. Your ticket is now disabled. Thanks for the notice. This is an early cancellation: it is more than 48"
                . " hours before the task, so it has no effect on your standing.\n\nOther open tasks at Party:\n📅 Sat 1 Aug\n\n🔹 ",
            (string) $dm['text']
        );
        self::assertStringNotContainsString('Your standing', (string) $dm['text']);
        $buttons = array_merge(...$dm['reply_markup']['inline_keyboard']);
        self::assertSame('↩️ Sign up again', $buttons[0]['text']);
        self::assertSame('j:5', $buttons[0]['callback_data']);
        self::assertStringContainsString('See open tasks', $buttons[1]['text']);

        $body = $this->mails[0]['body'];
        self::assertStringContainsString("so it has no effect on your standing.\n\nOther open tasks at Party:\n📅 Sat 1 Aug\n\n🔹 ", $body);
        self::assertStringContainsString('Sign up again: https://site.test/crew/#', $body);
        self::assertStringContainsString('https://t.me/crewbot?start=task-5', $body);
        self::assertStringContainsString('See open tasks: https://site.test/crew/', $body);
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
        self::assertStringStartsWith('Sam: Thanks for letting us know. This close to the task', $this->mails[0]['body']);
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
            "Can’t make it? Tap Cancel on your task in the Board tab of the crew page (https://site.test/crew/).",
            $body
        );
        self::assertStringContainsString("\nManage my tasks: https://site.test/crew/?eventcrew_login=", $body);
        self::assertStringNotContainsString('bot', $body);
        self::assertStringNotContainsString('/me', $body);

        $this->mails = [];
        $this->wpdb->nextRows[] = $this->timedTaskRow();
        $this->notifier()->confirmCancellation($this->webOnlyPerson(), 5, 'late_cancel');

        self::assertStringContainsString(
            'Thanks for letting us know. This close to the task it counts against your standing; cancelling 48 hours'
                . ' or more before has no effect and gives someone else time to take the place. Changed your mind?',
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
            'Etkö pääsekään? Napauta Peru tehtävässäsi crew-sivun Taulu-välilehdellä (https://site.test/crew/).',
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
        self::assertStringContainsString("Can’t make it? Tap Cancel below.", $body);
        self::assertStringNotContainsString('Manage my tasks', $body);
        self::assertStringNotContainsString('crew page', $body);

        $this->mails = [];
        $this->wpdb->nextRows[] = $this->timedTaskRow();
        $this->notifier()->confirmCancellation($this->personInLanguage(null), 5, 'late_cancel');

        self::assertStringContainsString(
            'You can also hand a task over: the other person sends /me to the bot and taps 🔁 Take over a task, and you accept.',
            $this->mails[0]['body']
        );
    }

    // --- the second thoughts window -------------------------------------------

    /** @return array<string, mixed> */
    private function personRow(): array
    {
        return ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam', 'email_verified_at' => '2026-07-01 00:00:00'];
    }

    public function testATakeInsideTheWindowOpensItAndSendsNothingYet(): void
    {
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);
        $scheduled = [];
        Functions\when('wp_schedule_single_event')->alias(
            static function (int $time, string $hook, array $args) use (&$scheduled): bool {
                $scheduled[] = [$hook, $args];

                return true;
            }
        );

        $this->notifier()->signedUp($this->person(), 5);

        self::assertSame([], $this->mails);
        // The default is 5 minutes; the event remembers they did not hold the task.
        self::assertSame([[SecondThoughts::HOOK, [7, 5, 0]]], $scheduled);
    }

    public function testACancelWhileTheWindowIsOpenAddsNoSecondEvent(): void
    {
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);
        Functions\when('wp_next_scheduled')->alias(
            static fn (string $hook, array $args): int|false => [7, 5, 0] === $args ? 1784548800 : false
        );
        Functions\expect('wp_schedule_single_event')->never();

        $this->notifier()->cancelled($this->person(), 5, AssignmentStatus::LATE_CANCEL);

        self::assertSame([], $this->mails);
    }

    public function testWithTheWindowOffTheConfirmationGoesAtOnce(): void
    {
        // get_option answers false: 0 minutes.
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->notifier()->signedUp($this->person(), 5);

        self::assertCount(1, $this->mails);
    }

    public function testTakeThenCancelInsideTheWindowSendsNothing(): void
    {
        $this->wpdb->nextRows[] = $this->personRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::CANCELLED];

        $this->notifier()->afterSecondThoughts(7, 5, 0);

        self::assertSame([], $this->mails);
    }

    public function testCancelThenTakeAgainInsideTheWindowSendsNothing(): void
    {
        $this->wpdb->nextRows[] = $this->personRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP];

        $this->notifier()->afterSecondThoughts(7, 5, 1);

        self::assertSame([], $this->mails);
    }

    public function testATakeThatStandsIsConfirmedWhenTheWindowEnds(): void
    {
        $this->wpdb->nextRows[] = $this->personRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP];
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP];

        $this->notifier()->afterSecondThoughts(7, 5, 0);

        self::assertCount(1, $this->mails);
        self::assertStringContainsString('wp-json/eventcrew/v1/ticket?token=', $this->mails[0]['body']);
    }

    public function testACancelThatStandsIsConfirmedWhenTheWindowEnds(): void
    {
        $freed = [];
        Functions\when('do_action')->alias(static function (string $hook) use (&$freed): void {
            $freed[] = $hook;
        });
        $this->wpdb->nextRows[] = $this->personRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::LATE_CANCEL];
        $this->wpdb->nextRows[] = $this->taskRow();

        $this->notifier()->afterSecondThoughts(7, 5, 1);

        self::assertCount(1, $this->mails);
        // The "a spot opened" call waited for the window too.
        self::assertSame(['eventcrew/slot_freed'], $freed);
    }

    private function notifierWithExtraCredits(): ClaimNotifier
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => ExtraTaskCredits::OPTION === $name ? '1' : false
        );

        return new ClaimNotifier(
            new TaskRepository(),
            new AssignmentRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new TelegramClient(new Logger(), new DohResolver(new Logger())),
            new StandingCalculator(new AssignmentRepository(), new RedemptionRepository(), new CreditGrantRepository()),
            null,
            null,
            null,
            new ExtraTaskCredits()
        );
    }

    /** A second task on the same night: the sign-up says a credit is pending. */
    public function testASecondTaskOnTheSameNightSaysACreditIsPending(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        // pendingExtraTasks for the night: one.
        $this->wpdb->nextVars[] = '1';

        $this->notifierWithExtraCredits()->confirmSignup($this->person(), 5);

        self::assertStringContainsString(
            'This is an extra task on the same night: 1 free-entry credit is pending. '
            . 'It is added after the event if you do the task.',
            $this->mails[0]['body']
        );
        $pendingQuery = implode("\n", array_filter(
            $this->wpdb->queries,
            static fn (string $q): bool => str_contains($q, 'GREATEST(0, n.held - 1 - n.added)')
        ));
        self::assertStringContainsString("s.task_date >= '2026-08-01' AND s.task_date <= '2026-08-01'", $pendingQuery);
    }

    /** One task that night: nothing pending, no line. */
    public function testAFirstTaskOnANightSaysNothingAboutPendingCredits(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextVars[] = '0';

        $this->notifierWithExtraCredits()->confirmSignup($this->person(), 5);

        self::assertStringNotContainsString('pending', $this->mails[0]['body']);
    }

    /** Cancelling one of two tasks that night: the pending credit is gone. */
    public function testCancellingAnExtraTaskSaysItsPendingCreditIsGone(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow();
        // holdsTaskOn: another task is still held that night.
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled'],
            ['id' => 10, 'task_id' => 6, 'person_id' => 7, 'status' => 'signed_up'],
        ];

        $this->notifierWithExtraCredits()->confirmCancellation($this->person(), 5, 'cancelled');

        self::assertStringContainsString(
            'The pending free-entry credit for an extra task that night is gone.',
            $this->mails[0]['body']
        );
    }

    /** Cancelling the only task that night: nothing was pending. */
    public function testCancellingTheOnlyTaskSaysNothingAboutPendingCredits(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextResults[] = [['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled']];

        $this->notifierWithExtraCredits()->confirmCancellation($this->person(), 5, 'cancelled');

        self::assertStringNotContainsString('pending', $this->mails[0]['body']);
    }
}
