<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\Turnstile;
use EventCrew\Telegram\SubscribeCommand;
use EventCrew\Web\NewsletterController;

/** /subscribe: an organizer adds someone to the newsletter, who confirms by email. */
final class SubscribeCommandTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('is_email')->alias(
            static fn (string $email): string|false => false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false
        );
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function command(): SubscribeCommand
    {
        $logger = new Logger();
        $mailer = new Mailer($logger, new EmailTemplate($logger));

        return new SubscribeCommand(
            new PersonRepository(),
            new NewsletterController(
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
            ),
            $this->client()
        );
    }

    private function queuePerson(bool $organizer): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'org@example.test',
            'display_name' => 'Org',
            'telegram_user_id' => 555,
            'is_organizer' => $organizer ? 1 : 0,
        ];
    }

    /** @return array<string, mixed> the subscriber row written (not the history count) */
    private function subscriberInsert(): array
    {
        $rows = array_values(array_filter(
            array_column($this->wpdb->inserts, 'data'),
            static fn (array $data): bool => isset($data['email'])
        ));
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function lastReply(): string
    {
        $last = end($this->telegramCalls);

        return false === $last ? '' : (string) ($last['body']['text'] ?? '');
    }

    public function testItIsRefusedInAGroup(): void
    {
        $this->command()->onCommand(555, -100, false, 'a@example.test');

        self::assertSame('Use /subscribe in a private chat with me.', $this->lastReply());
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAMemberIsRefused(): void
    {
        $this->queuePerson(false);

        $this->command()->onCommand(555, 555, true, 'a@example.test');

        self::assertSame('Only organizers can use /subscribe.', $this->lastReply());
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testNoAddressShowsHowToUseIt(): void
    {
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, '');

        self::assertStringContainsString('/subscribe name@example.com', $this->lastReply());
    }

    public function testAnUnknownLanguageShowsHowToUseIt(): void
    {
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, 'a@example.test xx');

        self::assertStringContainsString('/subscribe name@example.com', $this->lastReply());
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testSomethingThatIsNotAnAddressIsRefused(): void
    {
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, 'milla');

        self::assertSame('“milla” is not an email address.', $this->lastReply());
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAnAddressAlreadyOnTheListGetsNoEmail(): void
    {
        $this->queuePerson(true);
        $this->wpdb->nextRows[] = [
            'id' => 3,
            'email' => 'a@example.test',
            'status' => NewsletterSubscriberRepository::STATUS_CONFIRMED,
            'created_at' => '2026-07-01 10:00:00',
        ];

        $this->command()->onCommand(555, 555, true, 'a@example.test');

        self::assertSame('a@example.test is already on the newsletter.', $this->lastReply());
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testANewAddressIsPendingAndGetsTheConfirmation(): void
    {
        $this->queuePerson(true);
        $this->wpdb->insert_id = 12;

        $this->command()->onCommand(555, 555, true, 'New@Example.test en');

        $row = $this->subscriberInsert();
        self::assertSame('new@example.test', $row['email']);
        self::assertSame(NewsletterSubscriberRepository::STATUS_PENDING, $row['status']);
        self::assertStringStartsWith('A confirmation email went to New@Example.test.', $this->lastReply());
    }
}
