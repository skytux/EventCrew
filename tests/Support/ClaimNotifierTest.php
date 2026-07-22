<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Tests\TestCase;

/**
 * The shared confirmation emails both channels send. Light coverage: a signup
 * mails a ticket, and a switched-off account is never mailed.
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
            $this->mails[] = ['to' => $to, 'body' => $body];

            return true;
        });
    }

    private function notifier(): ClaimNotifier
    {
        return new ClaimNotifier(new TaskRepository(), new AssignmentRepository(), new Mailer(new Logger()));
    }

    private function person(bool $disabled = false): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'disabled_at' => $disabled ? '2026-07-10 09:00:00' : null,
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
    }

    public function testADisabledAccountIsNeverMailed(): void
    {
        $this->notifier()->confirmSignup($this->person(true), 5);

        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->nextRows); // returned before any read
    }
}
