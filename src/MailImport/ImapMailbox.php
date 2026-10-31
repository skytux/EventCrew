<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

use Closure;

/**
 * A small IMAP4rev1 client over a TLS socket. It needs only PHP's openssl
 * stream support, not the imap extension, which many hosts no longer ship.
 *
 * It does exactly what the import needs: log in, make sure the Imported and
 * Rejected folders exist, list the unread messages in one folder, fetch each
 * one without marking it read (BODY.PEEK[]), and file it away. A message only
 * becomes read once it has been dealt with, so a run that dies half way loses
 * nothing.
 *
 * Note: some shared hosts (InfinityFree among them) block outgoing
 * connections to the IMAP ports. Use JMAP there.
 */
final class ImapMailbox implements Mailbox
{
    public const SECURITY_SSL = 'ssl';
    public const SECURITY_STARTTLS = 'starttls';

    /** @var resource|null */
    private $stream = null;

    private int $tag = 0;

    /** @var array<int, string> */
    private array $capabilities = [];

    private bool $folderOpen = false;

    /**
     * @param Closure(): resource|null $connect Opens the connection; tests pass one end of a socket pair.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $security,
        private readonly string $username,
        private readonly string $password,
        private readonly string $folder,
        private readonly string $importedFolder,
        private readonly string $rejectedFolder,
        private readonly int $timeout = 20,
        private readonly ?Closure $connect = null
    ) {
    }

    public function unread(int $limit): array
    {
        $this->open();

        $untagged = $this->command('UID SEARCH UNSEEN');
        $uids = [];

        foreach ($untagged as $response) {
            if (1 === preg_match('/^\* SEARCH\b(.*)$/i', $response['line'], $match)) {
                foreach (preg_split('/\s+/', trim($match[1])) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }

        sort($uids);
        $messages = [];

        foreach (array_slice($uids, 0, max(0, $limit)) as $uid) {
            foreach ($this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])') as $response) {
                if (1 === preg_match('/FETCH\b/i', $response['line']) && [] !== $response['literals']) {
                    $messages[(string) $uid] = $response['literals'][0];
                    break;
                }
            }
        }

        return $messages;
    }

    public function moveToImported(string $id): void
    {
        $this->moveTo($id, $this->importedFolder);
    }

    public function moveToRejected(string $id): void
    {
        $this->moveTo($id, $this->rejectedFolder);
    }

    public function close(): void
    {
        if (null === $this->stream) {
            return;
        }

        try {
            $this->command('LOGOUT');
        } catch (MailboxException) {
            // Closing anyway.
        }

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
        $this->folderOpen = false;
    }

    private function moveTo(string $id, string $folder): void
    {
        if (! ctype_digit($id)) {
            throw new MailboxException('Not an IMAP UID: ' . $id);
        }

        $this->open();
        $this->command('UID STORE ' . $id . ' +FLAGS.SILENT (\\Seen)');

        if (in_array('MOVE', $this->capabilities, true)) {
            $this->command('UID MOVE ' . $id . ' ' . self::quote($folder));

            return;
        }

        $this->command('UID COPY ' . $id . ' ' . self::quote($folder));
        $this->command('UID STORE ' . $id . ' +FLAGS.SILENT (\\Deleted)');
        $this->command(in_array('UIDPLUS', $this->capabilities, true) ? 'UID EXPUNGE ' . $id : 'EXPUNGE');
    }

    /**
     * Connects, logs in, creates the two folders if missing and selects the
     * folder being read. Does nothing once done.
     */
    private function open(): void
    {
        if (null !== $this->stream && $this->folderOpen) {
            return;
        }

        $this->stream = $this->connectStream();
        $greeting = $this->readLine();

        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            throw new MailboxException('The IMAP server did not greet: ' . self::short($greeting));
        }

        if (self::SECURITY_STARTTLS === $this->security) {
            $this->command('STARTTLS');

            if (true !== @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new MailboxException('STARTTLS failed on ' . $this->host);
            }
        }

        $this->readCapabilities();
        $this->login();
        $this->readCapabilities();

        foreach ([$this->importedFolder, $this->rejectedFolder] as $name) {
            // "Already exists" is the usual answer and is fine.
            $this->command('CREATE ' . self::quote($name), false);
        }

        $this->command('SELECT ' . self::quote($this->folder));
        $this->folderOpen = true;
    }

    /** @return resource */
    private function connectStream()
    {
        if (null !== $this->connect) {
            $stream = ($this->connect)();
        } else {
            $scheme = self::SECURITY_SSL === $this->security ? 'ssl' : 'tcp';
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => $this->host,
                    'SNI_enabled' => true,
                ],
            ]);
            $errno = 0;
            $error = '';
            $stream = @stream_socket_client(
                $scheme . '://' . $this->host . ':' . $this->port,
                $errno,
                $error,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (false === $stream) {
                throw new MailboxException(sprintf(
                    'Could not connect to %s:%d (%d %s)',
                    $this->host,
                    $this->port,
                    $errno,
                    '' === $error ? 'no answer; the host may block this port' : $error
                ));
            }
        }

        if (! is_resource($stream)) {
            throw new MailboxException('Could not connect to ' . $this->host);
        }

        stream_set_timeout($stream, $this->timeout);

        return $stream;
    }

    private function readCapabilities(): void
    {
        foreach ($this->command('CAPABILITY') as $response) {
            if (1 === preg_match('/^\* CAPABILITY (.*)$/i', $response['line'], $match)) {
                $this->capabilities = array_map('strtoupper', preg_split('/\s+/', trim($match[1])) ?: []);
            }
        }
    }

    /**
     * AUTHENTICATE PLAIN when offered (any password bytes work), else LOGIN
     * with quoted strings.
     */
    private function login(): void
    {
        if (in_array('AUTH=PLAIN', $this->capabilities, true)) {
            $token = base64_encode("\0" . $this->username . "\0" . $this->password);
            $this->command('AUTHENTICATE PLAIN', true, $token);

            return;
        }

        $this->command('LOGIN ' . self::quote($this->username) . ' ' . self::quote($this->password));
    }

    /**
     * Sends one tagged command and reads up to its tagged answer.
     *
     * @param string|null $continuation Sent when the server answers "+".
     * @return array<int, array{line: string, literals: array<int, string>}> the untagged responses
     */
    private function command(string $command, bool $mustSucceed = true, ?string $continuation = null): array
    {
        $tag = sprintf('A%03d', ++$this->tag);
        $this->write($tag . ' ' . $command . "\r\n");
        $untagged = [];

        while (true) {
            $response = $this->readResponse();
            $line = $response['line'];

            if (str_starts_with($line, '+')) {
                // A continuation request. Answer it once, or cancel with "*".
                $this->write(($continuation ?? '*') . "\r\n");
                $continuation = '*';
                continue;
            }

            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper((string) strtok(substr($line, strlen($tag) + 1), ' '));

                if ($mustSucceed && 'OK' !== $status) {
                    // The login command line carries the password; never echo it.
                    $shown = str_starts_with($command, 'LOGIN') || str_starts_with($command, 'AUTHENTICATE')
                        ? strtok($command, ' ')
                        : $command;

                    throw new MailboxException(sprintf('IMAP %s: %s', $shown, self::short($line)));
                }

                return $untagged;
            }

            if (str_starts_with($line, '* BYE') && ! str_starts_with($command, 'LOGOUT')) {
                throw new MailboxException('The IMAP server closed the session: ' . self::short($line));
            }

            $untagged[] = $response;
        }
    }

    /**
     * One response: its line, with each {n} literal read out into $literals
     * and the text after it joined back on.
     *
     * @return array{line: string, literals: array<int, string>}
     */
    private function readResponse(): array
    {
        $line = $this->readLine();
        $literals = [];

        while (1 === preg_match('/\{(\d+)\}$/', $line, $match)) {
            $literals[] = $this->readBytes((int) $match[1]);
            $line .= $this->readLine();
        }

        return ['line' => $line, 'literals' => $literals];
    }

    private function readLine(): string
    {
        $line = $this->stream ? fgets($this->stream) : false;

        if (false === $line) {
            throw new MailboxException($this->lostReason());
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        $bytes = '';

        while (strlen($bytes) < $length) {
            $chunk = $this->stream ? fread($this->stream, min(65536, $length - strlen($bytes))) : false;

            if (false === $chunk || '' === $chunk) {
                if ($this->stream && ! feof($this->stream) && ! stream_get_meta_data($this->stream)['timed_out']) {
                    continue;
                }

                throw new MailboxException($this->lostReason());
            }

            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function write(string $data): void
    {
        if (null === $this->stream || false === @fwrite($this->stream, $data)) {
            throw new MailboxException($this->lostReason());
        }
    }

    private function lostReason(): string
    {
        $timedOut = null !== $this->stream && is_resource($this->stream)
            && (bool) (stream_get_meta_data($this->stream)['timed_out'] ?? false);

        return $timedOut ? 'The IMAP server stopped answering' : 'The IMAP connection closed';
    }

    private static function quote(string $text): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"';
    }

    private static function short(string $line): string
    {
        return substr($line, 0, 200);
    }
}
