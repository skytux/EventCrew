<?php

declare(strict_types=1);

namespace EventCrew\Tests\MailImport;

use EventCrew\MailImport\ImapMailbox;
use EventCrew\MailImport\MailboxException;
use EventCrew\Tests\TestCase;

/**
 * The socket IMAP client against a scripted server: one end of a socket pair
 * holds the server's answers, written before the client runs (the client
 * reads them in order), and afterwards gives back what the client sent.
 */
final class ImapMailboxTest extends TestCase
{
    private const PASSWORD = 'pa"ss\\word';

    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        parent::tearDown();
    }

    /**
     * @param array<int, string> $capabilities
     */
    private function mailbox(string $script, array $capabilities = ['IMAP4rev1', 'AUTH=PLAIN', 'MOVE']): ImapMailbox
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$client, $this->server] = $pair;

        $caps = '* CAPABILITY ' . implode(' ', $capabilities) . "\r\n";
        fwrite($this->server, str_replace('{CAPS}', $caps, $script));

        return new ImapMailbox(
            'imap.example.org',
            993,
            ImapMailbox::SECURITY_SSL,
            'import@example.org',
            self::PASSWORD,
            'INBOX',
            'Imported',
            'Rejected',
            2,
            static fn () => $client
        );
    }

    private function sent(): string
    {
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }

    /** The answers up to and including SELECT, for a server offering AUTH=PLAIN. */
    private static function login(): string
    {
        return "* OK IMAP ready\r\n"
            . "{CAPS}A001 OK\r\n"
            . "+ \r\n"
            . "A002 OK logged in\r\n"
            . "{CAPS}A003 OK\r\n"
            . "A004 NO [ALREADYEXISTS] exists\r\n"
            . "A005 OK created\r\n"
            . "* 3 EXISTS\r\n"
            . "A006 OK [READ-WRITE] selected\r\n";
    }

    public function testReadsTheUnreadMessagesOldestFirstWithoutMarkingThemRead(): void
    {
        $first = "From: a@example.com\r\n\r\nAnna\r\n";
        $second = "From: b@example.com\r\n\r\nBo\r\n";
        $mailbox = $this->mailbox(
            self::login()
            . "* SEARCH 12 7\r\nA007 OK\r\n"
            . '* 1 FETCH (UID 7 BODY[] {' . strlen($first) . "}\r\n" . $first . ")\r\nA008 OK\r\n"
            . '* 2 FETCH (UID 12 BODY[] {' . strlen($second) . "}\r\n" . $second . " FLAGS ())\r\nA009 OK\r\n"
            . "* BYE\r\nA010 OK\r\n"
        );

        $messages = $mailbox->unread(10);
        $mailbox->close();

        self::assertSame(['7' => $first, '12' => $second], $messages);

        $sent = $this->sent();
        // The password goes base64-encoded in AUTHENTICATE PLAIN, never on a command line.
        self::assertStringContainsString("A002 AUTHENTICATE PLAIN\r\n", $sent);
        self::assertStringContainsString(base64_encode("\0import@example.org\0" . self::PASSWORD) . "\r\n", $sent);
        self::assertStringContainsString("A004 CREATE \"Imported\"\r\nA005 CREATE \"Rejected\"\r\n", $sent);
        self::assertStringContainsString("A006 SELECT \"INBOX\"\r\n", $sent);
        self::assertStringContainsString("A007 UID SEARCH UNSEEN\r\n", $sent);
        self::assertStringContainsString("A008 UID FETCH 7 (BODY.PEEK[])\r\nA009 UID FETCH 12 (BODY.PEEK[])\r\n", $sent);
        self::assertStringContainsString("A010 LOGOUT\r\n", $sent);
    }

    public function testTheLimitCapsHowManyMessagesAreFetched(): void
    {
        $mailbox = $this->mailbox(
            self::login()
            . "* SEARCH 4 5 6\r\nA007 OK\r\n"
            . "* 1 FETCH (UID 4 BODY[] {2}\r\nhi)\r\nA008 OK\r\n"
        );

        self::assertSame(['4'], array_map('strval', array_keys($mailbox->unread(1))));
        self::assertStringNotContainsString('UID FETCH 5', $this->sent());
    }

    public function testFilingAwayMarksReadThenMoves(): void
    {
        $mailbox = $this->mailbox(self::login() . "A007 OK\r\nA008 OK\r\n");

        $mailbox->moveToRejected('9');

        $sent = $this->sent();
        self::assertStringContainsString("A007 UID STORE 9 +FLAGS.SILENT (\\Seen)\r\nA008 UID MOVE 9 \"Rejected\"\r\n", $sent);
    }

    public function testWithoutMoveItCopiesDeletesAndExpungesOnlyThatMessage(): void
    {
        $mailbox = $this->mailbox(
            self::login() . "A007 OK\r\nA008 OK\r\nA009 OK\r\nA010 OK\r\n",
            ['IMAP4rev1', 'AUTH=PLAIN', 'UIDPLUS']
        );

        $mailbox->moveToImported('9');

        self::assertStringContainsString(
            "A008 UID COPY 9 \"Imported\"\r\nA009 UID STORE 9 +FLAGS.SILENT (\\Deleted)\r\nA010 UID EXPUNGE 9\r\n",
            $this->sent()
        );
    }

    public function testWithoutAuthPlainItLogsInWithQuotedStrings(): void
    {
        $mailbox = $this->mailbox(
            "* OK ready\r\n{CAPS}A001 OK\r\nA002 OK\r\n{CAPS}A003 OK\r\nA004 OK\r\nA005 OK\r\nA006 OK\r\n"
            . "* SEARCH\r\nA007 OK\r\n",
            ['IMAP4rev1']
        );

        self::assertSame([], $mailbox->unread(10));
        self::assertStringContainsString("A002 LOGIN \"import@example.org\" \"pa\\\"ss\\\\word\"\r\n", $this->sent());
    }

    public function testARefusedLoginIsAnErrorThatDoesNotShowThePassword(): void
    {
        $mailbox = $this->mailbox("* OK ready\r\n{CAPS}A001 OK\r\nA002 NO bad password\r\n", ['IMAP4rev1']);

        try {
            $mailbox->unread(10);
            self::fail('A refused login must throw.');
        } catch (MailboxException $exception) {
            self::assertSame('IMAP LOGIN: A002 NO bad password', $exception->getMessage());
            self::assertStringNotContainsString('pa"ss', $exception->getMessage());
        }
    }

    public function testAServerThatHangsUpIsAnError(): void
    {
        $mailbox = $this->mailbox("* OK ready\r\n");
        fclose($this->server);

        $this->expectException(MailboxException::class);
        $this->expectExceptionMessage('The IMAP connection closed');

        $mailbox->unread(10);
    }
}
