<?php

/**
 * The date bar at the top of the Admin tab's Roster and Door screens:
 * "‹ Fri 14 Nov ›". The arrows open the date with tasks just before and just
 * after the one shown, and are left out when there is none; the menu lists
 * every date with tasks and opens the one picked. Both screens read the same
 * roster_date, so they always show the same night.
 *
 * @var array<string, mixed> $eventcrew_admin      The Admin tab view (CrewAdminController::viewFor).
 * @var string               $eventcrew_here       This page's address.
 * @var string               $eventcrew_bar_screen The screen the bar is on: 'roster' or 'door'.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_bar_anchor = '#eventcrew-admin-' . $eventcrew_bar_screen;
$eventcrew_bar_date = (string) $eventcrew_admin['roster_date'];
$eventcrew_bar_prev = (string) $eventcrew_admin['roster_prev'];
$eventcrew_bar_next = (string) $eventcrew_admin['roster_next'];

// A GET form drops the query of its action, so a permalink like ?page_id=5
// goes along as hidden fields.
$eventcrew_bar_query = [];
parse_str((string) wp_parse_url($eventcrew_here, PHP_URL_QUERY), $eventcrew_bar_query);
unset($eventcrew_bar_query['roster_date']);
?>
<form class="eventcrew-admin-datebar" method="get" action="<?php echo esc_url($eventcrew_here . $eventcrew_bar_anchor); ?>">
    <?php foreach ($eventcrew_bar_query as $eventcrew_query_key => $eventcrew_query_value) : ?>
        <?php if (is_scalar($eventcrew_query_value)) : ?>
            <input type="hidden" name="<?php echo esc_attr((string) $eventcrew_query_key); ?>" value="<?php echo esc_attr((string) $eventcrew_query_value); ?>">
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ('' === $eventcrew_bar_prev) : ?>
        <span class="eventcrew-admin-datebar-arrow" aria-hidden="true"></span>
    <?php else : ?>
        <a class="eventcrew-textlink eventcrew-admin-datebar-arrow" data-eventcrew-date-prev
            href="<?php echo esc_url(add_query_arg(['roster_date' => $eventcrew_bar_prev], $eventcrew_here) . $eventcrew_bar_anchor); ?>"
            aria-label="<?php esc_attr_e('Previous date', 'eventcrew'); ?>" title="<?php esc_attr_e('Previous date', 'eventcrew'); ?>">‹</a>
    <?php endif; ?>

    <select id="eventcrew-admin-<?php echo esc_attr($eventcrew_bar_screen); ?>-date" name="roster_date" aria-label="<?php esc_attr_e('Date', 'eventcrew'); ?>" onchange="this.form.submit()">
        <?php if ([] !== $eventcrew_admin['roster_upcoming']) : ?>
            <optgroup label="<?php esc_attr_e('Upcoming', 'eventcrew'); ?>">
                <?php foreach ($eventcrew_admin['roster_upcoming'] as $eventcrew_date_value => $eventcrew_date_label) : ?>
                    <option value="<?php echo esc_attr($eventcrew_date_value); ?>"<?php echo $eventcrew_date_value === $eventcrew_bar_date ? ' selected' : ''; ?>><?php echo esc_html($eventcrew_date_label); ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>
        <?php if ([] !== $eventcrew_admin['roster_past']) : ?>
            <optgroup label="<?php esc_attr_e('Past', 'eventcrew'); ?>">
                <?php foreach ($eventcrew_admin['roster_past'] as $eventcrew_date_value => $eventcrew_date_label) : ?>
                    <option value="<?php echo esc_attr($eventcrew_date_value); ?>"<?php echo $eventcrew_date_value === $eventcrew_bar_date ? ' selected' : ''; ?>><?php echo esc_html($eventcrew_date_label); ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>
    </select>
    <noscript><button type="submit" class="eventcrew-textlink"><?php esc_html_e('Show', 'eventcrew'); ?></button></noscript>

    <?php if ('' === $eventcrew_bar_next) : ?>
        <span class="eventcrew-admin-datebar-arrow" aria-hidden="true"></span>
    <?php else : ?>
        <a class="eventcrew-textlink eventcrew-admin-datebar-arrow" data-eventcrew-date-next
            href="<?php echo esc_url(add_query_arg(['roster_date' => $eventcrew_bar_next], $eventcrew_here) . $eventcrew_bar_anchor); ?>"
            aria-label="<?php esc_attr_e('Next date', 'eventcrew'); ?>" title="<?php esc_attr_e('Next date', 'eventcrew'); ?>">›</a>
    <?php endif; ?>
</form>
