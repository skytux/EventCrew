<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\MonthlyReportRepository;

/**
 * The Crew app install numbers (Owner 2026-11-13), for the monthly report
 * and the Mobile app box on the Settings page. Counts only, never a name.
 *
 * Per month (site time), people, each at most once a month:
 * - tried to install: they tapped Install the app (op tapped, the browser's
 *   own prompt opens), or the pop-in showed the steps by hand (op
 *   installing: iPhone or iPad, or the browser menu). An install counts as
 *   a try too, so the success rate stays at most 100%;
 * - installed: the browser said it installed the app (op installed, the
 *   appinstalled event), or the app was opened from the home screen for
 *   the first time after the steps by hand (op opened settling
 *   KEY_INSTALL_PENDING), or for the first time ever (KEY_INSTALLED written).
 * Both are SiteStats counters ('app' in the month's option), raised by
 * record() from AppInstallController. Who was counted in which month is
 * the person's KEY_TRIED_MONTH and KEY_INSTALLED_MONTH (the month only).
 *
 * Counting began at SINCE_OPTION (set on the first count). Installs from
 * before it come from each person's first install time (KEY_INSTALLED),
 * counted as tried and installed in that month; record() then skips the
 * counters for a person already counted that way in the same month, so
 * nobody counts twice.
 *
 * As of now, not per month:
 * - active installs: people who opened the app from the home screen in the
 *   last ACTIVE_DAYS days (KEY_LAST_OPEN, written at most once a day);
 * - devices with notifications: push subscriptions a notification reached
 *   in the last ACTIVE_DAYS days (last_ok_at), and all of them. Devices,
 *   not people.
 */
final class AppStats
{
    /** When counting began (site time), set on the first count. */
    public const SINCE_OPTION = 'eventcrew_app_stats_since';

    /** The days "active" and "reached" look back. */
    public const ACTIVE_DAYS = 30;

    public const TRIED = 'tried';
    public const INSTALLED = 'installed';

    private readonly MonthlyReportRepository $data;

    /** @var list<array<string, string>>|null The app entries of everyone, read once. */
    private ?array $notices = null;

    /** @var array<string, array{recent: int, total: int}> By the time looked back to. */
    private array $devices = [];

    public function __construct(?MonthlyReportRepository $data = null)
    {
        $this->data = $data ?? new MonthlyReportRepository();
    }

    /**
     * Counts what the op $op of $person (their record before it) says, now
     * ($siteNow, site time): raises the month's SiteStats counters and
     * returns the role_notices entries that note it. $entries is what
     * AppInstallPrompt::entriesFor() writes for the op.
     *
     * @param array<string, string> $entries
     * @return array<string, string>
     */
    public static function record(string $op, Person $person, array $entries, string $siteNow): array
    {
        $installed = AppInstallPrompt::OP_INSTALLED === $op
            || (AppInstallPrompt::OP_OPENED === $op
                && (isset($entries[AppInstallPrompt::KEY_INSTALLED])
                    || '' === ($entries[AppInstallPrompt::KEY_INSTALL_PENDING] ?? null)));
        $tried = $installed || in_array($op, [AppInstallPrompt::OP_TAPPED, AppInstallPrompt::OP_INSTALLING], true);

        if (! $tried) {
            return [];
        }

        $month = substr($siteNow, 0, 7);
        $since = (string) get_option(self::SINCE_OPTION, '');

        if ('' === $since) {
            add_option(self::SINCE_OPTION, $siteNow, '', false);
            $since = $siteNow;
        }

        // Already counted this month from their first install time.
        $derived = self::derived(
            (string) ($person->roleNotices[AppInstallPrompt::KEY_INSTALLED] ?? ''),
            $month,
            $since
        );
        $notes = [];
        $counts = [
            self::TRIED => [$tried, AppInstallPrompt::KEY_TRIED_MONTH],
            self::INSTALLED => [$installed, AppInstallPrompt::KEY_INSTALLED_MONTH],
        ];

        foreach ($counts as $counter => [$happened, $key]) {
            if (! $happened || $month === (string) ($person->roleNotices[$key] ?? '')) {
                continue;
            }

            $notes[$key] = $month;

            if (! $derived) {
                SiteStats::addApp($counter, $month);
            }
        }

        return $notes;
    }

    /**
     * The month's (Y-m) people who tried to install and who installed.
     *
     * @return array{tried: int, installed: int}
     */
    public function month(string $month): array
    {
        $counted = SiteStats::month($month)['app'];
        $since = (string) get_option(self::SINCE_OPTION, '');
        $derived = 0;

        foreach ($this->notices() as $notices) {
            if (self::derived($notices[AppInstallPrompt::KEY_INSTALLED] ?? '', $month, $since)) {
                $derived++;
            }
        }

        return [
            'tried' => $counted['tried'] + $derived,
            'installed' => $counted['installed'] + $derived,
        ];
    }

    /** The people who opened the app from the home screen in the ACTIVE_DAYS days before $now. */
    public function active(\DateTimeImmutable $now): int
    {
        $from = self::back($now);
        $active = 0;

        foreach ($this->notices() as $notices) {
            $open = $notices[AppInstallPrompt::KEY_LAST_OPEN] ?? '';

            if ('' !== $open && $open >= $from) {
                $active++;
            }
        }

        return $active;
    }

    /**
     * The devices with notifications: reached in the ACTIVE_DAYS days before
     * $now, and all.
     *
     * @return array{recent: int, total: int}
     */
    public function devices(\DateTimeImmutable $now): array
    {
        $from = self::back($now);

        return $this->devices[$from] ??= $this->data->pushDevices($from);
    }

    /**
     * Everything the Settings box shows: this month, last month, and the
     * numbers as of $now.
     *
     * @return array{
     *     this_month: array{tried: int, installed: int},
     *     last_month: array{tried: int, installed: int},
     *     active: int,
     *     devices: array{recent: int, total: int}
     * }
     */
    public function summary(\DateTimeImmutable $now): array
    {
        return [
            'this_month' => $this->month($now->format('Y-m')),
            'last_month' => $this->month($now->modify('first day of last month')->format('Y-m')),
            'active' => $this->active($now),
            'devices' => $this->devices($now),
        ];
    }

    /**
     * Whether an install at $installedAt (site time, '' for none) counts in
     * $month from the install time: in that month, and before counting
     * began at $since ('' when it has not begun).
     */
    private static function derived(string $installedAt, string $month, string $since): bool
    {
        return '' !== $installedAt
            && substr($installedAt, 0, 7) === $month
            && ('' === $since || $installedAt < $since);
    }

    /** The site time ACTIVE_DAYS days before $now. */
    private static function back(\DateTimeImmutable $now): string
    {
        return $now->modify('-' . self::ACTIVE_DAYS . ' days')->format('Y-m-d H:i:s');
    }

    /** @return list<array<string, string>> */
    private function notices(): array
    {
        if (null === $this->notices) {
            $this->notices = [];

            foreach ($this->data->appNotices() as $json) {
                $this->notices[] = Person::decodeNotices($json);
            }
        }

        return $this->notices;
    }
}
