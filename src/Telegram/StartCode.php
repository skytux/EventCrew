<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\TelegramLogin;

/**
 * The weekly code in the bot's deep links (Owner, 2026-11-07, Q354-Q355).
 *
 * Every t.me/<bot>?start=... link the plugin builds for an email, a web page
 * or the group board ends in "_<code>", e.g. task-12_3fa9c01b. The code is
 * the first 8 hex characters of an HMAC of the ISO week with the site's
 * secret salt, so it changes every Monday (UTC). This week's and last week's
 * codes are both accepted, so a link never dies at the moment of the switch.
 *
 * The code only matters to PrivateChatGate: someone who is not in the crew
 * group and opens the bot with a valid code gets the button to the group;
 * without a code they get no answer. The code is added only while the gate
 * is on, so links stay as they were when it is off.
 */
final class StartCode
{
    /** Between the payload and the code. */
    public const SEPARATOR = '_';

    /** Hex characters in a code. */
    public const LENGTH = 8;

    /** The code of the week $now falls in (default: now). */
    public static function current(?int $now = null): string
    {
        return self::forWeek(gmdate('o-W', $now ?? time()));
    }

    /** True for this week's or last week's code. */
    public static function isValid(string $code, ?int $now = null): bool
    {
        if (1 !== preg_match('/^[0-9a-f]{' . self::LENGTH . '}$/', $code)) {
            return false;
        }

        $now ??= time();

        return hash_equals(self::current($now), $code)
            || hash_equals(self::current($now - 7 * DAY_IN_SECONDS), $code);
    }

    /**
     * $payload with this week's code on the end, while the gate is on. The
     * payload comes back unchanged when the gate is off, when it is a sign-in
     * payload (it carries its own secret), or when the code would make it
     * longer than Telegram allows.
     */
    public static function append(string $payload): string
    {
        if (! PrivateChatGate::isActive() || str_starts_with($payload, TelegramLogin::PAYLOAD_PREFIX)) {
            return $payload;
        }

        $withCode = ('' === $payload ? 'onboard' : $payload) . self::SEPARATOR . self::current();

        return strlen($withCode) <= 64 ? $withCode : $payload;
    }

    /**
     * A start payload split into the payload the bot handles and the code
     * on its end: ['task-12', '3fa9c01b'], or [$payload, ''] when there is
     * no code. Sign-in payloads are never split.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $payload): array
    {
        if (
            str_starts_with($payload, TelegramLogin::PAYLOAD_PREFIX)
            || 1 !== preg_match('/^(.*)' . self::SEPARATOR . '([0-9a-f]{' . self::LENGTH . '})$/', $payload, $m)
        ) {
            return [$payload, ''];
        }

        return [$m[1], $m[2]];
    }

    private static function forWeek(string $week): string
    {
        $key = function_exists('wp_salt') ? wp_salt('auth') : 'eventcrew-test-key';

        return substr(hash_hmac('sha256', 'eventcrew-start-code|' . $week, $key), 0, self::LENGTH);
    }
}
