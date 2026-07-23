<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\StandingNotice;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

/**
 * The delayed no-show / late-cancel notice. Light coverage: a due no-show is
 * told on both channels and recorded, so the next run won't repeat it.
 */
final class StandingNoticeTest extends TestCase
{
    /** @var array<int, string> */
    private array $mails = [];

    /** @var array<int, array<string, mixed>> */
    private array $sends = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $this->sends = [];

        Functions\when('get_option')->alias(
            fn (string $name): mixed => TelegramClient::TOKEN_OPTION === $name ? 'BOT:TOKEN'
                : ('eventcrew_link_secret' === $name ? 'test-secret' : false)
        );
        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $d): string => (string) json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): string {
            $this->sends[] = (array) json_decode((string) $args['body'], true);

            return (string) json_encode(['ok' => true, 'result' => true]);
        });
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = $body;

            return true;
        });
    }

    private function service(): StandingNotice
    {
        $ledger = new NotificationsRepository();

        return new StandingNotice(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            $ledger,
            new TelegramClient(new Logger(), new DohResolver(new Logger())),
            new Mailer(new Logger())
        );
    }

    public function testADueNoShowIsToldOnBothChannelsAndRecorded(): void
    {
        // needingStandingNotice -> one no-show marked more than a day ago.
        $this->wpdb->nextResults[] = [
            [
                'id' => 11,
                'task_id' => 5,
                'person_id' => 7,
                'status' => 'no_show',
                'status_changed_at' => '2026-07-18 09:00:00',
                'task_date' => '2026-07-17',
            ],
        ];
        // tasks->find(5)
        $this->wpdb->nextRows[] = [
            'id' => 5,
            'task_date' => '2026-07-17',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_label' => 'Party',
        ];
        // people->find(7) — linked to Telegram, active.
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_chat_id' => 4242,
        ];

        $sent = $this->service()->sendDue(25);

        self::assertSame(1, $sent);
        self::assertCount(1, $this->sends);
        self::assertSame(4242, $this->sends[0]['chat_id']);
        self::assertStringContainsString('no-show', (string) $this->sends[0]['text']);
        self::assertCount(1, $this->mails);
        // The ledger row that stops the next run repeating it.
        self::assertSame('standing_hit', $this->wpdb->inserts[0]['data']['kind']);
    }
}
