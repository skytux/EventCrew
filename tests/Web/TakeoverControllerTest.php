<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\SignedLink;
use EventCrew\Tests\Telegram\TelegramTestCase;
use EventCrew\Web\TakeoverController;

/**
 * The email answer page: opening the link only shows the request and two
 * buttons (a mail scanner must not answer for the holder); only a POST
 * answers.
 */
final class TakeoverControllerTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function controller(): TakeoverController
    {
        return new TakeoverController($this->takeoverService());
    }

    /** @return array<string, mixed> */
    private static function takeoverRow(string $status = 'pending'): array
    {
        return [
            'id' => 1, 'assignment_id' => 3, 'task_id' => 5, 'holder_id' => 9, 'cover_id' => 7,
            'status' => $status, 'deadline_at' => '2026-07-23 12:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private static function personRow(int $id, ?string $language = null): array
    {
        return [
            'id' => $id,
            'email' => sprintf('p%d@example.com', $id),
            'display_name' => 'Person ' . $id,
            'email_verified_at' => '2026-07-01 00:00:00',
            'language' => $language,
        ];
    }

    /** @return array<string, mixed> */
    private static function taskRow(): array
    {
        return [
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'decorate', 'capacity' => 2,
            'event_post_id' => null, 'event_label' => 'Ecstatic Dance',
            'starts_at' => '2026-08-01 20:00:00', 'ends_at' => null,
        ];
    }

    public function testABadTokenIsNotFound(): void
    {
        $page = $this->controller()->respond('POST', 'nonsense', 'yes');

        self::assertSame(404, $page['status']);
        self::assertStringContainsString('This link is no longer valid.', $page['html']);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testATokenSignedForSomethingElseIsNotFound(): void
    {
        $page = $this->controller()->respond('POST', SignedLink::sign('ticket', 1), 'yes');

        self::assertSame(404, $page['status']);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testOpeningTheLinkOnlyShowsTheRequestAndTwoButtons(): void
    {
        $token = SignedLink::sign('takeover', 1);
        $this->wpdb->nextRows[] = self::takeoverRow();
        $this->wpdb->nextRows[] = self::personRow(9);    // holder
        $this->wpdb->nextRows[] = self::taskRow();       // summary: task
        $this->wpdb->nextRows[] = self::personRow(7);    // summary: cover

        // Even a GET that carries an answer does not answer.
        $page = $this->controller()->respond('GET', $token, 'yes');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Person 7 asks to take over your task', $page['html']);
        self::assertStringContainsString('Thu 23 Jul 12:00', $page['html']);
        self::assertSame(2, substr_count($page['html'], '<form method="post"'));
        self::assertStringContainsString('name="answer" value="yes"', $page['html']);
        self::assertStringContainsString('name="answer" value="no"', $page['html']);
        self::assertStringContainsString('value="' . $token . '"', $page['html']);
        self::assertSame([], $this->wpdb->updates);
        foreach ($this->wpdb->queries as $sql) {
            self::assertStringStartsWith('SELECT', ltrim($sql), 'a GET only reads');
        }
    }

    public function testThePageIsInTheHoldersLanguage(): void
    {
        $this->wpdb->nextRows[] = self::takeoverRow();
        $this->wpdb->nextRows[] = self::personRow(9, 'fi');
        $this->wpdb->nextRows[] = self::taskRow();
        $this->wpdb->nextRows[] = self::personRow(7);

        $page = $this->controller()->respond('GET', SignedLink::sign('takeover', 1), '');

        self::assertStringContainsString('Person 7 pyytää', $page['html']);
    }

    public function testPressingKeepAnswersTheRequest(): void
    {
        $this->wpdb->nextRows[] = self::takeoverRow();     // controller: find
        $this->wpdb->nextRows[] = self::personRow(9);      // controller: holder
        $this->wpdb->nextRows[] = self::takeoverRow();     // answer(): find
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP];
        $this->wpdb->nextRows[] = self::taskRow();
        $this->wpdb->nextRows[] = self::personRow(7);
        $this->wpdb->nextRows[] = self::personRow(9);
        $this->wpdb->nextQueryResults[] = 1;               // resolve(refused)

        $page = $this->controller()->respond('POST', SignedLink::sign('takeover', 1), 'no');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('You keep your task', $page['html']);
        self::assertStringNotContainsString('<form', $page['html']);
        self::assertStringContainsString("SET status = 'refused'", implode("\n", $this->wpdb->queries));
    }

    public function testAClosedRequestShowsNoButtons(): void
    {
        $this->wpdb->nextRows[] = self::takeoverRow('expired');
        $this->wpdb->nextRows[] = self::personRow(9);

        $page = $this->controller()->respond('GET', SignedLink::sign('takeover', 1), '');

        self::assertStringContainsString('The time to answer has passed, so you keep your task.', $page['html']);
        self::assertStringNotContainsString('<form', $page['html']);
    }
}
