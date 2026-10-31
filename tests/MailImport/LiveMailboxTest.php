<?php

declare(strict_types=1);

namespace EventCrew\Tests\MailImport;

use EventCrew\MailImport\ImapMailbox;
use EventCrew\MailImport\JmapMailbox;
use EventCrew\MailImport\Mailbox;
use EventCrew\Tests\TestCase;

/**
 * Both mailbox clients against a real mail server. Skipped unless
 * EVENTCREW_LIVE_MAIL names a JSON file:
 *
 *     {"imap_host": "imap.example.org", "imap_port": 993,
 *      "jmap_session": "https://mail.example.org/.well-known/jmap",
 *      "username": "import@example.org", "password": "..."}
 *
 * Run it with: ec env EVENTCREW_LIVE_MAIL=/app/.live-mail.json vendor/bin/phpunit tests/MailImport/LiveMailboxTest.php
 * (.live-mail.json is git-ignored; delete it afterwards).
 *
 * It never touches the inbox: it makes three folders of its own, puts two
 * test messages in the first with IMAP APPEND, lets the client read and file
 * them, checks where they went, and deletes the folders again.
 */
final class LiveMailboxTest extends TestCase
{
    /** @var array{imap_host: string, imap_port: int, jmap_session: string, username: string, password: string} */
    private array $config;

    private string $folder = '';

    protected function setUp(): void
    {
        parent::setUp();

        $file = (string) getenv('EVENTCREW_LIVE_MAIL');

        if ('' === $file || ! is_readable($file)) {
            self::markTestSkipped('Set EVENTCREW_LIVE_MAIL to a mailbox settings file to run the live test.');
        }

        $config = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($config, 'The live mail settings file is not JSON.');
        $this->config = $config;
        $this->folder = 'EventCrewTest-' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        if ('' !== $this->folder && isset($this->config)) {
            $this->raw(function ($session): void {
                foreach (['', '-Imported', '-Rejected'] as $suffix) {
                    $session('DELETE "' . $this->folder . $suffix . '"', false);
                }
            });
        }

        parent::tearDown();
    }

    public function testImapReadsAndFilesRealMessages(): void
    {
        $this->roundTrip(new ImapMailbox(
            $this->config['imap_host'],
            (int) $this->config['imap_port'],
            ImapMailbox::SECURITY_SSL,
            $this->config['username'],
            $this->config['password'],
            $this->folder,
            $this->folder . '-Imported',
            $this->folder . '-Rejected'
        ));
    }

    public function testJmapReadsAndFilesRealMessages(): void
    {
        // The JMAP client finds folders by name, so they must exist first.
        $this->raw(function ($session): void {
            $session('CREATE "' . $this->folder . '"', true);
        });

        $this->roundTrip(new JmapMailbox(
            $this->config['jmap_session'],
            $this->config['username'],
            $this->config['password'],
            $this->folder,
            $this->folder . '-Imported',
            $this->folder . '-Rejected',
            self::curl(...)
        ));
    }

    private function roundTrip(Mailbox $mailbox): void
    {
        $this->raw(function ($session, $append): void {
            $session('CREATE "' . $this->folder . '"', false);
            $append($this->folder, self::message('first'));
            $append($this->folder, self::message('second'));
        });

        $messages = $mailbox->unread(10);
        $mailbox->close();

        self::assertCount(2, $messages);
        [$firstId, $secondId] = array_map('strval', array_keys($messages));
        self::assertStringContainsString('Body first', $messages[$firstId]);
        self::assertStringContainsString('Body second', $messages[$secondId]);

        $mailbox->moveToImported($firstId);
        $mailbox->moveToRejected($secondId);

        self::assertSame([], $mailbox->unread(10), 'Filed messages must no longer be unread in the folder.');
        $mailbox->close();

        self::assertSame(['1 Seen'], $this->countIn($this->folder . '-Imported'));
        self::assertSame(['1 Seen'], $this->countIn($this->folder . '-Rejected'));
    }

    private function message(string $marker): string
    {
        return "From: EventCrew test <{$this->config['username']}>\r\n"
            . "To: {$this->config['username']}\r\n"
            . "Subject: EventCrew live test {$marker}\r\n"
            . 'Message-ID: <' . $marker . '.' . $this->folder . "@eventcrew.test>\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Body {$marker}\r\n";
    }

    /**
     * How many messages a folder holds and whether they are all read, as
     * "<count> Seen" (or "<count> Unseen").
     *
     * @return array<int, string>
     */
    private function countIn(string $folder): array
    {
        $result = [];

        $this->raw(function ($session) use ($folder, &$result): void {
            $lines = $session('EXAMINE "' . $folder . '"', true);
            $exists = 0;

            foreach ($lines as $line) {
                if (1 === preg_match('/^\* (\d+) EXISTS/', $line, $match)) {
                    $exists = (int) $match[1];
                }
            }

            $unseen = $session('SEARCH UNSEEN', true);
            $result[] = $exists . ('* SEARCH' === trim(implode('', $unseen)) ? ' Seen' : ' Unseen');
        });

        return $result;
    }

    /**
     * A bare IMAP session for setting up and cleaning up, independent of the
     * client under test. $callback gets a command function and an APPEND
     * function.
     */
    private function raw(callable $callback): void
    {
        $stream = stream_socket_client(
            'ssl://' . $this->config['imap_host'] . ':' . (int) $this->config['imap_port'],
            $errno,
            $error,
            20
        );
        self::assertIsResource($stream, "Could not connect: {$errno} {$error}");
        fgets($stream);
        $tag = 0;

        $command = function (string $line, bool $mustSucceed, ?string $literal = null) use ($stream, &$tag): array {
            $id = 'T' . ++$tag;
            fwrite($stream, $id . ' ' . $line . "\r\n");
            $lines = [];

            while (false !== ($answer = fgets($stream))) {
                if (str_starts_with($answer, '+') && null !== $literal) {
                    fwrite($stream, $literal . "\r\n");
                    $literal = null;
                    continue;
                }

                if (str_starts_with($answer, $id . ' ')) {
                    if ($mustSucceed) {
                        self::assertStringStartsWith($id . ' OK', $answer, 'IMAP ' . strtok($line, ' ') . ' failed');
                    }

                    return $lines;
                }

                $lines[] = rtrim($answer, "\r\n");
            }

            self::fail('The IMAP server closed the connection.');
        };

        $quote = static fn (string $text): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"';
        $id = 'T' . ++$tag;
        fwrite($stream, $id . ' LOGIN ' . $quote($this->config['username']) . ' ' . $quote($this->config['password']) . "\r\n");

        while (false !== ($answer = fgets($stream)) && ! str_starts_with($answer, $id . ' ')) {
            // Untagged lines before the login answer.
        }

        self::assertStringStartsWith($id . ' OK', (string) $answer, 'The live test could not log in.');

        $append = static fn (string $folder, string $message) => $command(
            'APPEND ' . $quote($folder) . ' () {' . strlen($message) . '}',
            true,
            $message
        );

        try {
            $callback($command, $append);
        } finally {
            fwrite($stream, 'T' . ++$tag . " LOGOUT\r\n");
            fclose($stream);
        }
    }

    /**
     * The JMAP client's requests through curl, since there is no WordPress
     * HTTP API here.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $handle = curl_init($url);
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
        ]);

        if (null !== $body) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $answer = curl_exec($handle);
        self::assertIsString($answer, 'curl: ' . curl_error($handle));

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $answer];
    }
}
