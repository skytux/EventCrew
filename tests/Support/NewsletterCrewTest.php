<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\NewsletterCrew;
use EventCrew\Tests\TestCase;

final class NewsletterCrewTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });

        // Adding someone to the newsletter never sends an email.
        Functions\expect('wp_mail')->never();
    }

    private function crew(): NewsletterCrew
    {
        return new NewsletterCrew(new NewsletterSubscriberRepository(), new PersonRepository());
    }

    /** @return array<string, mixed> */
    private static function person(int $id, bool $verified = true, string $email = ''): array
    {
        return [
            'id' => $id,
            'email' => '' === $email ? 'P' . $id . '@Example.com' : $email,
            'display_name' => 'Person ' . $id,
            'email_verified_at' => $verified ? '2026-07-01 10:00:00' : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function subscriber(string $email, string $status): array
    {
        return [
            'id' => 9,
            'email' => $email,
            'status' => $status,
            'created_at' => '2026-07-01 10:00:00',
            'confirmed_at' => null,
        ];
    }

    /** @return array<int, string> */
    private function addedEmails(): array
    {
        $subscribers = array_filter(
            $this->wpdb->inserts,
            static fn (array $insert): bool => 'wp_eventcrew_newsletter_subscribers' === $insert['table']
        );

        return array_values(array_map(
            static fn (array $insert): string => (string) $insert['data']['email'],
            $subscribers
        ));
    }

    public function testTheSettingIsOnByDefault(): void
    {
        self::assertTrue(NewsletterCrew::enabled());

        $this->options[NewsletterCrew::OPTION] = '0';
        self::assertFalse(NewsletterCrew::enabled());
    }

    /** A verified person is added as a confirmed subscriber, in lower case. */
    public function testTheBackfillAddsVerifiedCrewAsConfirmedSubscribers(): void
    {
        $this->wpdb->nextResults[] = [self::person(1), self::person(2)];

        self::assertSame(2, $this->crew()->backfill());

        self::assertSame(['p1@example.com', 'p2@example.com'], $this->addedEmails());
        self::assertSame(
            [
                'email' => 'p1@example.com',
                'status' => NewsletterSubscriberRepository::STATUS_CONFIRMED,
                'created_at' => '2026-07-20 12:00:00',
                'confirmed_at' => '2026-07-20 12:00:00',
            ],
            $this->wpdb->inserts[0]['data']
        );
        self::assertStringContainsString('email_verified_at IS NOT NULL', $this->wpdb->queries[0]);
    }

    public function testTheBackfillSkipsUnverifiedAndPlaceholderAddresses(): void
    {
        $this->wpdb->nextResults[] = [
            self::person(1, false),
            self::person(2, true, 'added-2@added.invalid'),
            self::person(3),
        ];

        self::assertSame(1, $this->crew()->backfill());
        self::assertSame(['p3@example.com'], $this->addedEmails());
    }

    /** Someone who left the newsletter is never put back. */
    public function testTheBackfillSkipsAnAddressThatUnsubscribed(): void
    {
        $this->wpdb->nextResults[] = [self::person(1)];
        $this->wpdb->nextRows[] = self::subscriber(
            'p1@example.com',
            NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED
        );

        self::assertSame(0, $this->crew()->backfill());
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
    }

    /** A pending or confirmed sign-up is left as it is. */
    public function testTheBackfillSkipsAddressesThatAlreadyHaveARow(): void
    {
        $this->wpdb->nextResults[] = [self::person(1), self::person(2), self::person(3)];
        $this->wpdb->nextRows[] = self::subscriber('p1@example.com', NewsletterSubscriberRepository::STATUS_PENDING);
        $this->wpdb->nextRows[] = self::subscriber('p2@example.com', NewsletterSubscriberRepository::STATUS_CONFIRMED);
        $this->wpdb->nextRows[] = null;

        self::assertSame(1, $this->crew()->backfill());
        self::assertSame(['p3@example.com'], $this->addedEmails());
        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheBackfillAddsNobodyWhileTheSettingIsOff(): void
    {
        $this->options[NewsletterCrew::OPTION] = '0';
        $this->wpdb->nextResults[] = [self::person(1)];

        self::assertSame(0, $this->crew()->backfill());
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->queries);
    }

    /** It runs once per site, and only while the setting is on. */
    public function testMaybeRunRunsOnceAndWaitsWhileTheSettingIsOff(): void
    {
        $this->options[NewsletterCrew::OPTION] = '0';
        $this->wpdb->nextResults[] = [self::person(1)];

        $this->crew()->maybeRun();
        self::assertSame([], $this->wpdb->inserts);
        self::assertArrayNotHasKey(NewsletterCrew::DONE_OPTION, $this->options);

        $this->options[NewsletterCrew::OPTION] = '1';
        $this->crew()->maybeRun();
        self::assertSame(['p1@example.com'], $this->addedEmails());
        self::assertSame('1', $this->options[NewsletterCrew::DONE_OPTION]);

        $this->wpdb->nextResults[] = [self::person(2)];
        $this->crew()->maybeRun();
        self::assertSame(['p1@example.com'], $this->addedEmails());
    }

    public function testVerifyingAnEmailAddsThePerson(): void
    {
        $this->wpdb->nextRows[] = self::person(4);

        $this->crew()->onEmailVerified(4);

        self::assertSame(['p4@example.com'], $this->addedEmails());
        self::assertSame(
            NewsletterSubscriberRepository::STATUS_CONFIRMED,
            $this->wpdb->inserts[0]['data']['status']
        );
    }

    public function testVerifyingAnEmailSkipsAnAddressThatUnsubscribed(): void
    {
        $this->wpdb->nextRows[] = self::person(4);
        $this->wpdb->nextRows[] = self::subscriber(
            'p4@example.com',
            NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED
        );

        $this->crew()->onEmailVerified(4);

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testVerifyingAnEmailAddsNobodyWhileTheSettingIsOff(): void
    {
        $this->options[NewsletterCrew::OPTION] = '0';
        $this->wpdb->nextRows[] = self::person(4);

        $this->crew()->onEmailVerified(4);

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testTheHookListensForVerifiedEmails(): void
    {
        $crew = $this->crew();

        $crew->boot();

        self::assertNotFalse(has_action('eventcrew/email_verified', [$crew, 'onEmailVerified']));
    }
}
