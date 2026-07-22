<?php
/**
 * Diagnostics admin view: a health table plus the environment footer.
 *
 * @var array<int, \EventCrew\Support\Diagnostic> $checks
 * @var string $plugin_version
 * @var string $php_version
 * @var string $wp_version
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Support\Diagnostic;

/**
 * A coloured status pill for a diagnostic's state.
 */
$eventcrew_status_pill = static function (string $status): string {
    [$color, $text] = match ($status) {
        Diagnostic::OK => ['#1a7f37', __('OK', 'eventcrew')],
        Diagnostic::WARN => ['#bd8600', __('Check', 'eventcrew')],
        Diagnostic::ERROR => ['#b32d2e', __('Problem', 'eventcrew')],
        default => ['#646970', __('Info', 'eventcrew')],
    };

    return sprintf(
        '<span style="display:inline-block;min-width:64px;text-align:center;padding:2px 10px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;text-transform:uppercase;background:%s">%s</span>',
        esc_attr($color),
        esc_html($text)
    );
};
?>
<div class="wrap">
    <h1><?php esc_html_e('EventCrew Diagnostics', 'eventcrew'); ?></h1>

    <p class="description">
        <?php esc_html_e('A read-only health check. Anything flagged is fixed on the Settings page — nothing here changes your site.', 'eventcrew'); ?>
    </p>

    <table class="widefat striped" style="max-width:900px;margin-top:1em">
        <thead>
            <tr>
                <th style="width:90px"><?php esc_html_e('Status', 'eventcrew'); ?></th>
                <th style="width:160px"><?php esc_html_e('Check', 'eventcrew'); ?></th>
                <th><?php esc_html_e('Detail', 'eventcrew'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($checks as $check) : ?>
                <tr>
                    <td><?php echo $eventcrew_status_pill($check->status); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built with esc_* inside the closure. ?></td>
                    <td><strong><?php echo esc_html($check->label); ?></strong></td>
                    <td><?php echo esc_html($check->detail); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top:1.5em"><?php esc_html_e('Environment', 'eventcrew'); ?></h2>
    <table class="widefat striped" style="max-width:900px">
        <tbody>
            <tr>
                <td style="width:160px"><strong><?php esc_html_e('EventCrew version', 'eventcrew'); ?></strong></td>
                <td><?php echo esc_html($plugin_version); ?></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('WordPress version', 'eventcrew'); ?></strong></td>
                <td><?php echo esc_html($wp_version); ?></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('PHP version', 'eventcrew'); ?></strong></td>
                <td><?php echo esc_html($php_version); ?></td>
            </tr>
        </tbody>
    </table>
</div>
