<?php
/**
 * Shifts admin view.
 *
 * @var \EventCrew\Admin\ShiftsListTable $table Prepared list table of shifts.
 * @var \EventCrew\Models\Shift|null $editing Shift open in the editor, if any.
 * @var array<int, array{slug: string, label: string, emoji: string, capacity: int}> $task_types
 * @var array<int, array{name: string, status: string}> $roster Signed-up volunteers for the edited shift.
 * @var string $nonce_action Nonce action for the save form.
 * @var string $page_slug Admin page slug, for form targets.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_is_edit = $editing instanceof \EventCrew\Models\Shift;
?>
<div class="wrap">
    <h1><?php esc_html_e('Shifts', 'eventcrew'); ?></h1>

    <div id="col-container" class="wp-clearfix">
        <div id="col-left" style="width:35%;float:left;padding-right:2%;box-sizing:border-box">
            <div class="form-wrap">
                <h2>
                    <?php
                    echo $eventcrew_is_edit
                        ? esc_html__('Edit shift', 'eventcrew')
                        : esc_html__('Add a shift', 'eventcrew');
                    ?>
                </h2>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="eventcrew_save_shift">
                    <?php wp_nonce_field($nonce_action); ?>
                    <?php if ($eventcrew_is_edit) : ?>
                        <input type="hidden" name="shift_id" value="<?php echo esc_attr((string) $editing->id); ?>">
                    <?php endif; ?>

                    <div class="form-field form-required">
                        <label for="eventcrew-shift-date"><?php esc_html_e('Date', 'eventcrew'); ?></label>
                        <input
                            name="shift_date"
                            id="eventcrew-shift-date"
                            type="date"
                            required
                            value="<?php echo esc_attr($eventcrew_is_edit ? $editing->shiftDate : ''); ?>">
                    </div>

                    <div class="form-field form-required">
                        <label for="eventcrew-task-slug"><?php esc_html_e('Task', 'eventcrew'); ?></label>
                        <select name="task_slug" id="eventcrew-task-slug" required>
                            <?php foreach ($task_types as $eventcrew_type) : ?>
                                <option
                                    value="<?php echo esc_attr($eventcrew_type['slug']); ?>"
                                    data-capacity="<?php echo esc_attr((string) $eventcrew_type['capacity']); ?>"
                                    <?php selected($eventcrew_is_edit ? $editing->taskSlug : '', $eventcrew_type['slug']); ?>>
                                    <?php echo esc_html($eventcrew_type['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-capacity"><?php esc_html_e('People needed', 'eventcrew'); ?></label>
                        <input
                            name="capacity"
                            id="eventcrew-capacity"
                            type="number"
                            min="1"
                            step="1"
                            value="<?php echo esc_attr((string) ($eventcrew_is_edit ? $editing->capacity : 2)); ?>">
                        <p><?php esc_html_e('Defaults come from the task group in Settings.', 'eventcrew'); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-starts-at"><?php esc_html_e('Starts', 'eventcrew'); ?></label>
                        <input
                            name="starts_at"
                            id="eventcrew-starts-at"
                            type="time"
                            value="<?php echo esc_attr($eventcrew_is_edit ? substr((string) $editing->startsAt, 0, 5) : ''); ?>">
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-ends-at"><?php esc_html_e('Ends', 'eventcrew'); ?></label>
                        <input
                            name="ends_at"
                            id="eventcrew-ends-at"
                            type="time"
                            value="<?php echo esc_attr($eventcrew_is_edit ? substr((string) $editing->endsAt, 0, 5) : ''); ?>">
                        <p><?php esc_html_e('Leave both blank if times are not decided. Overlapping shifts can only be detected when times are set.', 'eventcrew'); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-event-label"><?php esc_html_e('Event name', 'eventcrew'); ?></label>
                        <input
                            name="event_label"
                            id="eventcrew-event-label"
                            type="text"
                            value="<?php echo esc_attr($eventcrew_is_edit ? $editing->eventLabel : ''); ?>">
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-event-post-id"><?php esc_html_e('Linked event post ID', 'eventcrew'); ?></label>
                        <input
                            name="event_post_id"
                            id="eventcrew-event-post-id"
                            type="number"
                            min="0"
                            step="1"
                            value="<?php echo esc_attr((string) ($eventcrew_is_edit ? $editing->eventPostId : '')); ?>">
                        <p><?php esc_html_e('Optional. If EventMesh is installed, point this at the synced event post to use its title.', 'eventcrew'); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-notes"><?php esc_html_e('Notes', 'eventcrew'); ?></label>
                        <textarea name="notes" id="eventcrew-notes" rows="3"><?php
                            echo esc_textarea($eventcrew_is_edit ? $editing->notes : '');
                        ?></textarea>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php
                            echo $eventcrew_is_edit
                                ? esc_html__('Update shift', 'eventcrew')
                                : esc_html__('Add shift', 'eventcrew');
                            ?>
                        </button>
                        <?php if ($eventcrew_is_edit) : ?>
                            <a
                                href="<?php echo esc_url(add_query_arg(['page' => $page_slug], admin_url('admin.php'))); ?>"
                                class="button button-secondary">
                                <?php esc_html_e('Cancel', 'eventcrew'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </form>

                <?php if ($eventcrew_is_edit && [] !== $roster) : ?>
                    <h3><?php esc_html_e('Signed up', 'eventcrew'); ?></h3>
                    <ul class="ul-disc">
                        <?php foreach ($roster as $eventcrew_entry) : ?>
                            <li>
                                <?php
                                printf(
                                    '%s <span style="color:#646970">(%s)</span>',
                                    esc_html($eventcrew_entry['name']),
                                    esc_html($eventcrew_entry['status'])
                                );
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div id="col-right" style="width:63%;float:left">
            <?php $table->display(); ?>
        </div>
    </div>
</div>
