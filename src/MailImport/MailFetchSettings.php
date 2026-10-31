<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

/**
 * Where the site reads the import mailbox itself: off, IMAP or JMAP, with
 * the server, login and folders. One option, not autoloaded.
 *
 * This is the in-plugin alternative to the separate mail poller
 * (tools/mail-import), which posts to the signed endpoint instead. Either
 * works; a message imported by one is not imported again by the other,
 * because both file it away and the site remembers its Message-ID.
 */
final class MailFetchSettings
{
    public const OPTION = 'eventcrew_mail_fetch';

    /** Last run: when, whether it worked, and what happened, for Settings. */
    public const STATUS_OPTION = 'eventcrew_mail_fetch_status';

    public const OFF = '';
    public const IMAP = 'imap';
    public const JMAP = 'jmap';

    /**
     * @return array{protocol: string, server: string, port: int, security: string, username: string,
     *     password: string, folder: string, imported_folder: string, rejected_folder: string, authserv_id: string}
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);

        return self::normalize(is_array($stored) ? $stored : []);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function save(array $settings): void
    {
        update_option(self::OPTION, self::normalize($settings), false);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{protocol: string, server: string, port: int, security: string, username: string,
     *     password: string, folder: string, imported_folder: string, rejected_folder: string, authserv_id: string}
     */
    public static function normalize(array $raw): array
    {
        $protocol = (string) ($raw['protocol'] ?? self::OFF);
        $security = (string) ($raw['security'] ?? ImapMailbox::SECURITY_SSL);
        $port = (int) ($raw['port'] ?? 0);

        return [
            'protocol' => in_array($protocol, [self::IMAP, self::JMAP], true) ? $protocol : self::OFF,
            'server' => trim((string) ($raw['server'] ?? '')),
            'port' => $port > 0 && $port < 65536 ? $port : 993,
            'security' => ImapMailbox::SECURITY_STARTTLS === $security ? $security : ImapMailbox::SECURITY_SSL,
            'username' => trim((string) ($raw['username'] ?? '')),
            'password' => (string) ($raw['password'] ?? ''),
            'folder' => self::folder($raw['folder'] ?? '', 'INBOX'),
            'imported_folder' => self::folder($raw['imported_folder'] ?? '', 'Imported'),
            'rejected_folder' => self::folder($raw['rejected_folder'] ?? '', 'Rejected'),
            'authserv_id' => trim((string) ($raw['authserv_id'] ?? '')),
        ];
    }

    /** Switched on, with a server and a login to use. */
    public static function isOn(): bool
    {
        $settings = self::get();

        return self::OFF !== $settings['protocol']
            && '' !== $settings['server']
            && '' !== $settings['username']
            && '' !== $settings['password'];
    }

    public static function mailbox(): Mailbox
    {
        $s = self::get();

        if (self::JMAP === $s['protocol']) {
            return new JmapMailbox(
                $s['server'],
                $s['username'],
                $s['password'],
                $s['folder'],
                $s['imported_folder'],
                $s['rejected_folder']
            );
        }

        return new ImapMailbox(
            $s['server'],
            $s['port'],
            $s['security'],
            $s['username'],
            $s['password'],
            $s['folder'],
            $s['imported_folder'],
            $s['rejected_folder']
        );
    }

    /**
     * @return array{time: int, ok: bool, message: string}
     */
    public static function status(): array
    {
        $stored = get_option(self::STATUS_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'time' => (int) ($stored['time'] ?? 0),
            'ok' => (bool) ($stored['ok'] ?? false),
            'message' => (string) ($stored['message'] ?? ''),
        ];
    }

    private static function folder(mixed $value, string $default): string
    {
        // Control characters would break an IMAP command line.
        $value = trim((string) preg_replace('/[\x00-\x1f\x7f]/', '', (string) $value));

        return '' === $value ? $default : $value;
    }
}
