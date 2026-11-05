<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

use Closure;
use EventCrew\Support\SecureUrl;

/**
 * A JMAP (RFC 8620/8621) mailbox over HTTPS. For hosts that block the IMAP
 * ports but let a site make ordinary HTTPS requests, which is most shared
 * hosting, InfinityFree included.
 *
 * It logs in with HTTP Basic auth, reads the unread messages in one folder,
 * downloads each one's raw bytes (its blob) so the same parser serves IMAP and
 * JMAP, and files a message away by setting $seen and replacing its folders
 * with the Imported or Rejected one, creating those folders if missing.
 */
final class JmapMailbox implements Mailbox
{
    private const USING = ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'];

    private string $apiUrl = '';

    private string $downloadUrl = '';

    private string $accountId = '';

    /** @var array<string, string> purpose => mailbox id */
    private array $folderIds = [];

    /**
     * @param string $sessionUrl The session resource, or a bare host / https URL to find it at /.well-known/jmap.
     * @param Closure(string, string, array<string, string>, string|null): array{status: int, body: string}|null $http
     *        Makes one request: method, URL, headers, body. Defaults to wp_remote_request().
     */
    public function __construct(
        private readonly string $sessionUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $folder,
        private readonly string $importedFolder,
        private readonly string $rejectedFolder,
        private readonly ?Closure $http = null
    ) {
    }

    /**
     * "mail.example.org" or "https://mail.example.org" -> its /.well-known/jmap;
     * a URL with a path is used as it is.
     */
    public static function sessionUrlFor(string $server): string
    {
        $server = trim($server);

        if ('' === $server) {
            return '';
        }

        if (! preg_match('#^https?://#i', $server)) {
            $server = 'https://' . $server;
        }

        $path = (string) parse_url($server, PHP_URL_PATH);

        return '' === trim($path, '/') ? rtrim($server, '/') . '/.well-known/jmap' : $server;
    }

    public function unread(int $limit): array
    {
        $this->open();

        $responses = $this->call([
            ['Email/query', [
                'accountId' => $this->accountId,
                'filter' => ['inMailbox' => $this->folderIds['read'], 'notKeyword' => '$seen'],
                'sort' => [['property' => 'receivedAt', 'isAscending' => true]],
                'limit' => max(0, $limit),
            ], 'q'],
            ['Email/get', [
                'accountId' => $this->accountId,
                '#ids' => ['resultOf' => 'q', 'name' => 'Email/query', 'path' => '/ids'],
                'properties' => ['id', 'blobId'],
            ], 'g'],
        ]);

        $messages = [];

        foreach ((array) ($responses['g']['list'] ?? []) as $email) {
            $id = (string) ($email['id'] ?? '');
            $blobId = (string) ($email['blobId'] ?? '');

            if ('' === $id || '' === $blobId) {
                continue;
            }

            $messages[$id] = $this->download($blobId);
        }

        return $messages;
    }

    public function moveToImported(string $id): void
    {
        $this->moveTo($id, 'imported');
    }

    public function moveToRejected(string $id): void
    {
        $this->moveTo($id, 'rejected');
    }

    public function close(): void
    {
        // HTTP has no session to end.
    }

    private function moveTo(string $id, string $purpose): void
    {
        $this->open();

        $responses = $this->call([
            ['Email/set', [
                'accountId' => $this->accountId,
                'update' => [
                    $id => [
                        'mailboxIds' => [$this->folderIds[$purpose] => true],
                        'keywords/$seen' => true,
                    ],
                ],
            ], 's'],
        ]);

        if (! isset($responses['s']['updated']) || ! array_key_exists($id, (array) $responses['s']['updated'])) {
            throw new MailboxException(
                'JMAP did not move message ' . $id . ': ' . self::describe($responses['s']['notUpdated'][$id] ?? null)
            );
        }
    }

    /** Reads the session and finds (or creates) the three folders. Once per instance. */
    private function open(): void
    {
        if ('' !== $this->accountId) {
            return;
        }

        $session = $this->request('GET', self::sessionUrlFor($this->sessionUrl), null);
        $this->apiUrl = (string) ($session['apiUrl'] ?? '');
        $this->downloadUrl = (string) ($session['downloadUrl'] ?? '');
        $accountId = (string) ($session['primaryAccounts']['urn:ietf:params:jmap:mail'] ?? '');

        if ('' === $this->apiUrl || '' === $this->downloadUrl || '' === $accountId) {
            throw new MailboxException('The JMAP session has no mail account for ' . $this->username);
        }

        $this->accountId = $accountId;

        $responses = $this->call([
            ['Mailbox/get', [
                'accountId' => $this->accountId,
                'ids' => null,
                'properties' => ['id', 'name', 'role', 'parentId'],
            ], 'm'],
        ]);
        $mailboxes = (array) ($responses['m']['list'] ?? []);

        $read = $this->findFolder($mailboxes, $this->folder);

        if (null === $read) {
            $this->accountId = '';
            throw new MailboxException('There is no folder called ' . $this->folder . ' in the mailbox');
        }

        $this->folderIds['read'] = $read;
        $this->folderIds['imported'] = $this->findFolder($mailboxes, $this->importedFolder)
            ?? $this->createFolder($this->importedFolder);
        $this->folderIds['rejected'] = $this->findFolder($mailboxes, $this->rejectedFolder)
            ?? $this->createFolder($this->rejectedFolder);
    }

    /**
     * "INBOX" matches the folder with the inbox role, whatever it is called;
     * any other name matches a top-level folder by name, ignoring case.
     *
     * @param array<int, mixed> $mailboxes
     */
    private function findFolder(array $mailboxes, string $name): ?string
    {
        foreach ($mailboxes as $mailbox) {
            if ('inbox' === strtolower($name) && 'inbox' === ($mailbox['role'] ?? null)) {
                return (string) $mailbox['id'];
            }
        }

        foreach ($mailboxes as $mailbox) {
            $sameName = strtolower((string) ($mailbox['name'] ?? '')) === strtolower($name);

            if (null === ($mailbox['parentId'] ?? null) && $sameName) {
                return (string) $mailbox['id'];
            }
        }

        return null;
    }

    private function createFolder(string $name): string
    {
        $responses = $this->call([
            ['Mailbox/set', [
                'accountId' => $this->accountId,
                'create' => ['new' => ['name' => $name, 'parentId' => null]],
            ], 'c'],
        ]);
        $id = (string) ($responses['c']['created']['new']['id'] ?? '');

        if ('' === $id) {
            throw new MailboxException(
                'JMAP could not create the folder ' . $name . ': '
                . self::describe($responses['c']['notCreated']['new'] ?? null)
            );
        }

        return $id;
    }

    private function download(string $blobId): string
    {
        $url = strtr($this->downloadUrl, [
            '{accountId}' => rawurlencode($this->accountId),
            '{blobId}' => rawurlencode($blobId),
            '{name}' => 'message.eml',
            '{type}' => rawurlencode('message/rfc822'),
        ]);

        $answer = $this->send('GET', $url, null);

        if (200 !== $answer['status']) {
            throw new MailboxException('JMAP download failed with HTTP ' . $answer['status']);
        }

        return $answer['body'];
    }

    /**
     * One JMAP request; answers keyed by call id. A method-level error is an
     * exception, so a half-done run never mistakes it for an empty result.
     *
     * @param array<int, array{0: string, 1: array<string, mixed>, 2: string}> $calls
     * @return array<string, array<string, mixed>>
     */
    private function call(array $calls): array
    {
        $answer = $this->request('POST', $this->apiUrl, ['using' => self::USING, 'methodCalls' => $calls]);
        $byId = [];

        foreach ((array) ($answer['methodResponses'] ?? []) as $response) {
            [$name, $arguments, $callId] = array_pad((array) $response, 3, null);

            if ('error' === $name) {
                throw new MailboxException('JMAP error: ' . self::describe($arguments));
            }

            $byId[(string) $callId] = (array) $arguments;
        }

        foreach ($calls as [, , $callId]) {
            if (! isset($byId[$callId])) {
                throw new MailboxException('JMAP gave no answer to ' . $callId);
            }
        }

        return $byId;
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $json): array
    {
        // Outside WordPress (the live mailbox test) there is no wp_json_encode().
        $encoded = null === $json ? null : (function_exists('wp_json_encode')
            ? (string) wp_json_encode($json)
            : (string) json_encode($json)); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
        $answer = $this->send($method, $url, $encoded);

        if (401 === $answer['status'] || 403 === $answer['status']) {
            throw new MailboxException(
                'The JMAP server refused the login for ' . $this->username . ' (HTTP ' . $answer['status'] . ')'
            );
        }

        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            throw new MailboxException('The JMAP server answered HTTP ' . $answer['status'] . ' for ' . $url);
        }

        $decoded = json_decode($answer['body'], true);

        if (! is_array($decoded)) {
            throw new MailboxException('The JMAP server did not answer with JSON at ' . $url);
        }

        return $decoded;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function send(string $method, string $url, ?string $body): array
    {
        // The login travels with every request, so never over plain http to
        // another machine. This also covers the API and download addresses
        // the server's session names.
        if (! SecureUrl::isAllowed($url)) {
            throw new MailboxException(
                'The JMAP address must start with https:// (http:// only to localhost or 127.0.0.1): '
                . (string) parse_url($url, PHP_URL_HOST)
            );
        }

        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
            'Accept' => 'application/json',
        ];

        if (null !== $body) {
            $headers['Content-Type'] = 'application/json';
        }

        if (null !== $this->http) {
            return ($this->http)($method, $url, $headers, $body);
        }

        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => 20,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            throw new MailboxException(
                'Could not reach ' . (string) parse_url($url, PHP_URL_HOST) . ': ' . $response->get_error_message()
            );
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }

    private static function describe(mixed $error): string
    {
        if (is_array($error)) {
            return trim((string) ($error['type'] ?? '') . ' ' . (string) ($error['description'] ?? ''));
        }

        return 'no details';
    }
}
