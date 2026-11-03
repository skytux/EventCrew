<?php

/**
 * Newsletter admin view: how many addresses are subscribed, pending and
 * unsubscribed; a chart of joins and leaves with the sent newsletters marked
 * on it; and the sent newsletters, each with a link to a copy of the email.
 * Counts only: no address or name is shown.
 *
 * @var array{confirmed: int, pending: int, unsubscribed: int} $counts
 * @var string $since
 * @var string $per
 * @var bool $has_history
 * @var string $chart
 * @var list<array{number: int, id: int, at: string, lang: string, subject: string,
 *     sent: int, failed: int, url: string}> $sends
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\NewsletterPage;

$eventcrew_swatch = static function (string $css): void {
    printf('<span style="display:inline-block;width:12px;height:12px;%s"></span>', esc_attr($css));
};
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
        printf(
            /* translators: %s: a date, like 3.11.2026 */
            esc_html__('The history starts on %s, the day this version was installed. There is no older data.', 'eventcrew'),
            esc_html(NewsletterPage::longDay($since))
        );
        ?>
    </p>

    <?php if (! $has_history) : ?>
        <p><?php esc_html_e('Nothing recorded yet: no one has joined or left and no newsletter has been sent since then.', 'eventcrew'); ?></p>
    <?php else : ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"
            style="margin:1em 0;display:flex;gap:.4em;align-items:center">
            <input type="hidden" name="page" value="<?php echo esc_attr(NewsletterPage::PAGE_SLUG); ?>">
            <label for="eventcrew-newsletter-per"><?php esc_html_e('One bar per', 'eventcrew'); ?></label>
            <select id="eventcrew-newsletter-per" name="per">
                <option value="<?php echo esc_attr(NewsletterPage::PER_DAY); ?>"
                    <?php selected($per, NewsletterPage::PER_DAY); ?>><?php esc_html_e('day', 'eventcrew'); ?></option>
                <option value="<?php echo esc_attr(NewsletterPage::PER_WEEK); ?>"
                    <?php selected($per, NewsletterPage::PER_WEEK); ?>><?php esc_html_e('week', 'eventcrew'); ?></option>
            </select>
            <button type="submit" class="button"><?php esc_html_e('Show', 'eventcrew'); ?></button>
        </form>

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
    <?php endif; ?>
    <?php // phpcs:enable Generic.Files.LineLength.TooLong ?>

    <h2 style="margin-top:1.5em"><?php esc_html_e('Sent newsletters', 'eventcrew'); ?></h2>

    <?php if ([] === $sends) : ?>
        <p><?php esc_html_e('No newsletter has been sent since the history started.', 'eventcrew'); ?></p>
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
