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
     *
     * The issue time is carried as well as the expiry, so a cookie can be
     * compared against a person's revocation stamp. Without it "sign out
     * everywhere" is impossible for a stateless cookie: there is nothing to
     * distinguish one minted before the revocation from one minted after.
     */
    public static function mint(int $personId, ?int $now = null): string
    {
        $issued = $now ?? time();
        $body = self::encode($personId . '|' . $issued . '|' . ($issued + self::TTL));

        return $body . '.' . self::mac('web_session', $body);
    }

    /**
     * The person id a cookie carries, or null when it is malformed, tampered
     * with, or past its expiry. Revocation is not checked here - that needs the
     * person row, which the caller has; see issuedAt().
     */
    public static function read(string $cookie, ?int $now = null): ?int
    {
        return self::parse($cookie, $now)['person_id'];
    }

    /**
     * When the cookie was issued, as a timestamp, or null when it is not valid.
     *
     * A cookie minted before this version has no issue time and answers 0,
     * which is older than any revocation stamp and so is revoked by the first
     * "sign out everywhere" - but until then it keeps working. Upgrading must
     * not sign out an entire crew to gain a button none of them has pressed.
     */
    public static function issuedAt(string $cookie, ?int $now = null): ?int
    {
        return self::parse($cookie, $now)['issued_at'];
    }

    /**
     * @return array{person_id: int|null, issued_at: int|null}
     */
    private static function parse(string $cookie, ?int $now = null): array
    {
        $empty = ['person_id' => null, 'issued_at' => null];

        $cookie = trim($cookie);
        $dot = strrpos($cookie, '.');

        if (false === $dot) {
            return $empty;
        }

        $body = substr($cookie, 0, $dot);
        $signature = substr($cookie, $dot + 1);

        if (! hash_equals(self::mac('web_session', $body), $signature)) {
            return $empty;
        }

        $parts = explode('|', self::decode($body));

        foreach ($parts as $part) {
            if (! ctype_digit($part)) {
                return $empty;
            }
        }

        // Two parts is the pre-revocation format: person and expiry, no issue
        // time. Three is the current one.
        $issued = match (count($parts)) {
            2 => 0,
            3 => (int) $parts[1],
            default => null,
        };

        if (null === $issued) {
            return $empty;
        }

        $expiry = (int) $parts[count($parts) - 1];

        if ($expiry < ($now ?? time())) {
            return $empty;
        }

        return ['person_id' => (int) $parts[0], 'issued_at' => $issued];
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
