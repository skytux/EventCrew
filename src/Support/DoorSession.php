<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The sales desk's (door page's) login: a one-time link that turns into a
 * signed cookie for one event night.
 *
 * The link carries a raw auth token (purpose 'door') and the night's date,
 * plus a signature over the two, so the date cannot be swapped for another
 * night. Opening it spends the token and leaves a cookie behind that names the
 * person, the night and the latest it can last - 06:00 the morning after the
 * night - signed the same way WebSession signs its cookie. Nothing is stored
 * server-side for the cookie itself. The shorter lifetime of a non-organizer's
 * session (until the event ends) is checked on every
 * request by SalesDeskGate, since it depends on who the person is now.
 *
 * Times are local wall-clock strings, compared against current_time() like
 * every other datetime in the plugin, so "06:00" means 06:00 where the event
 * is.
 */
final class DoorSession
{
    public const COOKIE = 'eventcrew_door';

    /** The auth token purpose a door link is issued under. */
    public const TOKEN_PURPOSE = 'door';

    /**
     * The auth token purpose of a link made on the sales desk page itself
     * ("Other device"): shown as a QR code, it works once and only for
     * HANDOFF_SECONDS. See DoorHandoff.
     */
    public const HANDOFF_PURPOSE = 'door_handoff';

    /** How long a link made on the sales desk page works: 15 minutes. */
    public const HANDOFF_SECONDS = 15 * 60;

    /** The admin-ajax action that serves the page and consumes a link. */
    public const PAGE_ACTION = 'eventcrew_door';

    /** The hour, on the morning after the night, a door session ends. */
    private const ENDS_AT = '06:00:00';

    /**
     * When a session for $date ends: 06:00 local on the following day.
     */
    public static function expiryFor(string $date): string
    {
        return gmdate('Y-m-d', (int) strtotime($date . ' 00:00:00 +1 day')) . ' ' . self::ENDS_AT;
    }

    /**
     * A signed cookie value for a person working the door on $date.
     */
    public static function mint(int $personId, string $date): string
    {
        $body = self::encode($personId . '|' . $date . '|' . self::expiryFor($date));

        return $body . '.' . self::mac('door_session', $body);
    }

    /**
     * The person and night a cookie carries, or null when it is malformed,
     * tampered with, or past its expiry.
     *
     * @return array{person_id: int, date: string}|null
     */
    public static function read(string $cookie): ?array
    {
        $cookie = trim($cookie);
        $dot = strrpos($cookie, '.');

        if (false === $dot) {
            return null;
        }

        $body = substr($cookie, 0, $dot);

        if (! hash_equals(self::mac('door_session', $body), substr($cookie, $dot + 1))) {
            return null;
        }

        $parts = explode('|', self::decode($body));

        if (3 !== count($parts) || ! ctype_digit($parts[0]) || ! self::isDate($parts[1])) {
            return null;
        }

        // The expiry is recomputed from the date rather than trusted as
        // written, so even a correctly signed cookie cannot outlive its night.
        if ($parts[2] !== self::expiryFor($parts[1]) || $parts[2] <= (string) current_time('mysql')) {
            return null;
        }

        return ['person_id' => (int) $parts[0], 'date' => $parts[1]];
    }

    /**
     * The CSRF token for one cookie, embedded in the page and sent back with
     * every POST. Tied to the cookie value, so a token from one session is
     * worthless with another.
     */
    public static function csrfToken(string $cookie): string
    {
        return self::mac('door_csrf', trim($cookie));
    }

    public static function verifyCsrf(string $cookie, string $token): bool
    {
        return '' !== trim($cookie) && hash_equals(self::csrfToken($cookie), trim($token));
    }

    /**
     * The signature that binds a link's token to its night.
     */
    public static function linkSignature(string $rawToken, string $date): string
    {
        return self::mac('door_link', $rawToken . '|' . $date);
    }

    public static function verifyLink(string $rawToken, string $date, string $signature): bool
    {
        return '' !== $rawToken && hash_equals(self::linkSignature($rawToken, $date), trim($signature));
    }

    /**
     * The one-time login link for a raw door token.
     */
    public static function loginUrl(string $rawToken, string $date): string
    {
        return add_query_arg(
            [
                'action' => self::PAGE_ACTION,
                't' => $rawToken,
                'd' => $date,
                's' => self::linkSignature($rawToken, $date),
            ],
            admin_url('admin-ajax.php')
        );
    }

    /** The page's clean address, without a token. */
    public static function pageUrl(): string
    {
        return add_query_arg(['action' => self::PAGE_ACTION], admin_url('admin-ajax.php'));
    }

    /** Whether $value is a real Y-m-d calendar date. */
    public static function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $parsed && $parsed->format('Y-m-d') === $value;
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
