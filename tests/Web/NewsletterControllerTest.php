<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\SignedLink;
use EventCrew\Support\Turnstile;
use EventCrew\Tests\TestCase;
use EventCrew\Web\NewsletterController;

/**
 * The sign-up form and the signed links. wp_mail records each message;
 * nothing is sent. TestCase pins now to 2026-07-20 12:00:00.
 */
final class NewsletterControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->mails = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('is_email')->alias(
            static fn (string $email): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[a-z]+$/', $email)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    private function controller(): NewsletterController
    {
        $logger = new Logger();
        $mailer = new Mailer($logger, new EmailTemplate($logger));

        return new NewsletterController(
            new NewsletterSubscriberRepository(),
            $mailer,
            new Newsletter(
                new NewsletterSubscriberRepository(),
                new PersonRepository(),
                new NewsletterText(new LlmClient($logger), $logger),
                $mailer,
                $logger
            ),
            new Turnstile($logger)
        );
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $status, string $createdAt = '2026-07-19 10:00:00'): array
    {
        return [
            'id' => $id,
            'email' => 'fan@example.test',
            'status' => $status,
            'created_at' => $createdAt,
            'confirmed_at' => null,
        ];
    }

    /** A new address gets a pending row and one confirmation email with the signed link. */
    public function testANewAddressGetsTheConfirmationEmail(): void
    {
        $result = $this->controller()->join('Fan@Example.test', '', '');

        self::assertSame(NewsletterController::RESULT_SENT, $result);
        self::assertSame('pending', $this->wpdb->inserts[0]['data']['status']);
        self::assertCount(1, $this->mails);
        self::assertSame('fan@example.test', $this->mails[0]['to']);
        self::assertStringContainsString('do=confirm', $this->mails[0]['body']);
        self::assertStringContainsString('Vahvista', $this->mails[0]['subject']);
    }

    /** A filled honeypot looks like success to the bot and does nothing. */
    public function testTheHoneypotDoesNothing(): void
    {
        $result = $this->controller()->join('fan@example.test', 'https://spam.example', '');

        self::assertSame(NewsletterController::RESULT_SENT, $result);
        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->mails);
    }

    public function testAnInvalidAddressIsRefused(): void
    {
        self::assertSame(NewsletterController::RESULT_INVALID, $this->controller()->join('not an address', '', ''));
        self::assertSame([], $this->mails);
    }

    /** With Turnstile set up, a missing token is refused before anything is stored. */
    public function testTurnstileIsCheckedWhenItIsSetUp(): void
    {
        $this->options[Turnstile::SITE_KEY_OPTION] = 'site-key';
        $this->options[Turnstile::SECRET_KEY_OPTION] = 'secret';

        self::assertSame(NewsletterController::RESULT_CAPTCHA, $this->controller()->join('fan@example.test', '', ''));
        self::assertSame([], $this->wpdb->queries);
    }

    /** A pending sign-up less than an hour old gets no second email. */
    public function testNoSecondEmailWithinAnHour(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_PENDING, '2026-07-20 11:30:00');

        self::assertSame(NewsletterController::RESULT_SENT, $this->controller()->join('fan@example.test', '', ''));
        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->updates);
    }

    /** A confirmed address answers the same and gets no email. */
    public function testAConfirmedAddressGetsNoEmail(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        self::assertSame(NewsletterController::RESULT_SENT, $this->controller()->join('fan@example.test', '', ''));
        self::assertSame([], $this->mails);
    }

    public function testTheConfirmLinkConfirms(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_PENDING);

        $page = $this->controller()->respond('confirm', 'GET', SignedLink::sign(Newsletter::PURPOSE_CONFIRM, 5));

        self::assertSame(200, $page['status']);
        self::assertSame('confirmed', $this->wpdb->updates[0]['data']['status']);
    }

    /** A token signed for another purpose is not accepted. */
    public function testAnUnsubscribeTokenDoesNotConfirm(): void
    {
        $page = $this->controller()->respond('confirm', 'GET', SignedLink::sign(Newsletter::PURPOSE_UNSUBSCRIBE, 5));

        self::assertSame(404, $page['status']);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheUnsubscribeLinkWorksByGetAndByPost(): void
    {
        $token = SignedLink::sign(Newsletter::PURPOSE_UNSUBSCRIBE, 5);

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        self::assertSame(200, $this->controller()->respond('unsubscribe', 'GET', $token)['status']);

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        self::assertSame(200, $this->controller()->respond('unsubscribe', 'POST', $token)['status']);

        self::assertCount(2, $this->wpdb->updates);
        self::assertSame('unsubscribed', $this->wpdb->updates[1]['data']['status']);
    }

    /** Opening the stop link only shows the button; the POST stops. */
    public function testTheStopLinkNeedsThePost(): void
    {
        $this->options[Newsletter::STATE_OPTION] = [
            'ledger' => [],
            'plan' => [
                'id' => 3,
                'status' => Newsletter::PLAN_PLANNED,
                'send_on' => '2026-07-21',
                'events' => [['id' => 1, 'date' => '2026-07-27']],
            ],
            'last' => null,
            'next_id' => 4,
        ];
        $token = SignedLink::sign(Newsletter::PURPOSE_STOP, 3);

        $page = $this->controller()->respond('stop', 'GET', $token);
        self::assertStringContainsString('<form method="post"', $page['html']);
        self::assertNotNull($this->options[Newsletter::STATE_OPTION]['plan']);

        $page = $this->controller()->respond('stop', 'POST', $token);
        self::assertStringContainsString('The newsletter is stopped.', $page['html']);
        self::assertNull($this->options[Newsletter::STATE_OPTION]['plan']);
    }
}
