<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * One value that changes whenever something the Crew app shows changes
 * (Owner 2026-11-14, live Crew app): an open page asks for it every few
 * seconds and fetches its tabs again only when it moved.
 *
 * Every write to one of the tables the app draws from marks the request;
 * at its end the stamp is saved once. The query filter sees writes from
 * every path (the bot, the Crew app, wp-admin, cron), so no caller has to
 * remember to bump it. Logs, queues, tokens and the chat are left out:
 * they change all the time and the tabs do not show them.
 */
final class ChangeStamp
{
    public const OPTION = 'eventcrew_change_stamp';

    /** The tables whose rows the Crew app's tabs show. */
    private const TABLES = [
        'assignments',
        'checkins',
        'credit_grants',
        'door_notes',
        'guests',
        'people',
        'redemptions',
        'takeovers',
        'tasks',
        'ticket_buyers',
        'ticket_transfers',
    ];

    private static bool $dirty = false;

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        add_filter('query', [self::class, 'watch']);
        add_action('shutdown', [self::class, 'save']);
    }

    /**
     * The 'query' filter: notes a write to one of TABLES, and passes the
     * query on unchanged.
     */
    public static function watch(mixed $query): mixed
    {
        if (self::$dirty || ! is_string($query) || ! self::isWrite($query)) {
            return $query;
        }

        self::$dirty = true;

        return $query;
    }

    /** Whether $query writes one of TABLES. */
    public static function isWrite(string $query): bool
    {
        global $wpdb;

        $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
        $pattern = '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b[^`\'"]*?[`\s]'
            . preg_quote($prefix, '/') . 'eventcrew_(' . implode('|', self::TABLES) . ')[`\s(]/i';

        return 1 === preg_match($pattern, $query . ' ');
    }

    /** Saves a new stamp when this request wrote anything: once, at its end. */
    public static function save(): void
    {
        if (! self::$dirty) {
            return;
        }

        self::$dirty = false;
        self::bump();
    }

    /** A new stamp now. */
    public static function bump(): void
    {
        update_option(self::OPTION, self::next(), false);
    }

    /** The current stamp; '0' before anything was written. */
    public static function current(): string
    {
        $value = get_option(self::OPTION, '0');

        return is_scalar($value) && '' !== (string) $value ? (string) $value : '0';
    }

    /** Whether a write was seen and not saved yet (for tests). */
    public static function pending(): bool
    {
        return self::$dirty;
    }

    /** Forgets an unsaved write (for tests). */
    public static function reset(): void
    {
        self::$dirty = false;
    }

    private static function next(): string
    {
        return str_replace('.', '', sprintf('%.4F', microtime(true))) . random_int(10, 99);
    }
}
