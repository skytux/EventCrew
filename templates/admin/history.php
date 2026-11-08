<?php

/**
 * History admin view: filters for person, night and kind, then the actions,
 * newest first, paged.
 *
 * @var \EventCrew\Admin\HistoryListTable $table
 * @var array<int, array{id: int, name: string}> $people
 * @var int $person
 * @var string $date
 * @var string $group
 * @var array<int, string> $groups
 * @var int $keep_days
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\HistoryPage;
use EventCrew\Support\History;
?>
<div class="wrap">
    <h1><?php esc_html_e('History', 'eventcrew'); ?></h1>

    <p class="description">
        <?php
        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        printf(
            /* translators: %d: number of days */
            esc_html__('Who did what, and when: tasks, the door, credits, people and settings. Setting values are never stored. Rows are deleted after %d days.', 'eventcrew'),
            (int) $keep_days
        );
        // phpcs:enable Generic.Files.LineLength.TooLong
        ?>
    </p>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"
        style="margin:1em 0;display:flex;flex-wrap:wrap;gap:.4em;align-items:center">
        <input type="hidden" name="page" value="<?php echo esc_attr(HistoryPage::PAGE_SLUG); ?>">

        <label for="eventcrew-history-person"><?php esc_html_e('Person', 'eventcrew'); ?></label>
        <select id="eventcrew-history-person" name="person">
            <option value="0"><?php esc_html_e('Everyone', 'eventcrew'); ?></option>
            <?php foreach ($people as $eventcrew_person) : ?>
                <option value="<?php echo (int) $eventcrew_person['id']; ?>"
                    <?php selected($person, (int) $eventcrew_person['id']); ?>>
                    <?php echo esc_html($eventcrew_person['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="eventcrew-history-night"><?php esc_html_e('Night', 'eventcrew'); ?></label>
        <input type="date" id="eventcrew-history-night" name="night" value="<?php echo esc_attr($date); ?>">

        <label for="eventcrew-history-group"><?php esc_html_e('Kind', 'eventcrew'); ?></label>
        <select id="eventcrew-history-group" name="group">
            <option value=""><?php esc_html_e('All', 'eventcrew'); ?></option>
            <?php foreach ($groups as $eventcrew_group) : ?>
                <option value="<?php echo esc_attr($eventcrew_group); ?>" <?php selected($group, $eventcrew_group); ?>>
                    <?php echo esc_html(History::groupLabel($eventcrew_group)); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="button"><?php esc_html_e('Show', 'eventcrew'); ?></button>
    </form>

    <form method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr(HistoryPage::PAGE_SLUG); ?>">
        <input type="hidden" name="person" value="<?php echo (int) $person; ?>">
        <input type="hidden" name="night" value="<?php echo esc_attr($date); ?>">
        <input type="hidden" name="group" value="<?php echo esc_attr($group); ?>">
        <?php $table->display(); ?>
    </form>
</div>
