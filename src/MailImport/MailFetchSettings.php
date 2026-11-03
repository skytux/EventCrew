<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

use EventCrew\Support\SecretBox;

/**
 * Where the site reads the import mailbox itself: off, IMAP or JMAP, with
 * the server, login and folders. One option, not autoloaded.
 *
 * The password is stored encrypted ('enc1:' + SecretBox) under a key derived
 * from this site's auth salt. get() returns it decrypted.
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

    /** Marks a stored password as encrypted; a value without it is plain text from before. */
    public const PASSWORD_PREFIX = 'enc1:';

    /** passwordStatus(): no password stored. */
    public const PASSWORD_NONE = 'none';

    /** passwordStatus(): a password is stored and readable. */
    public const PASSWORD_OK = 'ok';

    /** passwordStatus(): a password is stored but cannot be decrypted on this site. */
    public const PASSWORD_UNREADABLE = 'unreadable';

    /** Mixed into the salt, so this key is not shared with any other secret. */
    private const PASSWORD_CONTEXT = '|eventcrew-mail-fetch-password-v1';

    /**
     * @return array{protocol: string, server: string, port: int, security: string, username: string,
     *     password: string, folder: string, imported_folder: string, rejected_folder: string, authserv_id: string}
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        $settings = self::normalize(is_array($stored) ? $stored : []);
        $storedPassword = $settings['password'];
        $settings['password'] = self::decryptPassword($storedPassword);

        // A password saved before it was encrypted: encrypt it now, once.
        if ('' !== $storedPassword && ! str_starts_with($storedPassword, self::PASSWORD_PREFIX)) {
            update_option(
                self::OPTION,
                ['password' => self::encryptPassword($storedPassword)] + $settings,
                false
            );
        }

        return $settings;
    }

    /**
     * Saves the settings with the password encrypted. Without a 'password'
     * key the stored password is kept as it is, even one that cannot be
     * decrypted, so its notice stays until a new one is entered.
     *
     * @param array<string, mixed> $settings
     */
    public static function save(array $settings): void
    {
        $normalized = self::normalize($settings);

        if (array_key_exists('password', $settings)) {
            $normalized['password'] = self::encryptPassword($normalized['password']);
        } else {
            $stored = get_option(self::OPTION, []);
            $normalized['password'] = (string) (is_array($stored) ? ($stored['password'] ?? '') : '');
        }

        update_option(self::OPTION, $normalized, false);
    }

    /** One of the PASSWORD_* constants. */
    public static function passwordStatus(): string
    {
        $stored = get_option(self::OPTION, []);
        $password = (string) (is_array($stored) ? ($stored['password'] ?? '') : '');

        if ('' === $password) {
            return self::PASSWORD_NONE;
        }

        if (! str_starts_with($password, self::PASSWORD_PREFIX)) {
            return self::PASSWORD_OK;
        }

        return null === SecretBox::decrypt(substr($password, strlen(self::PASSWORD_PREFIX)), self::PASSWORD_CONTEXT)
            ? self::PASSWORD_UNREADABLE
            : self::PASSWORD_OK;
    }

    /** 'enc1:' . base64(nonce . box), or '' for no password. */
    private static function encryptPassword(string $plain): string
    {
        return '' === $plain ? '' : self::PASSWORD_PREFIX . SecretBox::encrypt($plain, self::PASSWORD_CONTEXT);
    }

    /**
     * The plain password. A value without the prefix is a password saved
     * before encryption and is used as it is; a box that does not open (the
     * salts changed) reads as no password.
     */
    private static function decryptPassword(string $stored): string
    {
        if (! str_starts_with($stored, self::PASSWORD_PREFIX)) {
            return $stored;
        }

        return SecretBox::decrypt(substr($stored, strlen(self::PASSWORD_PREFIX)), self::PASSWORD_CONTEXT) ?? '';
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
