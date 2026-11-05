<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Database\Schema;
use EventCrew\Repositories\NewsletterHistoryRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;

/**
 * The Newsletter page: how many addresses are subscribed, pending and
 * unsubscribed; a chart of joins and leaves over a chosen time range, with a
 * bar per day, week or month and each sent newsletter in the range marked on
 * it; and the list of sent newsletters, each with a link
 * that opens the kept copy of the email in the browser. Read-only, and only
 * counts: no address or name is shown.
 *
 * The history (joins, leaves, sent newsletters) is recorded from schema 30 on;
 * schema 31 added the older joins from the subscribers' confirmed_at. The page
 * says from which day each is known.
 */
final class NewsletterPage
{
    public const PAGE_SLUG = 'eventcrew-newsletter';

    /** The admin-post action that shows one kept copy. */
    public const COPY_ACTION = 'eventcrew_newsletter_copy';

    public const PER_DAY = 'day';

    public const PER_WEEK = 'week';

    public const PER_MONTH = 'month';

    /** Up to this many days in the range the chart has a bar per day. */
    public const DAY_LIMIT = 62;

    /** Up to this many days a bar per week (53 bars at most); above it, per month. */
    public const WEEK_LIMIT = 371;

    /** The time ranges to choose from, as months back from today; the first is the default. */
    public const RANGES = ['1m' => 1, '6m' => 6, '1y' => 12, '3y' => 36];

    /** A range given as from and to dates. */
    public const RANGE_CUSTOM = 'custom';

    /** A custom range is cut to this many years, so the chart stays a sensible size. */
    private const MAX_YEARS = 10;

    private const WIDTH = 800;
    private const HEIGHT = 260;
    private const LEFT = 40;
    private const RIGHT = 12;
    private const TOP = 24;
    private const BOTTOM = 32;

    public const COLOR_SUBSCRIBED = '#2271b1';
    public const COLOR_UNSUBSCRIBED = '#d63638';
    public const COLOR_SEND = '#787c82';

    public function __construct(
        private readonly View $view,
        private readonly NewsletterSubscriberRepository $subscribers,
        private readonly NewsletterHistoryRepository $history
    ) {
    }

    public function render(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only choice, changes nothing.
        $get = static fn (string $key): string => isset($_GET[$key])
            ? sanitize_text_field(wp_unslash((string) $_GET[$key]))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $this->view->render('newsletter', $this->data($get('range'), $get('from'), $get('to')));
    }

    /**
     * Everything the page shows. $range is a key of RANGES, or RANGE_CUSTOM
     * with $from and $to (Y-m-d); anything else is the default range. The
     * chart, its newsletter markers, the totals and the list of sent
     * newsletters cover the range only; each newsletter keeps its number from
     * the whole history. any_sent is whether any newsletter was ever sent.
     *
     * @return array{
     *     counts: array{confirmed: int, pending: int, unsubscribed: int},
     *     since: string,
     *     joins_since: ?string,
     *     today: string,
     *     range: string,
     *     from: string,
     *     to: string,
     *     per: string,
     *     has_history: bool,
     *     chart: string,
     *     totals: array{joins: int, leaves: int, newsletters: int},
     *     sends: list<array{number: int, id: int, at: string, lang: string, subject: string,
     *         sent: int, failed: int, url: string}>,
     *     any_sent: bool
     * }
     */
    public function data(string $range, string $from = '', string $to = ''): array
    {
        $today = (string) current_time('Y-m-d');
        $since = self::validDay((string) get_option(Schema::NEWSLETTER_HISTORY_SINCE_OPTION, '')) ?? $today;
        $since = min($since, $today);
        [$range, $from, $to] = self::range($range, $from, $to, $today);
        $per = self::perFor($from, $to);

        $changes = $this->history->changesPerDay($from, $to);
        $sentList = $this->history->sentList();
        $numbers = [];
        $sends = [];

        foreach ($sentList as $row) {
            $numbers[$row['plan_id']] ??= count($numbers) + 1;
            $sends[] = [
                'number' => $numbers[$row['plan_id']],
                'id' => $row['id'],
                'at' => $row['sent_at'],
                'lang' => $row['lang'],
                'subject' => $row['subject'],
                'sent' => $row['sent'],
                'failed' => $row['failed'],
                'url' => self::copyUrl($row['id']),
            ];
        }

        // The markers and the list: only the newsletters sent within the range.
        // The numbers stay those of the whole history.
        $inRange = array_values(array_filter(
            $sends,
            static fn (array $send): bool => substr($send['at'], 0, 10) >= $from && substr($send['at'], 0, 10) <= $to
        ));
        $hasHistory = [] !== $changes || [] !== $inRange;
        $totals = [
            'joins' => array_sum(array_column($changes, 'subscribed')),
            'leaves' => array_sum(array_column($changes, 'unsubscribed')),
            'newsletters' => count(array_unique(array_column($inRange, 'number'))),
        ];

        return [
            'counts' => $this->subscribers->countByStatus(),
            'since' => $since,
            'joins_since' => $this->history->firstJoinDay(),
            'today' => $today,
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'per' => $per,
            'has_history' => $hasHistory,
            'chart' => $hasHistory ? self::chart(self::buckets($from, $to, $per, $changes), $inRange, $per) : '',
            'totals' => $totals,
            'sends' => $inRange,
            'any_sent' => [] !== $sends,
        ];
    }

    /**
     * The range to show, as [range key, from, to] (Y-m-d, both days
     * included). A preset ends today and starts the day after the same date
     * that many months back. A custom range needs a valid from day; a missing
     * or invalid to day is today, a day after today is today, from and to
     * the wrong way round are swapped, and a range longer than MAX_YEARS is
     * cut at its start. Anything else is the default preset.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function range(string $range, string $from, string $to, string $today): array
    {
        $from = self::validDay($from);

        if (self::RANGE_CUSTOM === $range && null !== $from) {
            $to = min(self::validDay($to) ?? $today, $today);
            $from = min($from, $today);

            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }

            $earliest = (new \DateTimeImmutable(self::monthsBack($to, 12 * self::MAX_YEARS)))
                ->modify('+1 day')
                ->format('Y-m-d');

            return [self::RANGE_CUSTOM, max($from, $earliest), $to];
        }

        if (! isset(self::RANGES[$range])) {
            $range = (string) array_key_first(self::RANGES);
        }

        $start = (new \DateTimeImmutable(self::monthsBack($today, self::RANGES[$range])))->modify('+1 day');

        return [$range, $start->format('Y-m-d'), $today];
    }

    /** A bar per day up to DAY_LIMIT days, per week up to WEEK_LIMIT days, else per month. */
    public static function perFor(string $from, string $to): string
    {
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;

        if ($days <= self::DAY_LIMIT) {
            return self::PER_DAY;
        }

        return $days <= self::WEEK_LIMIT ? self::PER_WEEK : self::PER_MONTH;
    }

    /** The same day $months months before $day (Y-m-d), or that month's last day when it is shorter. */
    private static function monthsBack(string $day, int $months): string
    {
        $date = new \DateTimeImmutable($day);
        $first = $date->modify('first day of this month')->modify('-' . $months . ' months');

        return $first->format('Y-m-') . sprintf('%02d', min((int) $date->format('j'), (int) $first->format('t')));
    }

    /**
     * The admin-post handler behind "View the email": prints the kept copy of
     * one sent newsletter as the page. Administrators only, with a nonce per
     * copy.
     */
    public function viewCopy(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- copyHtml() checks the nonce.
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $html = $this->copyHtml($id);

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        // The copy is the plugin's own email; it has no scripts and must run none.
        header("Content-Security-Policy: script-src 'none'");

        // The stored email, built from escaped parts when it was sent.
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above.
        exit;
    }

    /**
     * The kept copy of sent newsletter $id. Stops (wp_die) when the user may
     * not manage EventCrew, when the nonce is wrong, or when there is no such
     * copy.
     */
    public function copyHtml(int $id): string
    {
        Admin::assertCanSave(self::COPY_ACTION . '_' . $id);

        $copy = $this->history->copy($id);

        if (null === $copy) {
            wp_die(esc_html__('This email copy does not exist.', 'eventcrew'), '', ['response' => 404]);
        }

        return $copy['html'];
    }

    /** The admin-only link that opens the copy of sent newsletter $id. */
    public static function copyUrl(int $id): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => self::COPY_ACTION, 'id' => $id], admin_url('admin-post.php')),
            self::COPY_ACTION . '_' . $id
        );
    }

    /** "Finnish", "English" or "Both languages" for a version's language. */
    public static function versionLabel(string $lang): string
    {
        return match ($lang) {
            'fi' => __('Finnish', 'eventcrew'),
            'en' => __('English', 'eventcrew'),
            default => __('Both languages', 'eventcrew'),
        };
    }

    /**
     * One bucket per day, week (weeks start on Monday) or month from $from to
     * $to, with the joins and leaves in it. The first bucket starts on the
     * first day of the week or month $from falls in.
     *
     * @param array<string, array{subscribed: int, unsubscribed: int}> $changes Y-m-d => counts
     * @return list<array{start: string, subscribed: int, unsubscribed: int}>
     */
    public static function buckets(string $from, string $to, string $per, array $changes): array
    {
        $step = match ($per) {
            self::PER_WEEK => '+7 days',
            self::PER_MONTH => '+1 month',
            default => '+1 day',
        };
        $day = new \DateTimeImmutable(self::bucketOf($from, $per));
        $end = new \DateTimeImmutable($to);
        $buckets = [];

        while ($day <= $end) {
            $start = $day->format('Y-m-d');
            $buckets[$start] = ['start' => $start, 'subscribed' => 0, 'unsubscribed' => 0];
            $day = $day->modify($step);
        }

        foreach ($changes as $date => $counts) {
            $key = self::bucketOf((string) $date, $per);

            if (isset($buckets[$key])) {
                $buckets[$key]['subscribed'] += (int) ($counts['subscribed'] ?? 0);
                $buckets[$key]['unsubscribed'] += (int) ($counts['unsubscribed'] ?? 0);
            }
        }

        return array_values($buckets);
    }

    /**
     * The chart as inline SVG: for each bucket a bar of joins and a bar of
     * leaves side by side, and for each bar with sent newsletters one dashed
     * vertical line with their number (or first and last number) at the top. Hovering a bar or a line shows its numbers
     * (an SVG title).
     *
     * @param list<array{start: string, subscribed: int, unsubscribed: int}> $buckets
     * @param list<array{number: int, at: string, subject: string, sent: int}> $sends
     */
    public static function chart(array $buckets, array $sends, string $per): string
    {
        $count = max(1, count($buckets));
        $plotWidth = self::WIDTH - self::LEFT - self::RIGHT;
        $plotHeight = self::HEIGHT - self::TOP - self::BOTTOM;
        $slot = $plotWidth / $count;
        $bar = min(18.0, max(1.0, $slot * 0.4));
        $max = 1;

        foreach ($buckets as $bucket) {
            $max = max($max, $bucket['subscribed'], $bucket['unsubscribed']);
        }

        $bottom = self::TOP + $plotHeight;
        $y = static fn (int $n): float => $bottom - ($plotHeight * $n / $max);
        $out = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" role="img"'
                . ' aria-label="%s" style="max-width:%dpx;height:auto;background:#fff;border:1px solid #dcdcde">',
            self::WIDTH,
            self::HEIGHT,
            esc_attr__('Joins and leaves over time, with the sent newsletters marked', 'eventcrew'),
            self::WIDTH
        );

        // The scale: 0, half and the highest count, as lines across.
        foreach (array_unique([0, intdiv($max + 1, 2), $max]) as $tick) {
            $out .= sprintf(
                '<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" stroke="#dcdcde"/>'
                    . '<text x="%d" y="%.1f" font-size="11" text-anchor="end" fill="#50575e">%d</text>',
                self::LEFT,
                self::WIDTH - self::RIGHT,
                $y($tick),
                $y($tick),
                self::LEFT - 6,
                $y($tick) + 4,
                $tick
            );
        }

        $labelEvery = max(1, (int) ceil($count / 8));

        foreach ($buckets as $i => $bucket) {
            $center = self::LEFT + $slot * ($i + 0.5);
            $label = self::PER_MONTH === $per ? self::monthLabel($bucket['start']) : self::shortDay($bucket['start']);
            $bars = [
                [$bucket['subscribed'], $center - $bar, self::COLOR_SUBSCRIBED, self::PER_WEEK === $per
                    /* translators: 1: the first day of the week, like 3.11., 2: number of addresses */
                    ? __('Week of %1$s: %2$d subscribed', 'eventcrew')
                    /* translators: 1: a day, like 3.11., 2: number of addresses */
                    : __('%1$s: %2$d subscribed', 'eventcrew')],
                [$bucket['unsubscribed'], $center, self::COLOR_UNSUBSCRIBED, self::PER_WEEK === $per
                    /* translators: 1: the first day of the week, like 3.11., 2: number of addresses */
                    ? __('Week of %1$s: %2$d unsubscribed', 'eventcrew')
                    /* translators: 1: a day, like 3.11., 2: number of addresses */
                    : __('%1$s: %2$d unsubscribed', 'eventcrew')],
            ];

            foreach ($bars as [$n, $x, $color, $words]) {
                if ($n <= 0) {
                    continue;
                }

                $out .= sprintf(
                    '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"><title>%s</title></rect>',
                    $x,
                    $y($n),
                    $bar,
                    $bottom - $y($n),
                    $color,
                    esc_html(sprintf($words, $label, $n))
                );
            }

            // Every few bars and the last one, but not one so close to the last that the two overlap.
            $nearLast = $count - 1 - $i < intdiv($labelEvery + 1, 2);

            if ((0 === $i % $labelEvery && ! $nearLast) || $i === $count - 1) {
                $out .= sprintf(
                    '<text x="%.1f" y="%d" font-size="11" text-anchor="middle" fill="#50575e">%s</text>',
                    $center,
                    self::HEIGHT - 10,
                    esc_html($label)
                );
            }
        }

        $first = $buckets[0]['start'] ?? '';
        $index = [];

        foreach ($buckets as $i => $bucket) {
            $index[$bucket['start']] = $i;
        }

        // Each sent newsletter (both language versions together) in the bucket
        // of the day it went: bucket index => newsletter number => mark.
        $marks = [];

        foreach ($sends as $send) {
            $day = substr($send['at'], 0, 10);
            $key = self::bucketOf($day, $per);

            if ('' === $first || ! isset($index[$key])) {
                continue;
            }

            $mark = &$marks[$index[$key]][$send['number']];
            $mark ??= ['day' => $day, 'subjects' => [], 'sent' => 0];
            $mark['subjects'][] = $send['subject'];
            $mark['sent'] += $send['sent'];
            unset($mark);
        }

        ksort($marks);

        // One marker per bar (Owner, Q235): the newsletter's number, or for
        // several newsletters in one bar the first and last number, like
        // "1–2". The title lists each newsletter on its own line.
        foreach ($marks as $i => $group) {
            ksort($group);
            $x = self::LEFT + $slot * ($i + 0.5);
            $titles = [];

            foreach ($group as $number => $mark) {
                $titles[] = sprintf(
                    /* translators: 1: newsletter number, 2: date, like 3.11.2026, 3: subject(s), 4: number of emails */
                    _n(
                        'Newsletter %1$d, %2$s: %3$s. Sent to %4$d address.',
                        'Newsletter %1$d, %2$s: %3$s. Sent to %4$d addresses.',
                        $mark['sent'],
                        'eventcrew'
                    ),
                    $number,
                    self::longDay($mark['day']),
                    implode(' / ', array_unique($mark['subjects'])),
                    $mark['sent']
                );
            }

            $numbers = array_keys($group);

            if (1 === count($numbers)) {
                $badge = sprintf('<circle cx="%.1f" cy="%d" r="9" fill="%s"/>', $x, self::TOP - 10, self::COLOR_SEND);
                $label = (string) $numbers[0];
                $labelX = $x;
            } else {
                $label = min($numbers) . '–' . max($numbers);
                $width = 7 * mb_strlen($label) + 8;
                // Kept inside the chart at its left and right ends.
                $labelX = min(max($x, self::LEFT + $width / 2), self::WIDTH - $width / 2);
                $badge = sprintf(
                    '<rect x="%.1f" y="%d" width="%d" height="18" rx="9" fill="%s"/>',
                    $labelX - $width / 2,
                    self::TOP - 19,
                    $width,
                    self::COLOR_SEND
                );
            }

            $out .= sprintf(
                '<g><title>%1$s</title>'
                    . '<line x1="%2$.1f" x2="%2$.1f" y1="%3$d" y2="%4$.1f" stroke="%5$s" stroke-width="2"'
                    . ' stroke-dasharray="4 3"/>'
                    . '%6$s'
                    . '<text x="%7$.1f" y="%8$d" font-size="11" text-anchor="middle" fill="#fff">%9$s</text></g>',
                esc_html(implode("\n", $titles)),
                $x,
                self::TOP,
                $bottom,
                self::COLOR_SEND,
                $badge,
                $labelX,
                self::TOP - 6,
                esc_html($label)
            );
        }

        return $out . '</svg>';
    }

    /** The first day of the bucket $day (Y-m-d) falls in. */
    private static function bucketOf(string $day, string $per): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        if (false === $date) {
            return $day;
        }

        return match ($per) {
            self::PER_WEEK => $date->modify('-' . ((int) $date->format('N') - 1) . ' days')->format('Y-m-d'),
            self::PER_MONTH => $date->format('Y-m-01'),
            default => $day,
        };
    }

    /** "11/2026" from 2026-11-01. */
    private static function monthLabel(string $day): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return false === $date ? $day : $date->format('n/Y');
    }

    /** A valid Y-m-d, or null. */
    private static function validDay(string $day): ?string
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $day : null;
    }

    /** "3.11." from 2026-11-03. */
    private static function shortDay(string $day): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return false === $date ? $day : $date->format('j.n.');
    }

    /** "3.11.2026" from 2026-11-03. */
    public static function longDay(string $day): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($day, 0, 10));

        return false === $date ? $day : $date->format('j.n.Y');
    }
}
