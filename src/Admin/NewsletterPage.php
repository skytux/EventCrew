<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Database\Schema;
use EventCrew\Repositories\NewsletterHistoryRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;

/**
 * The Newsletter page: how many addresses are subscribed, pending and
 * unsubscribed; a chart of joins and leaves per day or per week with each sent
 * newsletter marked on it; and the list of sent newsletters, each with a link
 * that opens the kept copy of the email in the browser. Read-only, and only
 * counts: no address or name is shown.
 *
 * The history (joins, leaves, sent newsletters) is recorded from schema 30 on;
 * the page says from which day.
 */
final class NewsletterPage
{
    public const PAGE_SLUG = 'eventcrew-newsletter';

    /** The admin-post action that shows one kept copy. */
    public const COPY_ACTION = 'eventcrew_newsletter_copy';

    public const PER_DAY = 'day';

    public const PER_WEEK = 'week';

    /** Up to this many days the chart has a bar per day by default; above it, per week. */
    public const DAY_LIMIT = 62;

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
        $per = isset($_GET['per']) ? sanitize_key(wp_unslash((string) $_GET['per'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $this->view->render('newsletter', $this->data($per));
    }

    /**
     * Everything the page shows. $per is PER_DAY, PER_WEEK, or anything else
     * for the default (per day up to DAY_LIMIT days of history, else per week).
     *
     * @return array{
     *     counts: array{confirmed: int, pending: int, unsubscribed: int},
     *     since: string,
     *     per: string,
     *     has_history: bool,
     *     chart: string,
     *     sends: list<array{number: int, id: int, at: string, lang: string, subject: string,
     *         sent: int, failed: int, url: string}>
     * }
     */
    public function data(string $per): array
    {
        $today = (string) current_time('Y-m-d');
        $since = self::validDay((string) get_option(Schema::NEWSLETTER_HISTORY_SINCE_OPTION, '')) ?? $today;
        $since = min($since, $today);
        $span = (int) (new \DateTimeImmutable($since))->diff(new \DateTimeImmutable($today))->days + 1;

        if (self::PER_DAY !== $per && self::PER_WEEK !== $per) {
            $per = $span > self::DAY_LIMIT ? self::PER_WEEK : self::PER_DAY;
        }

        $changes = $this->history->changesPerDay($since);
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

        $hasHistory = [] !== $changes || [] !== $sends;

        return [
            'counts' => $this->subscribers->countByStatus(),
            'since' => $since,
            'per' => $per,
            'has_history' => $hasHistory,
            'chart' => $hasHistory ? self::chart(self::buckets($since, $today, $per, $changes), $sends, $per) : '',
            'sends' => $sends,
        ];
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
     * One bucket per day or per week (weeks start on Monday) from $since to
     * $today, with the joins and leaves in it.
     *
     * @param array<string, array{subscribed: int, unsubscribed: int}> $changes Y-m-d => counts
     * @return list<array{start: string, subscribed: int, unsubscribed: int}>
     */
    public static function buckets(string $since, string $today, string $per, array $changes): array
    {
        $step = self::PER_WEEK === $per ? '+7 days' : '+1 day';
        $day = new \DateTimeImmutable($since);

        if (self::PER_WEEK === $per) {
            $day = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
        }

        $end = new \DateTimeImmutable($today);
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
     * leaves side by side, and for each sent newsletter a dashed vertical line
     * with its number at the top. Hovering a bar or a line shows its numbers
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
            $label = self::shortDay($bucket['start']);
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

            if (0 === $i % $labelEvery || $i === $count - 1) {
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

        // One marker per sent newsletter, in the bucket of the day it went.
        $marks = [];

        foreach ($sends as $send) {
            $day = substr($send['at'], 0, 10);
            $key = self::bucketOf($day, $per);

            if ('' === $first || ! isset($index[$key])) {
                continue;
            }

            $mark = &$marks[$send['number']];
            $mark ??= ['i' => $index[$key], 'day' => $day, 'subjects' => [], 'sent' => 0];
            $mark['subjects'][] = $send['subject'];
            $mark['sent'] += $send['sent'];
            unset($mark);
        }

        foreach ($marks as $number => $mark) {
            $x = self::LEFT + $slot * ($mark['i'] + 0.5);
            $title = sprintf(
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
            $out .= sprintf(
                '<g><title>%1$s</title>'
                    . '<line x1="%2$.1f" x2="%2$.1f" y1="%3$d" y2="%4$.1f" stroke="%5$s" stroke-width="2"'
                    . ' stroke-dasharray="4 3"/>'
                    . '<circle cx="%2$.1f" cy="%6$d" r="9" fill="%5$s"/>'
                    . '<text x="%2$.1f" y="%7$d" font-size="11" text-anchor="middle" fill="#fff">%8$d</text></g>',
                esc_html($title),
                $x,
                self::TOP,
                $bottom,
                self::COLOR_SEND,
                self::TOP - 10,
                self::TOP - 6,
                $number
            );
        }

        return $out . '</svg>';
    }

    /** The first day of the bucket $day (Y-m-d) falls in. */
    private static function bucketOf(string $day, string $per): string
    {
        if (self::PER_WEEK !== $per) {
            return $day;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return false === $date ? $day : $date->modify('-' . ((int) $date->format('N') - 1) . ' days')->format('Y-m-d');
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
