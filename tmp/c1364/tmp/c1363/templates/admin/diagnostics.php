<?php

/**
 * Diagnostics admin view: a health table, a recent-activity panel, and the
 * environment footer.
 *
 * @var array<int, \EventCrew\Support\Diagnostic> $checks
 * @var array<int, array{level: string, message: string, timestamp: int}> $recent_logs
 * @var int $last_update_id
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

/**
 * The colour for a log level, matching the status pill palette.
 */
$eventcrew_log_color = static function (string $level): string {
    return match ($level) {
        'ERROR' => '#b32d2e',
        'WARNING' => '#bd8600',
        default => '#646970',
    };
};
?>
<div class="wrap">
    <h1><?php esc_html_e('EventCrew Diagnostics', 'eventcrew'); ?></h1>

    <p class="description">
        <?php esc_html_e('A read-only health check. Anything flagged is fixed on the Settings page — nothing here changes your site.', 'eventcrew'); ?>
    </p>

    <?php
    // An at-a-glance summary: the worst status across every check, so a problem
    // is obvious before reading the table.
    $eventcrew_worst = Diagnostic::OK;
    foreach ($checks as $eventcrew_summary_check) {
        if (Diagnostic::ERROR === $eventcrew_summary_check->status) {
            $eventcrew_worst = Diagnostic::ERROR;
            break;
        }
        if (Diagnostic::WARN === $eventcrew_summary_check->status) {
            $eventcrew_worst = Diagnostic::WARN;
        }
    }
    [$eventcrew_summary_bg, $eventcrew_summary_text] = match ($eventcrew_worst) {
        Diagnostic::ERROR => ['#b32d2e', __('Problems found — see the flagged rows below.', 'eventcrew')],
        Diagnostic::WARN => ['#bd8600', __('Needs attention — some checks want a look.', 'eventcrew')],
        default => ['#1a7f37', __('All systems go.', 'eventcrew')],
    };
    ?>
    <p style="max-width:900px;margin:1em 0;padding:.7em 1.1em;border-radius:8px;color:#fff;font-weight:600;background:<?php echo esc_attr($eventcrew_summary_bg); ?>">
        <?php echo esc_html($eventcrew_summary_text); ?>
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

    <h2 style="margin-top:1.5em"><?php esc_html_e('Recent activity', 'eventcrew'); ?></h2>

    <p class="description">
        <?php
        if ($last_update_id > 0) {
            printf(
                /* translators: %s: the highest Telegram update id processed */
                esc_html__('Last Telegram update processed: #%s.', 'eventcrew'),
                esc_html((string) $last_update_id)
            );
        } else {
            esc_html_e('No Telegram update has reached the site yet.', 'eventcrew');
        }
        ?>
    </p>

    <?php if ([] === $recent_logs) : ?>
        <p><?php esc_html_e('Nothing logged recently. Errors from the bot, mail and boot will appear here.', 'eventcrew'); ?></p>
    <?php else : ?>
        <table class="widefat striped" style="max-width:900px">
            <thead>
                <tr>
                    <th style="width:90px"><?php esc_html_e('Level', 'eventcrew'); ?></th>
                    <th style="width:160px"><?php esc_html_e('When', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Message', 'eventcrew'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_logs as $entry) : ?>
                    <tr>
                        <td>
                            <span style="display:inline-block;min-width:64px;text-align:center;padding:2px 10px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;text-transform:uppercase;background:<?php echo esc_attr($eventcrew_log_color($entry['level'])); ?>">
                                <?php echo esc_html($entry['level']); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            echo $entry['timestamp'] > 0
                                ? esc_html(
                                    sprintf(
                                        /* translators: %s: human-readable time difference, e.g. "5 mins" */
                                        __('%s ago', 'eventcrew'),
                                        human_time_diff($entry['timestamp'])
                                    )
                                )
                                : '&mdash;';
                            ?>
                        </td>
                        <td><?php echo esc_html($entry['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

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
