<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\NewsletterPage;
use EventCrew\Admin\View;
use EventCrew\Database\Schema;
use EventCrew\Repositories\NewsletterHistoryRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Tests\TestCase;

/**
 * The wp-admin Newsletter page: the counts, the chart with the sent
 * newsletters on it, the empty state, and the admin-only copy of an email.
 */
final class NewsletterPageTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [Schema::NEWSLETTER_HISTORY_SINCE_OPTION => '2026-07-15'];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_nonce_url')->alias(
            static fn (string $url, string $action): string => $url . '&_wpnonce=' . md5($action)
        );
        Functions\when('wp_die')->alias(static function (string $message = ''): void {
            throw new \RuntimeException('wp_die: ' . $message);
        });
    }

    private function page(): NewsletterPage
    {
        return new NewsletterPage(new View(), new NewsletterSubscriberRepository(), new NewsletterHistoryRepository());
    }

    /** Queues the counts, the changes per day and the sent list, in the order data() reads them. */
    private function queue(array $changes, array $sends): void
    {
        // changesPerDay(), sentList(), then countByStatus().
        $this->wpdb->nextResults[] = $changes;
        $this->wpdb->nextResults[] = $sends;
        $this->wpdb->nextResults[] = [
            ['status' => 'confirmed', 'n' => '12'],
            ['status' => 'pending', 'n' => '3'],
            ['status' => 'unsubscribed', 'n' => '1'],
        ];
    }

    /** With nothing recorded yet the page has the counts and the start day, and no chart. */
    public function testNoHistoryYetShowsCountsAndNoChart(): void
    {
        $this->queue([], []);

        $data = $this->page()->data('');

        self::assertSame(['confirmed' => 12, 'pending' => 3, 'unsubscribed' => 1], $data['counts']);
        self::assertSame('2026-07-15', $data['since']);
        self::assertFalse($data['has_history']);
        self::assertSame('', $data['chart']);
        self::assertSame([], $data['sends']);
        self::assertSame(NewsletterPage::PER_DAY, $data['per']);
    }

    /** Before the migration has stored a day, the history starts today. */
    public function testAMissingStartDayIsToday(): void
    {
        $this->options = [];
        $this->queue([], []);

        self::assertSame('2026-07-20', $this->page()->data('')['since']);
    }

    /**
     * The chart has a bar per day with its numbers as a title, and one marker
     * per sent newsletter (both language versions together) with its number,
     * date, subjects and how many it went to.
     */
    public function testTheChartMarksEachSentNewsletter(): void
    {
        $this->queue(
            [
                ['day' => '2026-07-16', 'kind' => 'subscribed', 'n' => '4'],
                ['day' => '2026-07-18', 'kind' => 'unsubscribed', 'n' => '1'],
            ],
            [
                ['id' => '1', 'plan_id' => '7', 'lang' => 'fi', 'sent_at' => '2026-07-17 18:00:00',
                    'subject' => 'Tulevat tapahtumat', 'sent' => '8', 'failed' => '0'],
                ['id' => '2', 'plan_id' => '7', 'lang' => 'en', 'sent_at' => '2026-07-17 18:00:00',
                    'subject' => 'Coming events & more', 'sent' => '3', 'failed' => '1'],
            ]
        );

        $data = $this->page()->data('');
        $svg = $data['chart'];

        self::assertTrue($data['has_history']);
        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<title>16.7.: 4 subscribed</title>', $svg);
        self::assertStringContainsString('<title>18.7.: 1 unsubscribed</title>', $svg);
        self::assertSame(2, substr_count($svg, '<rect '), 'no bar for a zero');
        self::assertSame(1, substr_count($svg, 'stroke-dasharray'), 'one marker per newsletter');
        self::assertStringContainsString(
            '<title>Newsletter 1, 17.7.2026: Tulevat tapahtumat / Coming events &amp; more.'
                . ' Sent to 11 addresses.</title>',
            $svg
        );
        self::assertSame([1, 1], array_column($data['sends'], 'number'));
        self::assertStringContainsString('action=eventcrew_newsletter_copy&id=2', $data['sends'][1]['url']);
        self::assertStringContainsString('_wpnonce=' . md5('eventcrew_newsletter_copy_2'), $data['sends'][1]['url']);
        self::assertStringNotContainsString('@', $svg);
    }

    /** Weeks start on Monday, and each day's numbers land in its week. */
    public function testWeeksAddUpTheirDays(): void
    {
        $buckets = NewsletterPage::buckets(
            '2026-07-08',
            '2026-07-20',
            NewsletterPage::PER_WEEK,
            [
                '2026-07-08' => ['subscribed' => 2, 'unsubscribed' => 0],
                '2026-07-12' => ['subscribed' => 1, 'unsubscribed' => 1],
            ]
        );

        self::assertSame(
            [
                ['start' => '2026-07-06', 'subscribed' => 3, 'unsubscribed' => 1],
                ['start' => '2026-07-13', 'subscribed' => 0, 'unsubscribed' => 0],
                ['start' => '2026-07-20', 'subscribed' => 0, 'unsubscribed' => 0],
            ],
            $buckets
        );
    }

    /** A month is a bucket from its first day, and each day's numbers land in its month. */
    public function testMonthsAddUpTheirDays(): void
    {
        $buckets = NewsletterPage::buckets(
            '2026-05-21',
            '2026-07-20',
            NewsletterPage::PER_MONTH,
            [
                '2026-05-31' => ['subscribed' => 2, 'unsubscribed' => 0],
                '2026-06-01' => ['subscribed' => 1, 'unsubscribed' => 1],
                '2026-06-30' => ['subscribed' => 1, 'unsubscribed' => 0],
            ]
        );

        self::assertSame(
            [
                ['start' => '2026-05-01', 'subscribed' => 2, 'unsubscribed' => 0],
                ['start' => '2026-06-01', 'subscribed' => 2, 'unsubscribed' => 1],
                ['start' => '2026-07-01', 'subscribed' => 0, 'unsubscribed' => 0],
            ],
            $buckets
        );
        self::assertStringContainsString(
            '<title>6/2026: 2 subscribed</title>',
            NewsletterPage::chart($buckets, [], NewsletterPage::PER_MONTH)
        );
    }

    /** Three years of months: a label every five bars, and none right before the last one. */
    public function testMonthLabelsDoNotCrowdTheLastOne(): void
    {
        $buckets = NewsletterPage::buckets('2023-11-05', '2026-11-04', NewsletterPage::PER_MONTH, []);
        $svg = NewsletterPage::chart($buckets, [], NewsletterPage::PER_MONTH);

        self::assertCount(37, $buckets);
        self::assertStringContainsString('>11/2023</text>', $svg);
        self::assertStringContainsString('>5/2026</text>', $svg);
        self::assertStringContainsString('>11/2026</text>', $svg);
        self::assertStringNotContainsString('>10/2026</text>', $svg);
    }

    /** Each preset ends today; one month is the default, and a short month is not overrun. */
    public function testThePresetRanges(): void
    {
        $today = '2026-11-04';

        self::assertSame(['1m', '2026-10-05', $today], NewsletterPage::range('', '', '', $today));
        self::assertSame(['1m', '2026-10-05', $today], NewsletterPage::range('nonsense', '2020-01-01', '', $today));
        self::assertSame(['6m', '2026-05-05', $today], NewsletterPage::range('6m', '', '', $today));
        self::assertSame(['1y', '2025-11-05', $today], NewsletterPage::range('1y', '', '', $today));
        self::assertSame(['3y', '2023-11-05', $today], NewsletterPage::range('3y', '', '', $today));
        self::assertSame(['1m', '2026-03-01', '2026-03-31'], NewsletterPage::range('1m', '', '', '2026-03-31'));
    }

    /**
     * A custom range needs a valid from day. The to day is today when missing
     * or later than today, swapped dates are put right, and a range longer
     * than ten years is cut at its start.
     */
    public function testACustomRangeIsKeptSensible(): void
    {
        $today = '2026-11-04';

        self::assertSame(
            ['custom', '2026-01-10', '2026-02-20'],
            NewsletterPage::range('custom', '2026-01-10', '2026-02-20', $today)
        );
        self::assertSame(['custom', '2026-01-10', $today], NewsletterPage::range('custom', '2026-01-10', '', $today));
        self::assertSame(
            ['custom', '2026-01-10', $today],
            NewsletterPage::range('custom', '2026-01-10', '2027-05-01', $today)
        );
        self::assertSame(
            ['custom', '2026-01-10', '2026-02-20'],
            NewsletterPage::range('custom', '2026-02-20', '2026-01-10', $today)
        );
        self::assertSame(
            ['custom', '2016-11-05', $today],
            NewsletterPage::range('custom', '2001-01-01', $today, $today)
        );
        self::assertSame(['1m', '2026-10-05', $today], NewsletterPage::range('custom', '2026-02-31', '', $today));
        self::assertSame(['1m', '2026-10-05', $today], NewsletterPage::range('custom', '', '2026-01-01', $today));
    }

    /** The bar width follows the length of the range, so there are never too many bars. */
    public function testTheBarsFollowTheRangeLength(): void
    {
        self::assertSame(NewsletterPage::PER_DAY, NewsletterPage::perFor('2026-01-01', '2026-03-03'));
        self::assertSame(NewsletterPage::PER_WEEK, NewsletterPage::perFor('2026-01-01', '2026-03-04'));
        self::assertSame(NewsletterPage::PER_WEEK, NewsletterPage::perFor('2025-11-05', '2026-11-04'));
        self::assertSame(NewsletterPage::PER_MONTH, NewsletterPage::perFor('2023-11-05', '2026-11-04'));
        self::assertSame(NewsletterPage::PER_DAY, NewsletterPage::perFor('2026-11-04', '2026-11-04'));

        $this->queue([], []);
        $data = $this->page()->data('6m');
        self::assertSame(['6m', '2026-01-21', '2026-07-20', NewsletterPage::PER_WEEK], [
            $data['range'], $data['from'], $data['to'], $data['per'],
        ]);
    }

    /**
     * The joins and leaves are read for the range only, and a newsletter sent
     * before the range has no marker and is not in the list (Q234); the one
     * in the range keeps its number from the whole history.
     */
    public function testTheChartFollowsTheRange(): void
    {
        $this->queue(
            [['day' => '2026-07-02', 'kind' => 'subscribed', 'n' => '2']],
            [
                ['id' => '1', 'plan_id' => '6', 'lang' => '', 'sent_at' => '2026-06-30 18:00:00',
                    'subject' => 'June', 'sent' => '5', 'failed' => '0'],
                ['id' => '2', 'plan_id' => '7', 'lang' => '', 'sent_at' => '2026-07-03 18:00:00',
                    'subject' => 'July', 'sent' => '6', 'failed' => '0'],
            ]
        );

        $data = $this->page()->data('custom', '2026-07-01', '2026-07-10');

        self::assertStringContainsString(
            "created_at >= '2026-07-01 00:00:00' AND created_at <= '2026-07-10 23:59:59'",
            $this->wpdb->queries[0]
        );
        self::assertSame(1, substr_count($data['chart'], 'stroke-dasharray'));
        self::assertStringContainsString('Newsletter 2, 3.7.2026: July.', $data['chart']);
        self::assertStringNotContainsString('June', $data['chart']);
        self::assertSame([2], array_column($data['sends'], 'number'));
        self::assertTrue($data['any_sent']);
        self::assertSame(['joins' => 2, 'leaves' => 0, 'newsletters' => 1], $data['totals']);
    }

    /** The totals count the joins, leaves and newsletters in the range; two versions are one newsletter (Q233). */
    public function testTheTotalsCoverTheRange(): void
    {
        $this->queue(
            [
                ['day' => '2026-07-02', 'kind' => 'subscribed', 'n' => '2'],
                ['day' => '2026-07-05', 'kind' => 'subscribed', 'n' => '3'],
                ['day' => '2026-07-05', 'kind' => 'unsubscribed', 'n' => '1'],
            ],
            [
                ['id' => '1', 'plan_id' => '6', 'lang' => '', 'sent_at' => '2026-06-30 18:00:00',
                    'subject' => 'June', 'sent' => '5', 'failed' => '0'],
                ['id' => '2', 'plan_id' => '7', 'lang' => 'fi', 'sent_at' => '2026-07-03 18:00:00',
                    'subject' => 'Heinäkuu', 'sent' => '6', 'failed' => '0'],
                ['id' => '3', 'plan_id' => '7', 'lang' => 'en', 'sent_at' => '2026-07-03 18:00:00',
                    'subject' => 'July', 'sent' => '2', 'failed' => '0'],
                ['id' => '4', 'plan_id' => '8', 'lang' => '', 'sent_at' => '2026-07-08 18:00:00',
                    'subject' => 'Later in July', 'sent' => '8', 'failed' => '0'],
            ]
        );

        $data = $this->page()->data('custom', '2026-07-01', '2026-07-10');

        self::assertSame(['joins' => 5, 'leaves' => 1, 'newsletters' => 2], $data['totals']);
        self::assertSame([2, 2, 3], array_column($data['sends'], 'number'));
    }

    /**
     * Two newsletters in one bar get one marker labelled with their first and
     * last number, and its title lists both (Q235). A bar with one newsletter
     * keeps the round marker with its number.
     */
    public function testSeveralNewslettersInOneBarShareOneMarker(): void
    {
        $buckets = NewsletterPage::buckets('2026-05-21', '2026-07-20', NewsletterPage::PER_MONTH, []);
        $svg = NewsletterPage::chart(
            $buckets,
            [
                ['number' => 1, 'at' => '2026-06-03 18:00:00', 'subject' => 'Early June', 'sent' => 4],
                ['number' => 2, 'at' => '2026-06-24 18:00:00', 'subject' => 'Midsummer', 'sent' => 5],
                ['number' => 2, 'at' => '2026-06-24 18:00:00', 'subject' => 'Juhannus', 'sent' => 1],
                ['number' => 3, 'at' => '2026-07-08 18:00:00', 'subject' => 'July', 'sent' => 7],
            ],
            NewsletterPage::PER_MONTH
        );

        self::assertSame(2, substr_count($svg, 'stroke-dasharray'), 'one marker per bar');
        self::assertStringContainsString('>1–2</text>', $svg);
        self::assertStringContainsString(
            "<title>Newsletter 1, 3.6.2026: Early June. Sent to 4 addresses.\n"
                . 'Newsletter 2, 24.6.2026: Midsummer / Juhannus. Sent to 6 addresses.</title>',
            $svg
        );
        self::assertSame(1, substr_count($svg, '<circle '), 'the July bar keeps the round marker');
        self::assertStringContainsString('>3</text></g>', $svg);
        self::assertSame(1, substr_count($svg, 'rx="9"'), 'the June bar has the wider marker');
    }

    /** A range with nothing in it has no chart and no sent newsletters listed. */
    public function testAnEmptyRangeHasNoChart(): void
    {
        $this->queue(
            [],
            [['id' => '1', 'plan_id' => '6', 'lang' => '', 'sent_at' => '2026-01-30 18:00:00',
                'subject' => 'January', 'sent' => '5', 'failed' => '0']]
        );

        $data = $this->page()->data('1m');

        self::assertFalse($data['has_history']);
        self::assertSame('', $data['chart']);
        self::assertSame([], $data['sends']);
        self::assertTrue($data['any_sent']);
        self::assertSame(['joins' => 0, 'leaves' => 0, 'newsletters' => 0], $data['totals']);
        self::assertStringContainsString('No newsletter was sent in this time range.', $this->renderedAgain());
    }

    /** The same data as the last data() call, rendered: queues the January newsletter again. */
    private function renderedAgain(): string
    {
        $this->queue(
            [],
            [['id' => '1', 'plan_id' => '6', 'lang' => '', 'sent_at' => '2026-01-30 18:00:00',
                'subject' => 'January', 'sent' => '5', 'failed' => '0']]
        );

        return $this->rendered(['range' => '1m']);
    }

    /** The rendered page, with $get as the query string. */
    private function rendered(array $get = []): string
    {
        Functions\when('esc_html_e')->alias(static function (string $text): void {
            echo esc_html(\EventCrew\Tests\FinnishCatalog::translate($text));
        });
        Functions\when('selected')->alias(static function (mixed $a, mixed $b): void {
            echo (string) $a === (string) $b ? ' selected="selected"' : '';
        });
        Functions\when('wp_unslash')->returnArg(1);
        $_GET = $get;

        ob_start();

        try {
            $this->page()->render();
        } finally {
            $_GET = [];
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** With no history the page says from which day it is kept, and that the range has nothing in it. */
    public function testThePageWithoutHistory(): void
    {
        $this->queue([], []);

        $html = $this->rendered();

        self::assertStringContainsString('<td style="width:120px">12</td>', $html);
        self::assertStringContainsString(
            'The history starts on 15.7.2026, the day this version was installed. There is no older data.',
            $html
        );
        self::assertStringContainsString('Nothing was recorded in this time range.', $html);
        self::assertMatchesRegularExpression('/value="1m"\s+selected="selected"/', $html);
        self::assertStringContainsString('No newsletter has been sent since the history started.', $html);
        self::assertStringNotContainsString('<svg', $html);
    }

    /** After the backfill the joins go back further than the leaves and newsletters, and the page says so. */
    public function testThePageSaysTheJoinsGoBackFurther(): void
    {
        $this->queue([], []);
        $this->wpdb->nextVars[] = '2024-12-24 18:30:00';

        $html = $this->rendered();

        self::assertStringContainsString(
            'Joins are known from 24.12.2024, leaves and sent newsletters only from 15.7.2026.',
            $html
        );
        self::assertStringNotContainsString('The history starts on', $html);
    }

    public function testThePageWithHistory(): void
    {
        $this->queue(
            [['day' => '2026-07-16', 'kind' => 'subscribed', 'n' => '4']],
            [['id' => '1', 'plan_id' => '7', 'lang' => '', 'sent_at' => '2026-07-17 18:05:00',
                'subject' => 'Coming events', 'sent' => '8', 'failed' => '0']]
        );

        $html = $this->rendered(['range' => '6m']);

        self::assertStringContainsString('<svg', $html);
        self::assertMatchesRegularExpression('/value="6m"\s+selected="selected"/', $html);
        self::assertStringNotContainsString('<option value="custom"', $html, 'no custom choice until dates are used');
        self::assertStringContainsString('21.1.2026 – 20.7.2026, one bar per week.', $html);
        self::assertMatchesRegularExpression('~<td>\s*17\.7\.2026 18:05\s*</td>~', $html);
        self::assertStringContainsString('Both languages', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('In this time range: joins 4, leaves 0, sent newsletters 1.', $html);
    }

    /** Dates chosen in the second form are kept in both forms, and the list shows "Custom dates". */
    public function testThePageWithCustomDates(): void
    {
        $this->queue([['day' => '2026-07-02', 'kind' => 'subscribed', 'n' => '1']], []);

        $html = $this->rendered(['range' => 'custom', 'from' => '2026-07-01', 'to' => '2026-07-10']);

        self::assertMatchesRegularExpression('/value="custom"\s+selected="selected">Custom dates</', $html);
        self::assertMatchesRegularExpression('/name="from" required\s+value="2026-07-01" max="2026-07-20"/', $html);
        self::assertSame(2, substr_count($html, 'value="2026-07-10"'), 'the to day in both forms');
        self::assertStringContainsString('1.7.2026 – 10.7.2026, one bar per day.', $html);
    }

    /** With no join, leave or newsletter in the range the page says so, and the range can still be changed. */
    public function testThePageWithAnEmptyRange(): void
    {
        $this->queue([], []);

        $html = $this->rendered(['range' => '3y']);

        self::assertStringContainsString('Nothing was recorded in this time range.', $html);
        self::assertStringContainsString('name="range"', $html);
        self::assertStringContainsString('one bar per month.', $html);
        self::assertStringNotContainsString('<svg', $html);
    }

    /** Only someone who may manage EventCrew can open a copy, and only with its own nonce. */
    public function testACopyNeedsTheCapabilityAndTheNonce(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('check_admin_referer')->never();

        try {
            $this->page()->copyHtml(3);
            self::fail('A user without the capability got the copy.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('You do not have permission', $e->getMessage());
        }

        self::assertSame([], $this->wpdb->queries, 'nothing was read');
    }

    public function testACopyIsReadWithItsOwnNonce(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('check_admin_referer')->once()->with('eventcrew_newsletter_copy_3')->andReturn(1);
        $this->wpdb->nextRows[] = ['subject' => 'Coming events', 'html' => '<p>Hi!</p>'];

        self::assertSame('<p>Hi!</p>', $this->page()->copyHtml(3));
    }

    public function testAMissingCopyStops(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(1);

        $this->expectExceptionMessage('This email copy does not exist.');

        $this->page()->copyHtml(9);
    }
}
