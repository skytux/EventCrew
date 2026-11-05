<?php

declare(strict_types=1);

namespace EventCrew\Tests\MailImport;

use Brain\Monkey\Functions;
use EventCrew\MailImport\Mailbox;
use EventCrew\MailImport\MailboxException;
use EventCrew\MailImport\MailFetchSettings;
use EventCrew\MailImport\MailImporter;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Tests\Support\TinyXlsx;
use EventCrew\Tests\TestCase;
use EventCrew\Web\BuyerImportController;

/**
 * One run of the site's own mailbox reader, against a fake mailbox. "Today"
 * is 2026-07-20 (the stubbed current_time).
 */
final class MailImporterTest extends TestCase
{
    private const AUTHSERV = 'mx.example.org';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, string> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            EmailTemplate::HTML_OPTION => false,
            MailFetchSettings::OPTION => ['protocol' => 'jmap', 'authserv_id' => self::AUTHSERV],
        ];
        $this->mails = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, mixed $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $data): string => (string) json_encode($data));
        Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-admin/' . $p);
        Functions\when('get_permalink')->justReturn(false);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to): bool {
            $this->mails[] = $to;

            return true;
        });
    }

    private static function mail(string $from, string $auth, string $body = "Anna\nBo\n"): string
    {
        return ('' === $auth ? '' : "Authentication-Results: {$auth}\n")
            . "From: {$from}\nMessage-ID: <{$from}>\nContent-Type: text/plain\n\n{$body}";
    }

    /**
     * @param array<string, string> $messages
     */
    private function fakeMailbox(array $messages, bool $fails = false): Mailbox
    {
        return new class ($messages, $fails) implements Mailbox {
            /** @var array<int, string> */
            public array $moves = [];

            public bool $closed = false;

            /**
             * @param array<string, string> $messages
             */
            public function __construct(private array $messages, private bool $fails)
            {
            }

            public function unread(int $limit): array
            {
                if ($this->fails) {
                    throw new MailboxException('Could not reach mail.example.org: timed out');
                }

                return array_slice($this->messages, 0, $limit, true);
            }

            public function moveToImported(string $id): void
            {
                $this->moves[] = 'imported ' . $id;
            }

            public function moveToRejected(string $id): void
            {
                $this->moves[] = 'rejected ' . $id;
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
    }

    private function importer(Mailbox $mailbox): MailImporter
    {
        $controller = new BuyerImportController(
            new PersonRepository(),
            new BuyerImport(
                new TicketBuyerRepository(),
                new DoorAccess(new TaskRepository(), new AssignmentRepository())
            ),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new GuestRepository()
        );

        return new MailImporter($controller, new Logger(), static fn (): Mailbox => $mailbox);
    }

    public function testEachMessageIsImportedOrRejectedAndFiledAway(): void
    {
        $pass = self::AUTHSERV . '; dmarc=pass header.from=example.com';
        $mailbox = $this->fakeMailbox([
            '11' => self::mail('owner@example.com', $pass),
            // Its passing header names another server: forged, so refused unread.
            '12' => self::mail('owner@example.com', 'evil.example.net; dmarc=pass header.from=example.com'),
            '13' => self::mail('stranger@example.com', $pass),
            '14' => self::mail('owner@example.com', $pass, ''),
        ]);

        // Message 11: an organizer, the next night is 1 Aug, nobody on its guest list yet.
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'owner@example.com', 'display_name' => 'Owner', 'is_organizer' => 1];
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextResults[] = [];
        // Message 13: no such person (nothing queued).

        $status = $this->importer($mailbox)->run();

        self::assertSame(['imported 11', 'rejected 12', 'rejected 13', 'rejected 14'], $mailbox->moves);
        self::assertTrue($mailbox->closed);
        self::assertTrue($status['ok']);
        self::assertSame('1 imported, 3 rejected, 0 left to try again', $status['message']);
        self::assertSame($status, $this->options[MailFetchSettings::STATUS_OPTION]);
        // The organizer got the confirmation; nobody else was mailed.
        self::assertSame(['owner@example.com'], $this->mails);
    }

    public function testWithoutATrustedMailServerEveryMessageIsRefusedAndTheStatusSaysWhy(): void
    {
        $this->options[MailFetchSettings::OPTION] = ['protocol' => 'jmap', 'authserv_id' => ''];
        $pass = self::AUTHSERV . '; dmarc=pass header.from=example.com';
        $mailbox = $this->fakeMailbox(['31' => self::mail('owner@example.com', $pass)]);

        $status = $this->importer($mailbox)->run();

        self::assertSame(['rejected 31'], $mailbox->moves);
        self::assertSame(
            '0 imported, 1 rejected, 0 left to try again (no trusted mail server is set, so every message is refused)',
            $status['message']
        );
        self::assertSame([], $this->mails);
    }

    public function testAnXlsxAttachmentIsImportedOneRowPerTicket(): void
    {
        $pass = self::AUTHSERV . '; dmarc=pass header.from=example.com';
        $mail = "Authentication-Results: {$pass}\nFrom: owner@example.com\nMessage-ID: <x1@example.com>\n"
            . "MIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"b1\"\n\n"
            . "--b1\nContent-Type: text/plain\n\nThe list is attached.\n"
            . "--b1\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\n"
            . "Content-Disposition: attachment; filename=\"Tickets.xlsx\"\nContent-Transfer-Encoding: base64\n\n"
            . chunk_split(base64_encode(TinyXlsx::holvi()), 76, "\n")
            . "--b1--\n";
        $mailbox = $this->fakeMailbox(['21' => $mail]);

        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'owner@example.com', 'display_name' => 'Owner', 'is_organizer' => 1];
        $this->wpdb->nextCols[] = ['2026-08-01'];
        $this->wpdb->nextVars[] = '0';
        $this->wpdb->nextResults[] = [];

        $status = $this->importer($mailbox)->run();

        self::assertSame(['imported 21'], $mailbox->moves);
        self::assertTrue($status['ok']);
        $codes = array_map(static fn (array $insert): mixed => $insert['data']['ticket_code'] ?? null, $this->wpdb->inserts);
        self::assertSame(['A1', 'A1', 'B2'], $codes);
    }

    public function testAMailboxThatCannotBeReachedIsStoredAsAFailedCheck(): void
    {
        $mailbox = $this->fakeMailbox([], true);

        $status = $this->importer($mailbox)->run();

        self::assertFalse($status['ok']);
        self::assertSame('Could not reach mail.example.org: timed out', $status['message']);
        self::assertTrue($mailbox->closed);
    }

    public function testASecondRunWhileOneIsGoingDoesNothing(): void
    {
        $this->wpdb->heldLocks['eventcrew_mail_fetch'] = true;
        $mailbox = $this->fakeMailbox(['1' => self::mail('owner@example.com', '')]);

        self::assertNull($this->importer($mailbox)->run());
        self::assertSame([], $mailbox->moves);
        self::assertArrayNotHasKey(MailFetchSettings::STATUS_OPTION, $this->options);
    }

    public function testItHasItsOwnFiveMinuteSchedule(): void
    {
        $schedules = $this->importer($this->fakeMailbox([]))->addSchedule([]);

        self::assertSame(300, $schedules[MailImporter::SCHEDULE]['interval']);
    }
}
