<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;

/**
 * The "Get the app" pop-in on the Crew app page (Owner 2026-11-12): who
 * sees it, and what each of its buttons stores.
 *
 * Shown to a signed-in person in a browser tab who has not installed the
 * app, right after a sign-in and on later visits, until they act:
 * - Close (×, Esc): not again until the next sign-in. The issue time of the
 *   session that closed it is stored (KEY_CLOSED); a new sign-in mints a
 *   cookie with another issue time and the pop-in is back. The daily cookie
 *   renewal carries a closed session's time over to the new cookie
 *   (carryOver()), or a close would last only a day.
 * - Remind me later: not again for remindDays() days (KEY_REMIND_AFTER),
 *   on any device or sign-in.
 * - Installed (the browser's appinstalled event, or the app opened from the
 *   Home Screen while signed in): never again (KEY_INSTALLED).
 * Once installed, the app offers "Turn on notifications" once
 * (KEY_NOTIFY_OFFERED).
 *
 * Whether this browser can install the app at all (an iPhone or iPad, or a
 * browser that fired beforeinstallprompt) only the page's script can tell;
 * the server decides only whether the person is due for it. Signed-in pages
 * are never cached, so the answer is per request.
 *
 * Kept in the person's role_notices record (the per-person key => value
 * record that also holds the newcomer request), so no schema change.
 */
final class AppInstallPrompt
{
    /** The setting: how many days Remind me later hides the pop-in. */
    public const REMIND_DAYS_OPTION = 'eventcrew_app_remind_days';

    public const DEFAULT_REMIND_DAYS = 14;
    public const MAX_REMIND_DAYS = 365;

    /** role_notices: when the app was installed (site time). */
    public const KEY_INSTALLED = 'app:installed';

    /** role_notices: the timestamp before which Remind me later hides it. */
    public const KEY_REMIND_AFTER = 'app:remind_after';

    /** role_notices: the issue times of the sessions that closed it, comma-separated. */
    public const KEY_CLOSED = 'app:closed';

    /** role_notices: when the installed app offered to turn on notifications. */
    public const KEY_NOTIFY_OFFERED = 'app:notify_offered';

    public const KEYS = [self::KEY_INSTALLED, self::KEY_REMIND_AFTER, self::KEY_CLOSED, self::KEY_NOTIFY_OFFERED];

    /** The ops the page posts (AppInstallController). */
    public const OP_CLOSE = 'close';
    public const OP_LATER = 'later';
    public const OP_INSTALLED = 'installed';
    public const OP_OFFERED = 'offered';

    /** How many closed sessions are remembered: a few devices at once. */
    private const CLOSED_KEEP = 5;

    /** The Remind me later days, as saved, else the default. */
    public static function remindDays(): int
    {
        return self::sanitizeRemindDays(get_option(self::REMIND_DAYS_OPTION, self::DEFAULT_REMIND_DAYS));
    }

    /**
     * A whole number of days, 1 to MAX_REMIND_DAYS; anything not a number
     * (or blank) is the default.
     */
    public static function sanitizeRemindDays(mixed $raw): int
    {
        if (is_string($raw)) {
            $raw = trim($raw);
        }

        if (! is_numeric($raw)) {
            return self::DEFAULT_REMIND_DAYS;
        }

        return max(1, min(self::MAX_REMIND_DAYS, (int) $raw));
    }

    public static function saveRemindDays(mixed $raw): void
    {
        update_option(self::REMIND_DAYS_OPTION, self::sanitizeRemindDays($raw), false);
    }

    /**
     * What the page's script needs for this person on this request.
     *
     * popin: the browser-tab pop-in is due (the script still shows it only
     * where the app can be installed). installed: the app was installed.
     * offer_notify: the installed app has not yet offered notifications.
     *
     * @return array{popin: bool, installed: bool, offer_notify: bool}
     */
    public static function state(Person $person, ?int $sessionIssuedAt, int $now): array
    {
        $record = $person->roleNotices;
        $installed = '' !== (string) ($record[self::KEY_INSTALLED] ?? '');

        return [
            'popin' => ! $installed
                && null !== $sessionIssuedAt
                && $now >= (int) ($record[self::KEY_REMIND_AFTER] ?? 0)
                && ! in_array($sessionIssuedAt, self::closedSessions($person), true),
            'installed' => $installed,
            'offer_notify' => '' === (string) ($record[self::KEY_NOTIFY_OFFERED] ?? ''),
        ];
    }

    /**
     * The role_notices entries an op writes, or null for an op it does not
     * know. Close needs the session's issue time; without one it is null.
     *
     * @return array<string, string>|null
     */
    public static function entriesFor(
        string $op,
        Person $person,
        ?int $sessionIssuedAt,
        int $now,
        string $siteNow,
        int $remindDays
    ): ?array {
        switch ($op) {
            case self::OP_CLOSE:
                if (null === $sessionIssuedAt) {
                    return null;
                }

                return [self::KEY_CLOSED => self::encodeClosed(
                    array_merge(self::closedSessions($person), [$sessionIssuedAt])
                )];

            case self::OP_LATER:
                return [self::KEY_REMIND_AFTER => (string) ($now + $remindDays * DAY_IN_SECONDS)];

            case self::OP_INSTALLED:
                // The first time counts; an app opened again changes nothing.
                return '' !== (string) ($person->roleNotices[self::KEY_INSTALLED] ?? '')
                    ? []
                    : [self::KEY_INSTALLED => $siteNow];

            case self::OP_OFFERED:
                return '' !== (string) ($person->roleNotices[self::KEY_NOTIFY_OFFERED] ?? '')
                    ? []
                    : [self::KEY_NOTIFY_OFFERED => $siteNow];
        }

        return null;
    }

    /**
     * The KEY_CLOSED entry after a cookie renewal: the old cookie's issue
     * time swapped for the new one's, so a close lasts until the next real
     * sign-in. Null when the old session never closed the pop-in (nothing to
     * write).
     *
     * @return array<string, string>|null
     */
    public static function carryOver(Person $person, int $oldIssuedAt, int $newIssuedAt): ?array
    {
        $closed = self::closedSessions($person);

        if (! in_array($oldIssuedAt, $closed, true)) {
            return null;
        }

        $closed = array_values(array_diff($closed, [$oldIssuedAt]));
        $closed[] = $newIssuedAt;

        return [self::KEY_CLOSED => self::encodeClosed($closed)];
    }

    /** @return list<int> */
    private static function closedSessions(Person $person): array
    {
        $raw = (string) ($person->roleNotices[self::KEY_CLOSED] ?? '');
        $times = [];

        foreach (explode(',', $raw) as $part) {
            if (ctype_digit($part)) {
                $times[] = (int) $part;
            }
        }

        return $times;
    }

    /** @param list<int> $times */
    private static function encodeClosed(array $times): string
    {
        $times = array_values(array_unique($times));

        return implode(',', array_slice($times, -self::CLOSED_KEEP));
    }
}
