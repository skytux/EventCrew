<?php

declare(strict_types=1);

namespace EventCrew\Tests\MailImport;

use Brain\Monkey\Functions;
use EventCrew\MailImport\JmapMailbox;
use EventCrew\MailImport\MailboxException;
use EventCrew\Tests\TestCase;

/**
 * The JMAP client against a fake server: a closure that answers each request
 * the way a JMAP server would and records what it was sent.
 */
final class JmapMailboxTest extends TestCase
{
    private const SESSION = 'https://mail.example.org/jmap/session';
    private const API = 'https://mail.example.org/jmap/';

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: array<string, mixed>|null}> */
    private array $requests = [];

    /** @var array<int, array<string, mixed>> */
    private array $folders = [];

    /** @var array<string, mixed>|null What Email/set answers; null = updated. */
    private ?array $setAnswer = null;

    private int $sessionStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_json_encode')->alias(static fn (mixed $data): string => (string) json_encode($data));

        $this->requests = [];
        $this->folders = [
            ['id' => 'in1', 'name' => 'Inbox', 'role' => 'inbox', 'parentId' => null],
            ['id' => 'imp', 'name' => 'imported', 'role' => null, 'parentId' => null],
            ['id' => 'sub', 'name' => 'Rejected', 'role' => null, 'parentId' => 'imp'],
        ];
    }

    private function mailbox(string $server = self::SESSION): JmapMailbox
    {
        return new JmapMailbox(
            $server,
            'import@example.org',
            'secret',
            'INBOX',
            'Imported',
            'Rejected',
            fn (string $method, string $url, array $headers, ?string $body): array => $this->answer($method, $url, $headers, $body)
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function answer(string $method, string $url, array $headers, ?string $body): array
    {
        $json = null === $body ? null : json_decode($body, true);
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $json];

        if (self::SESSION === $url || 'https://mail.example.org/.well-known/jmap' === $url) {
            return ['status' => $this->sessionStatus, 'body' => (string) json_encode([
                'apiUrl' => self::API,
                'downloadUrl' => 'https://mail.example.org/dl/{accountId}/{blobId}/{name}?accept={type}',
                'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'acc 1'],
            ])];
        }

        if (str_starts_with($url, 'https://mail.example.org/dl/')) {
            return ['status' => 200, 'body' => 'RAW ' . basename((string) parse_url($url, PHP_URL_PATH), '/message.eml') . ' ' . $url];
        }

        $responses = [];

        foreach ($json['methodCalls'] as [$name, $args, $id]) {
            $responses[] = match ($name) {
                'Mailbox/get' => [$name, ['list' => $this->folders], $id],
                'Mailbox/set' => [$name, ['created' => ['new' => ['id' => 'new-' . $args['create']['new']['name']]]], $id],
                'Email/query' => [$name, ['ids' => ['e1', 'e2']], $id],
                'Email/get' => [$name, ['list' => [['id' => 'e1', 'blobId' => 'b/1'], ['id' => 'e2', 'blobId' => 'b2']]], $id],
                'Email/set' => [$name, $this->setAnswer ?? ['updated' => [array_key_first($args['update']) => null]], $id],
                default => ['error', ['type' => 'unknownMethod'], $id],
            };
        }

        return ['status' => 200, 'body' => (string) json_encode(['methodResponses' => $responses])];
    }

    /**
     * @return array<int, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    private function calls(): array
    {
        $calls = [];

        foreach ($this->requests as $request) {
            foreach ($request['body']['methodCalls'] ?? [] as $call) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    public function testABareHostFindsTheSessionAtTheWellKnownPath(): void
    {
        self::assertSame('https://mail.example.org/.well-known/jmap', JmapMailbox::sessionUrlFor('mail.example.org'));
        self::assertSame('https://mail.example.org/.well-known/jmap', JmapMailbox::sessionUrlFor('https://mail.example.org/'));
        self::assertSame(self::SESSION, JmapMailbox::sessionUrlFor(self::SESSION));
    }

    public function testReadsTheUnreadMessagesAsRawBytesAndCreatesOnlyTheMissingFolder(): void
    {
        $messages = $this->mailbox()->unread(5);

        self::assertSame(['e1', 'e2'], array_keys($messages));
        // The blob id and account id are URL-encoded into the download URL.
        self::assertStringContainsString('/dl/acc%201/b%2F1/message.eml?accept=message%2Frfc822', $messages['e1']);

        $calls = $this->calls();
        self::assertSame('Mailbox/get', $calls[0][0]);
        // "Imported" matched "imported" regardless of case; "Rejected" exists only
        // as a subfolder, so a top-level one is created.
        self::assertSame('Mailbox/set', $calls[1][0]);
        self::assertSame(['name' => 'Rejected', 'parentId' => null], $calls[1][1]['create']['new']);
        self::assertSame('Email/query', $calls[2][0]);
        self::assertSame(['inMailbox' => 'in1', 'notKeyword' => '$seen'], $calls[2][1]['filter']);
        self::assertSame(5, $calls[2][1]['limit']);
        self::assertSame('Basic ' . base64_encode('import@example.org:secret'), $this->requests[0]['headers']['Authorization']);
    }

    public function testFilingAwayReplacesTheFoldersAndMarksRead(): void
    {
        $mailbox = $this->mailbox();
        $mailbox->moveToImported('e1');
        $mailbox->moveToRejected('e2');

        $sets = array_values(array_filter($this->calls(), static fn (array $call): bool => 'Email/set' === $call[0]));

        self::assertSame(['e1' => ['mailboxIds' => ['imp' => true], 'keywords/$seen' => true]], $sets[0][1]['update']);
        self::assertSame(['e2' => ['mailboxIds' => ['new-Rejected' => true], 'keywords/$seen' => true]], $sets[1][1]['update']);
        // The session and folders were read once for both moves.
        self::assertCount(1, array_filter($this->calls(), static fn (array $call): bool => 'Mailbox/get' === $call[0]));
    }

    public function testAMoveTheServerRefusedIsAnError(): void
    {
        $this->setAnswer = ['notUpdated' => ['e1' => ['type' => 'notFound']]];

        $this->expectException(MailboxException::class);
        $this->expectExceptionMessage('JMAP did not move message e1: notFound');

        $this->mailbox()->moveToImported('e1');
    }

    /** The login goes with every request, so plain http to another machine is never sent. */
    public function testAPlainHttpAddressIsRefusedBeforeAnythingIsSent(): void
    {
        try {
            $this->mailbox('http://mail.example.org/jmap/session')->unread(5);
            self::fail('Expected the http address to be refused.');
        } catch (MailboxException $e) {
            self::assertStringContainsString('must start with https://', $e->getMessage());
        }

        self::assertSame([], $this->requests);
    }

    public function testPlainHttpToThisMachineIsAllowed(): void
    {
        foreach (['http://localhost:8080/jmap/session', 'http://127.0.0.1/jmap/session'] as $url) {
            $sent = [];
            $mailbox = new JmapMailbox(
                $url,
                'import@example.org',
                'secret',
                'INBOX',
                'Imported',
                'Rejected',
                static function (string $method, string $to) use (&$sent): array {
                    $sent[] = $to;

                    return ['status' => 404, 'body' => ''];
                }
            );

            try {
                $mailbox->unread(5);
            } catch (MailboxException $e) {
                // Only that the request was sent matters here.
                self::assertStringNotContainsString('must start with https://', $e->getMessage());
            }

            self::assertSame([$url], $sent);
        }
    }

    public function testTheSecureUrlRule(): void
    {
        self::assertTrue(\EventCrew\Support\SecureUrl::isAllowed('https://llm.example.org/v1'));
        self::assertTrue(\EventCrew\Support\SecureUrl::isAllowed('HTTPS://LLM.EXAMPLE.ORG'));
        self::assertTrue(\EventCrew\Support\SecureUrl::isAllowed('http://localhost:11434/v1'));
        self::assertTrue(\EventCrew\Support\SecureUrl::isAllowed('http://127.0.0.1/v1'));
        self::assertFalse(\EventCrew\Support\SecureUrl::isAllowed('http://llm.example.org/v1'));
        self::assertFalse(\EventCrew\Support\SecureUrl::isAllowed('http://localhost.example.org/v1'));
        self::assertFalse(\EventCrew\Support\SecureUrl::isAllowed('http://127.0.0.2/v1'));
        self::assertFalse(\EventCrew\Support\SecureUrl::isAllowed('ftp://llm.example.org'));
        self::assertFalse(\EventCrew\Support\SecureUrl::isAllowed(''));
    }

    public function testARefusedLoginSaysSo(): void
    {
        $this->sessionStatus = 401;

        $this->expectException(MailboxException::class);
        $this->expectExceptionMessage('The JMAP server refused the login for import@example.org (HTTP 401)');

        $this->mailbox()->unread(5);
    }

    public function testAMissingFolderToReadIsAnError(): void
    {
        $this->folders = [['id' => 'x', 'name' => 'Archive', 'role' => 'archive', 'parentId' => null]];

        $this->expectException(MailboxException::class);
        $this->expectExceptionMessage('There is no folder called INBOX in the mailbox');

        $this->mailbox()->unread(5);
    }
}
