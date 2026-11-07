<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * A demo pair to try the ticket's live "you're in" band without any real
 * data (Owner, 2026-11-07): a ticket for "Demo Guest" and a one-row sales desk
 * that ticks it in and out (Web\DemoController).
 *
 * The whole demo is one option: a random id, when it ends and when the desk
 * ticked it in. Nothing goes into the people, ticket or check-ins tables, so
 * no real door list, count, export or email can ever show it. A demo lasts
 * TTL seconds; links() starts a new one when the last has ended, and moves
 * the end of a running one TTL ahead.
 *
 * Its two links are signed (SignedLink) with the demo's id, one purpose for
 * each page, so the ticket link can never tick itself in. Both work with no
 * login: the desk only flips this demo's flag.
 */
final class DemoTicket
{
    public const OPTION = 'eventcrew_demo_ticket';

    /** How long a demo lasts, in seconds. */
    public const TTL = DAY_IN_SECONDS;

    /** The holder's name on the ticket and the desk row. */
    public const NAME = 'Demo Guest';

    public const TICKET_PURPOSE = 'demo_ticket';
    public const DESK_PURPOSE = 'demo_desk';

    /** The REST routes of the two pages (Web\DemoController). */
    public const TICKET_ROUTE = '/demo/ticket';
    public const DESK_ROUTE = '/demo/desk';
    public const STATE_ROUTE = '/demo/state';

    /**
     * The two links of the running demo, starting one when none is running.
     *
     * @return array{ticket: string, desk: string}
     */
    public static function links(): array
    {
        $demo = self::current();
        $now = self::now();

        if (null === $demo) {
            $demo = ['id' => random_int(1, 2147483647), 'expires' => $now + self::TTL, 'in' => ''];
            self::save($demo);
        } elseif ($demo['expires'] - $now < self::TTL - HOUR_IN_SECONDS) {
            // Kept running while someone asks for its links, written at most
            // once an hour.
            $demo['expires'] = $now + self::TTL;
            self::save($demo);
        }

        return [
            'ticket' => self::url(self::TICKET_ROUTE, SignedLink::sign(self::TICKET_PURPOSE, $demo['id'])),
            'desk' => self::url(self::DESK_ROUTE, SignedLink::sign(self::DESK_PURPOSE, $demo['id'])),
        ];
    }

    /**
     * The running demo when $token is its link for $purpose, else null (a bad
     * or other-purpose link, or a demo that has ended or been replaced).
     *
     * @return array{id: int, expires: int, in: string}|null
     */
    public static function forToken(string $purpose, string $token): ?array
    {
        $id = SignedLink::verify($purpose, $token);
        $demo = null === $id ? null : self::current();

        return null !== $demo && $demo['id'] === $id ? $demo : null;
    }

    /** The demo ticket's short code, written under its QR and on its desk row. */
    public static function shortCode(int $id): string
    {
        return ShortCode::forDemo($id);
    }

    /**
     * Ticks the demo in ($in) or out from the desk. False when $token is not
     * the running demo's desk link.
     */
    public static function mark(string $token, bool $in): bool
    {
        $demo = self::forToken(self::DESK_PURPOSE, $token);

        if (null === $demo) {
            return false;
        }

        $demo['in'] = $in ? (string) current_time('mysql') : '';
        self::save($demo);

        return true;
    }

    /**
     * The running demo, read fresh from the database each time (the open
     * ticket asks once a second while it waits), or null when there is none
     * or it has ended.
     *
     * @return array{id: int, expires: int, in: string}|null
     */
    public static function current(): ?array
    {
        // A long wait reads this once a second: drop the request's cached
        // copy so each read sees what the desk wrote.
        wp_cache_delete(self::OPTION, 'options');
        $value = get_option(self::OPTION, null);

        if (! is_array($value) || ! isset($value['id'], $value['expires'])) {
            return null;
        }

        $demo = [
            'id' => (int) $value['id'],
            'expires' => (int) $value['expires'],
            'in' => (string) ($value['in'] ?? ''),
        ];

        return $demo['id'] > 0 && $demo['expires'] > self::now() ? $demo : null;
    }

    /** @param array{id: int, expires: int, in: string} $demo */
    private static function save(array $demo): void
    {
        // Not autoloaded: it is read only by the demo pages.
        update_option(self::OPTION, $demo, false);
    }

    private static function url(string $route, string $token): string
    {
        return add_query_arg(['token' => $token], rest_url('eventcrew/v1' . $route));
    }

    private static function now(): int
    {
        return (int) current_time('timestamp');
    }
}
