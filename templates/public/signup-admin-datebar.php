<?php

/**
 * The date bar at the top of the Admin tab's Roster and Door screens, the
 * same selector as in Telegram: "‹ 📅 Fri 14 Nov ✓ ›". The arrows open the
 * date with tasks just before and just after the one shown, and are left out
 * when there is none; past dates are reached only by stepping back. The
 * middle link opens the shown date again. Both screens read the same
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

?>
<div class="eventcrew-admin-datebar">
    <?php if ('' === $eventcrew_bar_prev) : ?>
        <span class="eventcrew-admin-datebar-arrow" aria-hidden="true"></span>
    <?php else : ?>
        <a class="eventcrew-textlink" data-eventcrew-date-prev
            href="<?php echo esc_url(add_query_arg(['roster_date' => $eventcrew_bar_prev], $eventcrew_here) . $eventcrew_bar_anchor); ?>"
            aria-label="<?php esc_attr_e('Previous date', 'eventcrew'); ?>">◀ <?php echo esc_html(\EventCrew\Support\Dates::dayLabel($eventcrew_bar_prev)); ?></a>
    <?php endif; ?>

    <a class="eventcrew-textlink" aria-current="date"
        href="<?php echo esc_url(add_query_arg(['roster_date' => $eventcrew_bar_date], $eventcrew_here) . $eventcrew_bar_anchor); ?>"><?php echo esc_html((string) $eventcrew_admin['roster_picker_label']); ?></a>

    <?php if ('' === $eventcrew_bar_next) : ?>
        <span class="eventcrew-admin-datebar-arrow" aria-hidden="true"></span>
    <?php else : ?>
        <a class="eventcrew-textlink" data-eventcrew-date-next
            href="<?php echo esc_url(add_query_arg(['roster_date' => $eventcrew_bar_next], $eventcrew_here) . $eventcrew_bar_anchor); ?>"
            aria-label="<?php esc_attr_e('Next date', 'eventcrew'); ?>"><?php echo esc_html(\EventCrew\Support\Dates::dayLabel($eventcrew_bar_next)); ?> ▶</a>
    <?php endif; ?>
</div>
