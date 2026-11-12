<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The short code in the sign-in email, typed on the page instead of opening
 * the link.
 *
 * An app added to an iPhone home screen keeps its own cookies, apart from
 * Safari's, so the emailed link signs in Safari and not the app. The code
 * signs in whichever browser it is typed into.
 *
 * A code belongs to one pending sign-in: the email's link token. It is
 * stored with that token's hash (never the token itself) and only as a keyed
 * hash of its own, in a transient per email address that expires with the
 * link. Typing it spends the link's token (the caller does that with the hash
 * returned), so the link and the code are one sign-in: whichever is used
 * first, the other no longer works.
 *
 * MAX_TRIES wrong codes for an address remove every code waiting for it; the
 * links still work, since they need the mailbox anyway. Wrong codes are also
 * counted per IP address (IP_LIMIT an hour), so one visitor cannot try codes
 * for many addresses. Codes are never logged.
 */
final class LoginCode
{
    /** Digits in a code. */
    public const LENGTH = 6;

    /** Wrong codes for one address before its codes are removed. */
    public const MAX_TRIES = 5;

    /** Wrong codes from one IP address in IP_WINDOW. */
    public const IP_LIMIT = 30;

    private const IP_WINDOW = HOUR_IN_SECONDS;

    private const PREFIX = 'eventcrew_login_code_';
    private const IP_PREFIX = 'eventcrew_login_code_ip_';

    /** check() answers. */
    public const OK = 'ok';
    public const WRONG = 'wrong';
    public const LOCKED = 'locked';
    public const BUSY = 'busy';

    /**
     * A new code for the sign-in link $rawToken, mailed to $email, good for
     * $ttl seconds like the link. Returns the code for the email; only its
     * keyed hash is kept.
     */
    public static function issue(string $email, string $rawToken, int $ttl, ?int $now = null): string
    {
        $now ??= time();
        $code = str_pad((string) random_int(0, (10 ** self::LENGTH) - 1), self::LENGTH, '0', STR_PAD_LEFT);
        $key = self::key($email);
        $stored = self::live(get_transient($key), $now);

        $stored['entries'][] = [
            'code' => self::codeHash($email, $code),
            'token' => hash('sha256', trim($rawToken)),
            'expires' => $now + $ttl,
        ];

        set_transient($key, $stored, self::lifetime($stored, $now));

        return $code;
    }

    /**
     * Checks a typed code for $email. 'state' is OK with 'token' (the hash
     * of the link token to spend), WRONG (wrong, expired, or no code waiting:
     * one answer, so it tells nobody whether a sign-in is waiting), LOCKED
     * (that was the last allowed wrong try; the codes are gone) or BUSY (too
     * many wrong codes from this IP address).
     *
     * A code is used once: a right one removes every code waiting for the
     * address.
     *
     * @return array{state: string, token?: string}
     */
    public static function check(string $email, string $typed, string $clientIp = '', ?int $now = null): array
    {
        $now ??= time();

        if (! self::ipAllowed($clientIp, $now)) {
            return ['state' => self::BUSY];
        }

        $code = self::normalize($typed);
        $key = self::key($email);
        $stored = self::live(get_transient($key), $now);

        if (null === $code || [] === $stored['entries']) {
            self::countIp($clientIp, $now);

            return ['state' => self::WRONG];
        }

        $hash = self::codeHash($email, $code);
        $token = null;

        // Every entry is compared, in constant time, whichever matches.
        foreach ($stored['entries'] as $entry) {
            if (hash_equals((string) $entry['code'], $hash)) {
                $token = (string) $entry['token'];
            }
        }

        if (null !== $token) {
            delete_transient($key);

            return ['state' => self::OK, 'token' => $token];
        }

        self::countIp($clientIp, $now);
        ++$stored['tries'];

        if ($stored['tries'] >= self::MAX_TRIES) {
            delete_transient($key);

            return ['state' => self::LOCKED];
        }

        set_transient($key, $stored, self::lifetime($stored, $now));

        return ['state' => self::WRONG];
    }

    /** Removes every code waiting for $email (its link was used). */
    public static function forget(string $email): void
    {
        delete_transient(self::key($email));
    }

    /**
     * The digits of what was typed (spaces and dashes are allowed between
     * them), or null when that is not LENGTH digits.
     */
    public static function normalize(string $typed): ?string
    {
        $digits = (string) preg_replace('/[\s\-]+/u', '', $typed);

        return 1 === preg_match('/^\d{' . self::LENGTH . '}$/', $digits) ? $digits : null;
    }

    /**
     * The stored value with expired entries left out.
     *
     * @return array{tries: int, entries: list<array{code: string, token: string, expires: int}>}
     */
    private static function live(mixed $stored, int $now): array
    {
        $tries = is_array($stored) ? (int) ($stored['tries'] ?? 0) : 0;
        $entries = is_array($stored) && is_array($stored['entries'] ?? null) ? $stored['entries'] : [];
        $live = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && (int) ($entry['expires'] ?? 0) > $now) {
                $live[] = [
                    'code' => (string) ($entry['code'] ?? ''),
                    'token' => (string) ($entry['token'] ?? ''),
                    'expires' => (int) $entry['expires'],
                ];
            }
        }

        return ['tries' => [] === $live ? 0 : $tries, 'entries' => $live];
    }

    /**
     * Seconds until the last entry expires.
     *
     * @param array{tries: int, entries: list<array{code: string, token: string, expires: int}>} $stored
     */
    private static function lifetime(array $stored, int $now): int
    {
        $last = $now;

        foreach ($stored['entries'] as $entry) {
            $last = max($last, $entry['expires']);
        }

        return max(1, $last - $now);
    }

    private static function ipAllowed(string $clientIp, int $now): bool
    {
        if ('' === $clientIp) {
            return true;
        }

        $seen = get_transient(self::ipKey($clientIp));

        return ! is_array($seen)
            || (int) ($seen['since'] ?? 0) + self::IP_WINDOW <= $now
            || (int) ($seen['count'] ?? 0) < self::IP_LIMIT;
    }

    private static function countIp(string $clientIp, int $now): void
    {
        if ('' === $clientIp) {
            return;
        }

        $key = self::ipKey($clientIp);
        $seen = get_transient($key);

        if (! is_array($seen) || (int) ($seen['since'] ?? 0) + self::IP_WINDOW <= $now) {
            $seen = ['since' => $now, 'count' => 0];
        }

        $seen['count'] = (int) $seen['count'] + 1;
        set_transient($key, $seen, max(1, (int) $seen['since'] + self::IP_WINDOW - $now));
    }

    private static function key(string $email): string
    {
        return self::PREFIX . substr(hash('sha256', 'eventcrew|' . strtolower(trim($email))), 0, 32);
    }

    private static function ipKey(string $clientIp): string
    {
        return self::IP_PREFIX . substr(hash('sha256', 'eventcrew|' . $clientIp), 0, 32);
    }

    /**
     * A keyed hash: a 6-digit code has too few values for a plain hash to
     * hide it, so the site secret goes in, and the address, so one code
     * hash is no use for another address.
     */
    private static function codeHash(string $email, string $code): string
    {
        $key = function_exists('wp_salt') ? wp_salt('auth') : 'eventcrew-test-key';

        return hash_hmac('sha256', 'login_code|' . strtolower(trim($email)) . '|' . $code, $key);
    }
}
