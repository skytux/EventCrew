<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\MailImport\Mailbox;
use EventCrew\MailImport\MailImporter;
use EventCrew\MailImport\MailedListImport;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\DoorList;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Web\CrewAdminRoster;

/**
 * What the tests of the private-chat commands (PersonCommands,
 * NightCommands, TellCommand, MemberCommands) share: transients kept in
 * memory, people and tasks as rows, taps, and the buttons last sent.
 * The organizer is person 1, Telegram user 555.
 */
trait CommandTestHelpers
{
    /** @var array<string, mixed> */
    private array $transients = [];

    private function setUpCommandTest(): void
    {
        $this->transients = [];
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function person(int $id, string $name, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'email' => 'p' . $id . '@example.com',
            'display_name' => $name,
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => 0,
            'telegram_user_id' => 600 + $id,
            'telegram_chat_id' => 600 + $id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizer(): array
    {
        return $this->person(1, 'Owner Organizer', ['is_organizer' => 1, 'telegram_user_id' => 555, 'telegram_chat_id' => 555]);
    }

    /**
     * @return array<string, mixed>
     */
    private function task(int $id, string $date = '2026-07-18'): array
    {
        return [
            'id' => $id,
            'task_date' => $date,
            'role_slug' => 'bar',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tap(string $data, int $from = 555): array
    {
        return ['id' => 'cbq', 'from' => ['id' => $from], 'data' => $data, 'message' => ['chat' => ['id' => $from], 'message_id' => 3]];
    }

    /**
     * @return array<int, string>
     */
    private function buttonData(): array
    {
        $keyboard = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'] ?? [];

        return array_column(array_merge(...$keyboard), 'callback_data');
    }

    private function lastText(): string
    {
        return (string) ($this->lastCallTo('sendMessage')['text'] ?? '');
    }

    private function requestId(string $prefix = 'pc:'): string
    {
        preg_match('/^' . preg_quote($prefix, '/') . '\w:([0-9a-f]{16})/', $this->buttonData()[0] ?? '', $m);

        return $m[1] ?? '';
    }

    /**
     * The crew page's Roster screen over the fake wpdb, with an empty mailbox.
     */
    private function roster(): CrewAdminRoster
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $tasks = new TaskRepository();
        $assignments = new AssignmentRepository();
        $buyers = new TicketBuyerRepository();
        $buyerImport = new BuyerImport($buyers, new DoorAccess($tasks, $assignments));
        $mailbox = new class implements Mailbox {
            public function unread(int $limit): array
            {
                return [];
            }

            public function moveToImported(string $id): void
            {
            }

            public function moveToRejected(string $id): void
            {
            }

            public function close(): void
            {
            }
        };

        return new CrewAdminRoster(
            $tasks,
            $assignments,
            new DoorList($tasks, $assignments, new PersonRepository(), new RedemptionRepository(), $this->standing()),
            new RedemptionRepository(),
            new FreeEntryGate(),
            new TicketRedemptionService(
                new PersonRepository(),
                $tasks,
                new RedemptionRepository(),
                $this->standing(),
                new FreeEntryGate(),
                $this->client(),
                $mailer
            ),
            new GuestRepository(),
            $buyers,
            $buyerImport,
            new MailImporter(
                new MailedListImport(new PersonRepository(), $buyerImport, $mailer, new GuestRepository()),
                new Logger(),
                static fn (): Mailbox => $mailbox
            )
        );
    }
}
