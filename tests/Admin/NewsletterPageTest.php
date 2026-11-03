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

    /** A long history is shown per week unless per day is asked for. */
    public function testALongHistoryDefaultsToWeeks(): void
    {
        $this->options[Schema::NEWSLETTER_HISTORY_SINCE_OPTION] = '2026-01-01';
        $this->queue([], []);
        self::assertSame(NewsletterPage::PER_WEEK, $this->page()->data('')['per']);

        $this->queue([], []);
        self::assertSame(NewsletterPage::PER_DAY, $this->page()->data(NewsletterPage::PER_DAY)['per']);
    }

    /** The rendered page, with a GET of $per. */
    private function rendered(string $per = ''): string
    {
        Functions\when('esc_html_e')->alias(static function (string $text): void {
            echo esc_html(\EventCrew\Tests\FinnishCatalog::translate($text));
        });
        Functions\when('selected')->alias(static function (mixed $a, mixed $b): void {
            echo (string) $a === (string) $b ? ' selected="selected"' : '';
        });
        Functions\when('wp_unslash')->returnArg(1);
        $_GET = '' === $per ? [] : ['per' => $per];

        ob_start();

        try {
            $this->page()->render();
        } finally {
            $_GET = [];
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** With no history the page says from which day it is kept, and that nothing is recorded yet. */
    public function testThePageWithoutHistory(): void
    {
        $this->queue([], []);

        $html = $this->rendered();

        self::assertStringContainsString('<td style="width:120px">12</td>', $html);
        self::assertStringContainsString(
            'The history starts on 15.7.2026, the day this version was installed. There is no older data.',
            $html
        );
        self::assertStringContainsString('Nothing recorded yet', $html);
        self::assertStringContainsString('No newsletter has been sent since the history started.', $html);
        self::assertStringNotContainsString('<svg', $html);
    }

    public function testThePageWithHistory(): void
    {
        $this->queue(
            [['day' => '2026-07-16', 'kind' => 'subscribed', 'n' => '4']],
            [['id' => '1', 'plan_id' => '7', 'lang' => '', 'sent_at' => '2026-07-17 18:05:00',
                'subject' => 'Coming events', 'sent' => '8', 'failed' => '0']]
        );

        $html = $this->rendered(NewsletterPage::PER_WEEK);

        self::assertStringContainsString('<svg', $html);
        self::assertMatchesRegularExpression('/value="week"\s+selected="selected"/', $html);
        self::assertMatchesRegularExpression('~<td>\s*17\.7\.2026 18:05\s*</td>~', $html);
        self::assertStringContainsString('Both languages', $html);
        self::assertStringContainsString('target="_blank"', $html);
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
