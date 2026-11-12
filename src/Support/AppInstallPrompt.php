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
 *   Home Screen while signed in): kept as history (KEY_INSTALLED) only, since
 *   the app can be removed again (Owner 2026-11-13). Whether it is installed
 *   on this device the browser tells: Chrome, Edge and Samsung Internet fire
 *   beforeinstallprompt only where it is not, and getInstalledRelatedApps()
 *   says where it is (then the page shows "Open in the app" instead). An
 *   iPhone or iPad tells nothing, so each opening of the app from the Home
 *   Screen is recorded (KEY_LAST_OPEN, at most once a day), and Safari
 *   offers the app again once it has not been opened for remindDays() days.
 * Once installed, the app offers "Turn on notifications" once per install
 * (KEY_NOTIFY_OFFERED): a new install (appinstalled, or the first opening
 * after the iPhone steps were shown, KEY_INSTALL_PENDING) clears it again.
 * The script offers it only where this device has no push subscription.
 *
 * Whether this browser can install the app at all only the page's script
 * can tell; the server decides only whether the person is due for it.
 * Signed-in pages are never cached, so the answer is per request.
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

    /** role_notices: when the app was last opened from the Home Screen (site time). */
    public const KEY_LAST_OPEN = 'app:last_open';

    /** role_notices: when the pop-in showed the iPhone steps; the next opening is a new install. */
    public const KEY_INSTALL_PENDING = 'app:install_pending';

    public const KEYS = [
        self::KEY_INSTALLED,
        self::KEY_REMIND_AFTER,
        self::KEY_CLOSED,
        self::KEY_NOTIFY_OFFERED,
        self::KEY_LAST_OPEN,
        self::KEY_INSTALL_PENDING,
    ];

    /** The ops the page posts (AppInstallController). */
    public const OP_CLOSE = 'close';
    public const OP_LATER = 'later';
    public const OP_INSTALLED = 'installed';
    public const OP_OFFERED = 'offered';
    public const OP_INSTALLING = 'installing';
    public const OP_OPENED = 'opened';

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
     * popin: the browser-tab pop-in is due (not closed in this session, no
     * Remind me later pending); the script still shows it only where the app
     * can be installed. installed: the app was installed once (history).
     * ios_due: an iPhone or iPad has not opened the app for remindDays()
     * days, or never, so Safari may offer it again. record_open: the app,
     * opened now, should post OP_OPENED. offer_notify: the app has not yet
     * offered notifications for this install.
     *
     * @return array{popin: bool, installed: bool, ios_due: bool, record_open: bool, offer_notify: bool}
     */
    public static function state(Person $person, ?int $sessionIssuedAt, int $now, ?string $siteNow = null): array
    {
        $record = $person->roleNotices;
        $siteNow ??= (string) current_time('mysql');
        $installed = self::has($person, self::KEY_INSTALLED);
        $pending = self::has($person, self::KEY_INSTALL_PENDING);
        $sinceOpen = self::secondsSince($person, self::KEY_LAST_OPEN, $siteNow);

        return [
            'popin' => null !== $sessionIssuedAt
                && $now >= (int) ($record[self::KEY_REMIND_AFTER] ?? 0)
                && ! in_array($sessionIssuedAt, self::closedSessions($person), true),
            'installed' => $installed,
            'ios_due' => null === $sinceOpen || $sinceOpen >= self::remindDays() * DAY_IN_SECONDS,
            'record_open' => ! $installed || $pending || null === $sinceOpen || $sinceOpen >= DAY_IN_SECONDS,
            'offer_notify' => $pending || ! self::has($person, self::KEY_NOTIFY_OFFERED),
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
                // The browser's appinstalled: a new install on this device.
                return self::newInstall($person, $siteNow);

            case self::OP_INSTALLING:
                // The iPhone steps were shown: the next opening is a new install.
                return [self::KEY_INSTALL_PENDING => $siteNow];

            case self::OP_OPENED:
                $entries = self::has($person, self::KEY_INSTALL_PENDING) ? self::newInstall($person, $siteNow) : [];

                if (! self::has($person, self::KEY_INSTALLED)) {
                    $entries[self::KEY_INSTALLED] = $siteNow;
                }

                $sinceOpen = self::secondsSince($person, self::KEY_LAST_OPEN, $siteNow);

                if (null === $sinceOpen || $sinceOpen >= DAY_IN_SECONDS) {
                    $entries[self::KEY_LAST_OPEN] = $siteNow;
                }

                return $entries;

            case self::OP_OFFERED:
                return self::has($person, self::KEY_NOTIFY_OFFERED)
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

    /**
     * A new install: the first install time is kept, the notifications
     * offer comes back once, and a pending iPhone install is done.
     *
     * @return array<string, string>
     */
    private static function newInstall(Person $person, string $siteNow): array
    {
        $entries = [];

        if (! self::has($person, self::KEY_INSTALLED)) {
            $entries[self::KEY_INSTALLED] = $siteNow;
        }

        if (self::has($person, self::KEY_NOTIFY_OFFERED)) {
            $entries[self::KEY_NOTIFY_OFFERED] = '';
        }

        if (self::has($person, self::KEY_INSTALL_PENDING)) {
            $entries[self::KEY_INSTALL_PENDING] = '';
        }

        return $entries;
    }

    private static function has(Person $person, string $key): bool
    {
        return '' !== (string) ($person->roleNotices[$key] ?? '');
    }

    /** Seconds from a stored site time to $siteNow, or null when none is stored. */
    private static function secondsSince(Person $person, string $key, string $siteNow): ?int
    {
        if (! self::has($person, $key)) {
            return null;
        }

        $then = strtotime((string) $person->roleNotices[$key]);
        $now = strtotime($siteNow);

        return false === $then || false === $now ? null : $now - $then;
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
