<?php

/**
 * Roster / attendance admin view.
 *
 * @var array<int, string> $upcoming_dates Dates with tasks, today onward, nearest first.
 * @var array<int, string> $past_dates Dates with tasks before today, most recent first.
 * @var string $selected_date The date currently shown (Y-m-d), or '' when none.
 * @var array<int, array{task: \EventCrew\Models\Task, people: array<int, array{assignment_id: int, name: string, status: string, status_label: string, occupying: bool, standing: ?\EventCrew\Support\Standing}>}> $roster
 * @var array{entrants: array<int, array{name: string, detail: string, standing: \EventCrew\Support\Standing, redemption_id: ?int}>, candidates: array<int, array{person_id: int, name: string, credit_balance: int}>} $door
 * @var bool $free_entry_closed Whether free entry is closed for the shown date.
 * @var bool $leader_enabled Whether the crew-leader slot is on for the shown date.
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

/**
 * A coloured standing badge for the door list.
 */
$eventcrew_standing_badge = static function (\EventCrew\Support\Standing $standing): string {
    $color = match ($standing->level) {
        \EventCrew\Support\Standing::GOOD => '#1a7f37',
        \EventCrew\Support\Standing::AT_RISK => '#b32d2e',
        default => '#646970',
    };

    return sprintf(
        '<span style="color:%s;font-weight:600">%s</span>',
        esc_attr($color),
        esc_html($standing->ratedSummary())
    );
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

        <?php if ('' !== $selected_date) : ?>
            <h2><?php esc_html_e('Door list', 'eventcrew'); ?></h2>
            <p class="description">
                <?php esc_html_e('Everyone who gets in free that night — working the event, or spending a credit.', 'eventcrew'); ?>
            </p>
            <p style="margin:.4em 0 1em">
                <?php if ($free_entry_closed) : ?>
                    <strong style="color:#b32d2e"><?php esc_html_e('Free entry is closed for this date — no credits can be spent on it.', 'eventcrew'); ?></strong><br>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <input type="hidden" name="action" value="eventcrew_toggle_ticket_closed">
                    <?php wp_nonce_field($nonce_action); ?>
                    <input type="hidden" name="roster_date" value="<?php echo esc_attr($selected_date); ?>">
                    <button type="submit" class="button">
                        <?php echo $free_entry_closed
                            ? esc_html__('Reopen free entry', 'eventcrew')
                            : esc_html__('Close free entry (sold out)', 'eventcrew'); ?>
                    </button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <input type="hidden" name="action" value="eventcrew_toggle_leader">
                    <?php wp_nonce_field($nonce_action); ?>
                    <input type="hidden" name="roster_date" value="<?php echo esc_attr($selected_date); ?>">
                    <button type="submit" class="button">
                        <?php echo $leader_enabled
                            ? esc_html__('Turn off crew leader', 'eventcrew')
                            : esc_html__('Turn on crew leader', 'eventcrew'); ?>
                    </button>
                </form>
            </p>
            <table class="widefat striped" style="max-width:640px;margin-bottom:.5em">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Person', 'eventcrew'); ?></th>
                        <th><?php esc_html_e('Free entry', 'eventcrew'); ?></th>
                        <th style="width:9em"><?php esc_html_e('Standing', 'eventcrew'); ?></th>
                        <th style="width:6em"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ([] === $door['entrants']) : ?>
                        <tr><td colspan="4"><?php esc_html_e('Nobody on the door list yet.', 'eventcrew'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($door['entrants'] as $eventcrew_entrant) : ?>
                        <tr>
                            <td><?php echo esc_html($eventcrew_entrant['name']); ?></td>
                            <td><?php echo esc_html($eventcrew_entrant['detail']); ?></td>
                            <td><?php echo $eventcrew_standing_badge($eventcrew_entrant['standing']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge builds its own escaped markup. ?></td>
                            <td>
                                <?php if (null !== $eventcrew_entrant['redemption_id']) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="eventcrew_remove_redemption">
                                        <?php wp_nonce_field($nonce_action); ?>
                                        <input type="hidden" name="redemption_id" value="<?php echo esc_attr((string) $eventcrew_entrant['redemption_id']); ?>">
                                        <input type="hidden" name="roster_date" value="<?php echo esc_attr($selected_date); ?>">
                                        <button type="submit" class="button-link delete"><?php esc_html_e('Remove', 'eventcrew'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ([] !== $door['candidates'] && ! $free_entry_closed) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:2em;display:flex;gap:.4em;align-items:center">
                    <input type="hidden" name="action" value="eventcrew_redeem_credit">
                    <?php wp_nonce_field($nonce_action); ?>
                    <input type="hidden" name="roster_date" value="<?php echo esc_attr($selected_date); ?>">
                    <label for="eventcrew-redeem"><?php esc_html_e('Redeem a credit for', 'eventcrew'); ?></label>
                    <select name="person_id" id="eventcrew-redeem">
                        <?php foreach ($door['candidates'] as $eventcrew_candidate) : ?>
                            <option value="<?php echo esc_attr((string) $eventcrew_candidate['person_id']); ?>">
                                <?php echo esc_html(sprintf(
                                    /* translators: 1: person name, 2: credit balance */
                                    __('%1$s (%2$d credits)', 'eventcrew'),
                                    $eventcrew_candidate['name'],
                                    $eventcrew_candidate['credit_balance']
                                )); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e('Redeem', 'eventcrew'); ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ([] === $roster) : ?>
            <p><?php esc_html_e('No tasks on that date.', 'eventcrew'); ?></p>
        <?php endif; ?>

        <?php if ([] !== $roster) : ?>
            <?php /* One form for the whole night: each person's status picker below posts into it by id, so a single Update saves them all. */ ?>
            <form method="post" id="eventcrew-roster-save" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:.5em 0 1em">
                <input type="hidden" name="action" value="eventcrew_save_roster">
                <?php wp_nonce_field($nonce_action); ?>
                <input type="hidden" name="roster_date" value="<?php echo esc_attr($selected_date); ?>">
                <button type="submit" class="button button-primary"><?php esc_html_e('Update attendance', 'eventcrew'); ?></button>
                <span class="description" style="margin-left:.5em"><?php esc_html_e('Saves every status picker below at once.', 'eventcrew'); ?></span>
            </form>
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
                            <th style="width:9em"><?php esc_html_e('Standing', 'eventcrew'); ?></th>
                            <th style="width:16em"><?php esc_html_e('Status', 'eventcrew'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventcrew_row['people'] as $eventcrew_person) : ?>
                            <tr>
                                <td><?php echo esc_html($eventcrew_person['name']); ?></td>
                                <td><?php echo null === $eventcrew_person['standing']
                                    ? ''
                                    : $eventcrew_standing_badge($eventcrew_person['standing']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge builds its own escaped markup. ?></td>
                                <td>
                                    <select name="status[<?php echo esc_attr((string) $eventcrew_person['assignment_id']); ?>]" form="eventcrew-roster-save">
                                        <?php foreach ($statuses as $eventcrew_slug => $eventcrew_label) : ?>
                                            <option value="<?php echo esc_attr($eventcrew_slug); ?>" <?php selected($eventcrew_slug, $eventcrew_person['status']); ?>>
                                                <?php echo esc_html($eventcrew_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
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

        <?php if ([] !== $roster) : ?>
            <p style="margin:0 0 2em">
                <button type="submit" form="eventcrew-roster-save" class="button button-primary"><?php esc_html_e('Update attendance', 'eventcrew'); ?></button>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
