<?php

/**
 * Newsletter admin view: how many addresses are subscribed, pending and
 * unsubscribed; a chart of joins and leaves with the sent newsletters marked
 * on it; and the sent newsletters, each with a link to a copy of the email.
 * Counts only: no address or name is shown.
 *
 * @var array{confirmed: int, pending: int, unsubscribed: int} $counts
 * @var string $since
 * @var string|null $joins_since
 * @var string $today
 * @var string $range
 * @var string $from
 * @var string $to
 * @var string $per
 * @var bool $has_history
 * @var string $chart
 * @var array{joins: int, leaves: int, newsletters: int} $totals
 * @var list<array{number: int, id: int, at: string, lang: string, subject: string,
 *     sent: int, failed: int, url: string}> $sends The ones sent in the range.
 * @var bool $any_sent
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\NewsletterPage;

$eventcrew_swatch = static function (string $css): void {
    printf('<span style="display:inline-block;width:12px;height:12px;%s"></span>', esc_attr($css));
};

// The keys are NewsletterPage::RANGES. Dates are chosen in the second form;
// while they are shown, the first one lists them as "Custom dates".
$eventcrew_ranges = [
    '1m' => __('1 month', 'eventcrew'),
    '6m' => __('6 months', 'eventcrew'),
    '1y' => __('1 year', 'eventcrew'),
    '3y' => __('3 years', 'eventcrew'),
];

if (NewsletterPage::RANGE_CUSTOM === $range) {
    $eventcrew_ranges[NewsletterPage::RANGE_CUSTOM] = __('Custom dates', 'eventcrew');
}
?>
<div class="wrap">
    <h1><?php esc_html_e('Newsletter', 'eventcrew'); ?></h1>

    <table class="widefat striped" style="max-width:500px;margin-top:1em">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Subscribed', 'eventcrew'); ?></th>
                <td style="width:120px"><?php echo esc_html((string) $counts['confirmed']); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Waiting for confirmation', 'eventcrew'); ?></th>
                <td><?php echo esc_html((string) $counts['pending']); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Unsubscribed', 'eventcrew'); ?></th>
                <td><?php echo esc_html((string) $counts['unsubscribed']); ?></td>
            </tr>
        </tbody>
    </table>

    <h2 style="margin-top:1.5em"><?php esc_html_e('Joins, leaves and sent newsletters', 'eventcrew'); ?></h2>

    <?php // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction. ?>
    <p class="description">
        <?php
        if (null !== $joins_since && $joins_since < $since) {
            printf(
                /* translators: 1: date of the oldest known join, like 3.11.2024, 2: the day the history began */
                esc_html__('Joins are known from %1$s, leaves and sent newsletters only from %2$s.', 'eventcrew'),
                esc_html(NewsletterPage::longDay($joins_since)),
                esc_html(NewsletterPage::longDay($since))
            );
        } else {
            printf(
                /* translators: %s: a date, like 3.11.2026 */
                esc_html__('The history starts on %s, the day this version was installed. There is no older data.', 'eventcrew'),
                esc_html(NewsletterPage::longDay($since))
            );
        }
        ?>
    </p>

    <div style="margin:1em 0;display:flex;gap:1.5em;flex-wrap:wrap;align-items:center">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"
            style="display:flex;gap:.4em;align-items:center">
            <input type="hidden" name="page" value="<?php echo esc_attr(NewsletterPage::PAGE_SLUG); ?>">
            <?php // Read only when "Custom dates" stays chosen. ?>
            <input type="hidden" name="from" value="<?php echo esc_attr($from); ?>">
            <input type="hidden" name="to" value="<?php echo esc_attr($to); ?>">
            <label for="eventcrew-newsletter-range"><?php esc_html_e('Time range', 'eventcrew'); ?></label>
            <select id="eventcrew-newsletter-range" name="range">
                <?php foreach ($eventcrew_ranges as $eventcrew_key => $eventcrew_label) : ?>
                    <option value="<?php echo esc_attr($eventcrew_key); ?>"
                        <?php selected($range, $eventcrew_key); ?>><?php echo esc_html($eventcrew_label); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php esc_html_e('Show', 'eventcrew'); ?></button>
        </form>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"
            style="display:flex;gap:.4em;align-items:center">
            <input type="hidden" name="page" value="<?php echo esc_attr(NewsletterPage::PAGE_SLUG); ?>">
            <input type="hidden" name="range" value="<?php echo esc_attr(NewsletterPage::RANGE_CUSTOM); ?>">
            <label for="eventcrew-newsletter-from"><?php esc_html_e('From', 'eventcrew'); ?></label>
            <input type="date" id="eventcrew-newsletter-from" name="from" required
                value="<?php echo esc_attr($from); ?>" max="<?php echo esc_attr($today); ?>">
            <label for="eventcrew-newsletter-to"><?php esc_html_e('To', 'eventcrew'); ?></label>
            <input type="date" id="eventcrew-newsletter-to" name="to"
                value="<?php echo esc_attr($to); ?>" max="<?php echo esc_attr($today); ?>">
            <button type="submit" class="button"><?php esc_html_e('Show', 'eventcrew'); ?></button>
        </form>
    </div>

    <p>
        <?php
        $eventcrew_span = [
            /* translators: 1: first day of the range, like 5.10.2026, 2: its last day */
            NewsletterPage::PER_DAY => __('%1$s – %2$s, one bar per day.', 'eventcrew'),
            /* translators: 1: first day of the range, like 5.10.2026, 2: its last day */
            NewsletterPage::PER_WEEK => __('%1$s – %2$s, one bar per week.', 'eventcrew'),
            /* translators: 1: first day of the range, like 5.10.2026, 2: its last day */
            NewsletterPage::PER_MONTH => __('%1$s – %2$s, one bar per month.', 'eventcrew'),
        ];
        echo esc_html(
            sprintf(
                $eventcrew_span[$per] ?? $eventcrew_span[NewsletterPage::PER_DAY],
                NewsletterPage::longDay($from),
                NewsletterPage::longDay($to)
            )
        );
        ?>
    </p>

    <?php if (! $has_history) : ?>
        <p><?php esc_html_e('Nothing was recorded in this time range.', 'eventcrew'); ?></p>
    <?php else : ?>
        <?php
        // Built in NewsletterPage::chart() from numbers and escaped text only.
        echo $chart; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above.
        ?>

        <p style="display:flex;gap:1.2em;flex-wrap:wrap;align-items:center">
            <span>
                <?php $eventcrew_swatch('background:' . NewsletterPage::COLOR_SUBSCRIBED); ?>
                <?php esc_html_e('Subscribed', 'eventcrew'); ?>
            </span>
            <span>
                <?php $eventcrew_swatch('background:' . NewsletterPage::COLOR_UNSUBSCRIBED); ?>
                <?php esc_html_e('Unsubscribed', 'eventcrew'); ?>
            </span>
            <span>
                <?php $eventcrew_swatch('height:0;border-top:2px dashed ' . NewsletterPage::COLOR_SEND); ?>
                <?php esc_html_e('Newsletter sent (the number is its row below; point at it for the subject)', 'eventcrew'); ?>
            </span>
        </p>

        <p>
            <?php
            printf(
                /* translators: 1: number of joins, 2: number of leaves, 3: number of newsletters sent */
                esc_html__('In this time range: joins %1$d, leaves %2$d, sent newsletters %3$d.', 'eventcrew'),
                (int) $totals['joins'],
                (int) $totals['leaves'],
                (int) $totals['newsletters']
            );
            ?>
        </p>
    <?php endif; ?>
    <?php // phpcs:enable Generic.Files.LineLength.TooLong ?>

    <h2 style="margin-top:1.5em"><?php esc_html_e('Sent newsletters', 'eventcrew'); ?></h2>

    <?php if (! $any_sent) : ?>
        <p><?php esc_html_e('No newsletter has been sent since the history started.', 'eventcrew'); ?></p>
    <?php elseif ([] === $sends) : ?>
        <p><?php esc_html_e('No newsletter was sent in this time range.', 'eventcrew'); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th style="width:140px"><?php esc_html_e('Sent', 'eventcrew'); ?></th>
                    <th style="width:120px"><?php esc_html_e('Version', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Subject', 'eventcrew'); ?></th>
                    <th style="width:100px"><?php esc_html_e('Sent to', 'eventcrew'); ?></th>
                    <th style="width:100px"><?php esc_html_e('Failed', 'eventcrew'); ?></th>
                    <th style="width:120px"><?php esc_html_e('Email', 'eventcrew'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sends as $eventcrew_send) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $eventcrew_send['number']); ?></td>
                        <td>
                            <?php
                            $eventcrew_at = $eventcrew_send['at'];
                            echo esc_html(NewsletterPage::longDay($eventcrew_at) . ' ' . substr($eventcrew_at, 11, 5));
                            ?>
                        </td>
                        <td><?php echo esc_html(NewsletterPage::versionLabel($eventcrew_send['lang'])); ?></td>
                        <td><?php echo esc_html($eventcrew_send['subject']); ?></td>
                        <td><?php echo esc_html((string) $eventcrew_send['sent']); ?></td>
                        <td><?php echo esc_html((string) $eventcrew_send['failed']); ?></td>
                        <td>
                            <a href="<?php echo esc_url($eventcrew_send['url']); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e('View the email', 'eventcrew'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
