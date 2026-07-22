<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The web signup's "stay signed in" cookie: a stateless, HMAC-signed token
 * carrying a person id and an expiry, in the same spirit as SignedLink but with
 * a lifetime baked in.
 *
 * Nothing is stored server-side - the cookie is self-describing and
 * self-authenticating, valid until it expires or the person row is gone, and
 * unforgeable without the site's secret keys. A separate per-person CSRF token,
 * signed the same way, guards the POST actions the cookie authorises, since a
 * logged-out visitor has no WordPress nonce.
 */
final class WebSession
{
    public const COOKIE = 'eventcrew_session';

    /** How long a web sign-in lasts. */
    public const TTL = 30 * DAY_IN_SECONDS;

    /**
     * A signed cookie value for a person, good for TTL from now.
     */
    public static function mint(int $personId, ?int $now = null): string
    {
        $expiry = ($now ?? time()) + self::TTL;
        $body = self::encode($personId . '|' . $expiry);

        return $body . '.' . self::mac('web_session', $body);
    }

    /**
     * The person id a cookie carries, or null when it is malformed, tampered
     * with, or past its expiry.
     */
    public static function read(string $cookie, ?int $now = null): ?int
    {
        $cookie = trim($cookie);
        $dot = strrpos($cookie, '.');

        if (false === $dot) {
            return null;
        }

        $body = substr($cookie, 0, $dot);
        $signature = substr($cookie, $dot + 1);

        if (! hash_equals(self::mac('web_session', $body), $signature)) {
            return null;
        }

        $parts = explode('|', self::decode($body));

        if (2 !== count($parts) || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        if ((int) $parts[1] < ($now ?? time())) {
            return null;
        }

        return (int) $parts[0];
    }

    /**
     * A stable per-person token for the claim/drop forms. An attacker cannot
     * compute it without the site secret, so a form carrying it could only have
     * come from a page we rendered for this person.
     */
    public static function csrfToken(int $personId): string
    {
        return self::mac('web_csrf', (string) $personId);
    }

    public static function verifyCsrf(int $personId, string $token): bool
    {
        return hash_equals(self::csrfToken($personId), trim($token));
    }

    private static function mac(string $purpose, string $body): string
    {
        $key = function_exists('wp_salt') ? wp_salt('auth') : 'eventcrew-test-key';

        return hash_hmac('sha256', $purpose . '|' . $body, $key);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
