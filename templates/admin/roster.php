<?php
/**
 * Roster / attendance admin view.
 *
 * @var array<int, string> $upcoming_dates Dates with tasks, today onward, nearest first.
 * @var array<int, string> $past_dates Dates with tasks before today, most recent first.
 * @var string $selected_date The date currently shown (Y-m-d), or '' when none.
 * @var array<int, array{task: \EventCrew\Models\Task, people: array<int, array{assignment_id: int, name: string, status: string, status_label: string, occupying: bool}>}> $roster
 * @var array<string, string> $statuses Status slug => label, for the pickers.
 * @var string $nonce_action Nonce action for the marking forms.
 * @var string $page_slug Admin page slug, for form targets.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A date as "Sat 1 Aug 2026", localized, for the picker.
 */
$eventcrew_date_label = static function (string $date): string {
    $timestamp = strtotime($date . ' 12:00:00');

    return false === $timestamp ? $date : (string) wp_date('D j M Y', $timestamp);
};
?>
<div class="wrap">
    <h1><?php esc_html_e('Roster', 'eventcrew'); ?></h1>

    <?php if ([] === $upcoming_dates && [] === $past_dates) : ?>
        <p><?php esc_html_e('No tasks have been created yet, so there is nothing to take a roster for.', 'eventcrew'); ?></p>
    <?php else : ?>
        <form method="get" style="margin:1em 0">
            <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
            <label for="eventcrew-roster-date"><?php esc_html_e('Date', 'eventcrew'); ?></label>
            <select name="roster_date" id="eventcrew-roster-date" onchange="this.form.submit()">
                <?php if ([] !== $upcoming_dates) : ?>
                    <optgroup label="<?php esc_attr_e('Upcoming', 'eventcrew'); ?>">
                        <?php foreach ($upcoming_dates as $eventcrew_date) : ?>
                            <option value="<?php echo esc_attr($eventcrew_date); ?>" <?php selected($eventcrew_date, $selected_date); ?>>
                                <?php echo esc_html($eventcrew_date_label($eventcrew_date)); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php if ([] !== $past_dates) : ?>
                    <optgroup label="<?php esc_attr_e('Past', 'eventcrew'); ?>">
                        <?php foreach ($past_dates as $eventcrew_date) : ?>
                            <option value="<?php echo esc_attr($eventcrew_date); ?>" <?php selected($eventcrew_date, $selected_date); ?>>
                                <?php echo esc_html($eventcrew_date_label($eventcrew_date)); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
            <noscript><button type="submit" class="button"><?php esc_html_e('Show', 'eventcrew'); ?></button></noscript>
        </form>

        <?php if ([] === $roster) : ?>
            <p><?php esc_html_e('No tasks on that date.', 'eventcrew'); ?></p>
        <?php endif; ?>

        <?php foreach ($roster as $eventcrew_row) : ?>
            <?php
            $eventcrew_task = $eventcrew_row['task'];
            $eventcrew_time = $eventcrew_task->timeRange();
            $eventcrew_taken = 0;
            foreach ($eventcrew_row['people'] as $eventcrew_person) {
                if ($eventcrew_person['occupying']) {
                    ++$eventcrew_taken;
                }
            }
            ?>
            <h2 style="margin-bottom:.2em">
                <?php echo esc_html($eventcrew_task->roleDisplay()); ?>
                <span style="font-weight:normal;color:#666">
                    — <?php echo esc_html($eventcrew_task->eventName()); ?><?php
                    echo '' === $eventcrew_time ? '' : ' · ' . esc_html($eventcrew_time); ?>
                    (<?php echo esc_html(sprintf('%d/%d', $eventcrew_taken, $eventcrew_task->capacity)); ?>)
                </span>
            </h2>

            <?php if ([] === $eventcrew_row['people']) : ?>
                <p class="description"><?php esc_html_e('Nobody signed up.', 'eventcrew'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:640px;margin-bottom:.5em">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Person', 'eventcrew'); ?></th>
                            <th style="width:16em"><?php esc_html_e('Status', 'eventcrew'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventcrew_row['people'] as $eventcrew_person) : ?>
                            <tr>
                                <td><?php echo esc_html($eventcrew_person['name']); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:.4em">
                                        <input type="hidden" name="action" value="eventcrew_mark_attendance">
                                        <?php wp_nonce_field($nonce_action); ?>
                                        <input type="hidden" name="assignment_id" value="<?php echo esc_attr((string) $eventcrew_person['assignment_id']); ?>">
                                        <input type="hidden" name="roster_date" value="<?php echo esc_attr($selected_date); ?>">
                                        <select name="status">
                                            <?php foreach ($statuses as $eventcrew_slug => $eventcrew_label) : ?>
                                                <option value="<?php echo esc_attr($eventcrew_slug); ?>" <?php selected($eventcrew_slug, $eventcrew_person['status']); ?>>
                                                    <?php echo esc_html($eventcrew_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="button"><?php esc_html_e('Update', 'eventcrew'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:2em">
                    <input type="hidden" name="action" value="eventcrew_mark_all">
                    <?php wp_nonce_field($nonce_action); ?>
                    <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task->id); ?>">
                    <input type="hidden" name="roster_date" value="<?php echo esc_attr($selected_date); ?>">
                    <button type="submit" name="status" value="arrived" class="button">
                        <?php esc_html_e('Mark all arrived', 'eventcrew'); ?>
                    </button>
                    <button type="submit" name="status" value="completed" class="button">
                        <?php esc_html_e('Mark all completed', 'eventcrew'); ?>
                    </button>
                </form>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
