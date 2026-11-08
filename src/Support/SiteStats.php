<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The two counters behind the monthly report's click and campaign rows
 * (Owner, Q385 + Q386): how often each email button was clicked
 * (EmailClicks) and how many page views came in from a campaign link
 * (CampaignVisits). Counts only: no names, no addresses, no IP addresses,
 * no cookies.
 *
 * One option per month (site time), never autoloaded:
 * eventcrew_stats_2026_11 => [
 *     'clicks' => [email key => [button key => n]],
 *     'campaigns' => [campaign => n],
 *     'visits' => n,
 * ]
 * A plain read, add one, write: two clicks in the same instant can lose
 * one, which a monthly count can live with. The number of different
 * email · button pairs and campaigns a month keeps is capped; the rest are
 * counted under OTHER, so a link shared with made-up campaign names cannot
 * grow the option without end. The monthly report drops months older than
 * KEEP_MONTHS (prune()).
 */
final class SiteStats
{
    /** The option of one month is this plus Y_m. */
    public const PREFIX = 'eventcrew_stats_';

    /** The key the counts beyond the caps go under. */
    public const OTHER = 'other';

    /** The most different campaigns one month keeps. */
    public const MAX_CAMPAIGNS = 50;

    /** The most different email · button pairs one month keeps. */
    public const MAX_CLICK_PAIRS = 60;

    /** How many months back the counts are kept, the current one not counted. */
    public const KEEP_MONTHS = 13;

    /** The longest campaign name kept. */
    public const CAMPAIGN_MAX = 40;

    /** A user agent with one of these in it is a robot, a link checker or a preview fetcher. */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|scan|preview|fetch|monitor|check|curl|wget|python|java\/|'
        . 'go-http|okhttp|axios|headless|phantom|lighthouse|facebookexternalhit|whatsapp|proofpoint|'
        . 'mimecast|barracuda|https?:/i';

    /** The option name of $month (Y-m). */
    public static function option(string $month): string
    {
        return self::PREFIX . str_replace('-', '_', $month);
    }

    /**
     * The counts of $month (Y-m), empty when nothing was counted.
     *
     * @return array{clicks: array<string, array<string, int>>, campaigns: array<string, int>, visits: int}
     */
    public static function month(string $month): array
    {
        $stored = get_option(self::option($month), []);
        $stored = is_array($stored) ? $stored : [];
        $clicks = [];

        foreach ((array) ($stored['clicks'] ?? []) as $mail => $buttons) {
            foreach ((array) $buttons as $button => $count) {
                $clicks[(string) $mail][(string) $button] = (int) $count;
            }
        }

        $campaigns = [];

        foreach ((array) ($stored['campaigns'] ?? []) as $name => $count) {
            $campaigns[(string) $name] = (int) $count;
        }

        return [
            'clicks' => $clicks,
            'campaigns' => $campaigns,
            'visits' => (int) ($stored['visits'] ?? 0),
        ];
    }

    /** Counts one click on $button of the email $mail. */
    public static function addClick(string $mail, string $button, ?\DateTimeImmutable $now = null): void
    {
        $month = self::currentMonth($now);
        $data = self::month($month);

        if (! isset($data['clicks'][$mail][$button])) {
            $pairs = array_sum(array_map('count', $data['clicks']));

            if ($pairs >= self::MAX_CLICK_PAIRS) {
                $mail = self::OTHER;
                $button = self::OTHER;
            }
        }

        $data['clicks'][$mail][$button] = ($data['clicks'][$mail][$button] ?? 0) + 1;
        self::save($month, $data);
    }

    /** Counts one page view that came from the campaign link $campaign (clean, campaignName()). */
    public static function addVisit(string $campaign, ?\DateTimeImmutable $now = null): void
    {
        $month = self::currentMonth($now);
        $data = self::month($month);

        if (! isset($data['campaigns'][$campaign]) && count($data['campaigns']) >= self::MAX_CAMPAIGNS) {
            $campaign = self::OTHER;
        }

        $data['campaigns'][$campaign] = ($data['campaigns'][$campaign] ?? 0) + 1;
        $data['visits']++;
        self::save($month, $data);
    }

    /**
     * A campaign name as it is kept: lower case, only a-z, 0-9, _ and -, at
     * most CAMPAIGN_MAX characters. '' when nothing is left.
     */
    public static function campaignName(string $raw): string
    {
        $name = (string) preg_replace('/[^a-z0-9_-]+/', '', strtolower(trim($raw)));

        return substr($name, 0, self::CAMPAIGN_MAX);
    }

    /** Whether $userAgent is a robot, a link checker or a preview fetcher (or there is none). */
    public static function isBot(string $userAgent): bool
    {
        $userAgent = trim($userAgent);

        return '' === $userAgent || 1 === preg_match(self::BOT_PATTERN, $userAgent);
    }

    /**
     * Drops the counts of the months before the last KEEP_MONTHS, looking a
     * year further back than that, so a few missed runs leave nothing behind.
     */
    public static function prune(?\DateTimeImmutable $now = null): void
    {
        if (! function_exists('delete_option')) {
            return;
        }

        $first = new \DateTimeImmutable(self::currentMonth($now) . '-01', self::timezone());

        for ($back = self::KEEP_MONTHS + 1; $back <= self::KEEP_MONTHS + 12; $back++) {
            delete_option(self::option($first->modify('-' . $back . ' months')->format('Y-m')));
        }
    }

    /** @param array{clicks: array<string, array<string, int>>, campaigns: array<string, int>, visits: int} $data */
    private static function save(string $month, array $data): void
    {
        // Not autoloaded: read only when counting and by the monthly report.
        update_option(self::option($month), $data, false);
    }

    private static function currentMonth(?\DateTimeImmutable $now): string
    {
        return ($now ?? new \DateTimeImmutable('now', self::timezone()))->format('Y-m');
    }

    private static function timezone(): \DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    }
}
