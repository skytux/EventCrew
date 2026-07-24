<?php

/**
 * Tasks admin view.
 *
 * @var \EventCrew\Admin\TasksListTable $table Prepared list table of tasks.
 * @var \EventCrew\Models\Task|null $editing Task open in the editor, if any.
 * @var array<int, array{slug: string, label: string, emoji: string, capacity: int, archived: bool, anchor: string, start_offset: int|null, end_offset: int|null}> $roles
 * @var array<int, array{id: int, title: string, starts_at: string, ends_at: string, date: string}> $events Linkable EventMesh events.
 * @var bool $events_available Whether EventMesh is installed at all.
 * @var array<int, array{name: string, status: string}> $roster Signed-up people for the edited task.
 * @var string $nonce_action Nonce action for the save form.
 * @var string $template_nonce_action Nonce action for the apply-template form.
 * @var string $page_slug Admin page slug, for form targets.
 * @var string $event_other Sentinel value for the picker's "other" option.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_is_edit = $editing instanceof \EventCrew\Models\Task;

/** datetime-local wants Y-m-d\TH:i; the column holds Y-m-d H:i:s. */
$eventcrew_local = static function (?string $stored): string {
    if (null === $stored || '' === $stored) {
        return '';
    }

    return str_replace(' ', 'T', substr($stored, 0, 16));
};

$eventcrew_selected_event = $eventcrew_is_edit && null !== $editing->eventPostId
    ? (string) $editing->eventPostId
    : $event_other;
?>
<div class="wrap">
    <h1><?php esc_html_e('Tasks', 'eventcrew'); ?></h1>

    <div id="col-container" class="wp-clearfix">
        <div id="col-left" style="width:35%;float:left;padding-right:2%;box-sizing:border-box">
            <?php if ($events_available && [] !== $events && ! $eventcrew_is_edit) : ?>
                <div class="form-wrap" style="margin-bottom:2em">
                    <h2><?php esc_html_e('Create an event\'s tasks', 'eventcrew'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Creates one task per active role, with times worked out from each role\'s offsets in Settings. Roles that already have a task for the event are left alone, so this is safe to run again after adding a role.', 'eventcrew'); ?>
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="eventcrew_apply_template">
                        <?php wp_nonce_field($template_nonce_action); ?>

                        <div class="form-field">
                            <label for="eventcrew-template-event"><?php esc_html_e('Event', 'eventcrew'); ?></label>
                            <select name="template_event" id="eventcrew-template-event">
                                <?php foreach ($events as $eventcrew_event) : ?>
                                    <option value="<?php echo esc_attr((string) $eventcrew_event['id']); ?>">
                                        <?php
                                        echo esc_html(
                                            '' === $eventcrew_event['date']
                                                ? $eventcrew_event['title']
                                                : $eventcrew_event['date'] . ' — ' . $eventcrew_event['title']
                                        );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <p class="submit">
                            <button type="submit" class="button button-secondary">
                                <?php esc_html_e('Create tasks', 'eventcrew'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <div class="form-wrap">
                <h2>
                    <?php
                    echo $eventcrew_is_edit
                        ? esc_html__('Edit task', 'eventcrew')
                        : esc_html__('Add a task', 'eventcrew');
                    ?>
                </h2>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="eventcrew_save_task">
                    <?php wp_nonce_field($nonce_action); ?>
                    <?php if ($eventcrew_is_edit) : ?>
                        <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $editing->id); ?>">
                    <?php endif; ?>

                    <?php /* The event comes first because choosing one fills in everything below it. */ ?>
                    <div class="form-field">
                        <label for="eventcrew-event-choice"><?php esc_html_e('Event', 'eventcrew'); ?></label>
                        <?php if ($events_available && [] !== $events) : ?>
                            <select name="event_choice" id="eventcrew-event-choice" data-eventcrew-events>
                                <?php foreach ($events as $eventcrew_event) : ?>
                                    <option
                                        value="<?php echo esc_attr((string) $eventcrew_event['id']); ?>"
                                        data-date="<?php echo esc_attr($eventcrew_event['date']); ?>"
                                        data-starts="<?php echo esc_attr(str_replace(' ', 'T', substr($eventcrew_event['starts_at'], 0, 16))); ?>"
                                        data-ends="<?php echo esc_attr(str_replace(' ', 'T', substr($eventcrew_event['ends_at'], 0, 16))); ?>"
                                        <?php selected($eventcrew_selected_event, (string) $eventcrew_event['id']); ?>>
                                        <?php
                                        echo esc_html(
                                            '' === $eventcrew_event['date']
                                                ? $eventcrew_event['title']
                                                : $eventcrew_event['date'] . ' — ' . $eventcrew_event['title']
                                        );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                                <option
                                    value="<?php echo esc_attr($event_other); ?>"
                                    <?php selected($eventcrew_selected_event, $event_other); ?>>
                                    <?php esc_html_e('Other (type a name)', 'eventcrew'); ?>
                                </option>
                            </select>
                            <p><?php esc_html_e('Picking an event fills in the date and times below. Change them afterwards if this task runs to its own schedule.', 'eventcrew'); ?></p>
                        <?php else : ?>
                            <input type="hidden" name="event_choice" value="<?php echo esc_attr($event_other); ?>">
                            <p class="description">
                                <?php esc_html_e('No linkable events found. Type a name below instead.', 'eventcrew'); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-field" data-eventcrew-other-label<?php
                        echo $event_other === $eventcrew_selected_event ? '' : ' style="display:none"';
                    ?>>
                        <label for="eventcrew-event-label"><?php esc_html_e('Event name', 'eventcrew'); ?></label>
                        <input
                            name="event_label"
                            id="eventcrew-event-label"
                            type="text"
                            value="<?php echo esc_attr($eventcrew_is_edit ? $editing->eventLabel : ''); ?>">
                    </div>

                    <div class="form-field form-required">
                        <label for="eventcrew-task-date"><?php esc_html_e('Task date', 'eventcrew'); ?></label>
                        <input
                            name="task_date"
                            id="eventcrew-task-date"
                            type="date"
                            required
                            value="<?php echo esc_attr($eventcrew_is_edit ? $editing->taskDate : ''); ?>">
                        <p><?php esc_html_e('The day this task is filed under. A clean-up at 01:00 after a Saturday event still belongs to Saturday.', 'eventcrew'); ?></p>
                    </div>

                    <div class="form-field form-required">
                        <label for="eventcrew-role-slug"><?php esc_html_e('Role', 'eventcrew'); ?></label>
                        <select name="role_slug" id="eventcrew-role-slug" required>
                            <?php foreach ($roles as $eventcrew_role) : ?>
                                <option
                                    value="<?php echo esc_attr($eventcrew_role['slug']); ?>"
                                    data-capacity="<?php echo esc_attr((string) $eventcrew_role['capacity']); ?>"
                                    <?php selected($eventcrew_is_edit ? $editing->roleSlug : '', $eventcrew_role['slug']); ?>>
                                    <?php
                                    echo esc_html(
                                        $eventcrew_role['archived']
                                            ? sprintf(
                                                /* translators: %s: role label */
                                                __('%s (archived)', 'eventcrew'),
                                                $eventcrew_role['label']
                                            )
                                            : $eventcrew_role['label']
                                    );
                                    ?>
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
                            min="0"
                            step="1"
                            value="<?php echo esc_attr((string) ($eventcrew_is_edit ? $editing->capacity : 2)); ?>">
                        <p><?php esc_html_e('Defaults come from the role in Settings. Set to 0 to take a task off the board without deleting it.', 'eventcrew'); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-starts-at"><?php esc_html_e('Starts', 'eventcrew'); ?></label>
                        <input
                            name="starts_at"
                            id="eventcrew-starts-at"
                            type="datetime-local"
                            value="<?php echo esc_attr($eventcrew_local($editing->startsAt ?? null)); ?>">
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-ends-at"><?php esc_html_e('Ends', 'eventcrew'); ?></label>
                        <input
                            name="ends_at"
                            id="eventcrew-ends-at"
                            type="datetime-local"
                            value="<?php echo esc_attr($eventcrew_local($editing->endsAt ?? null)); ?>">
                        <p><?php esc_html_e('Leave both blank if times are not decided. Overlapping tasks can only be detected when times are set. An end earlier than the start is read as the next morning.', 'eventcrew'); ?></p>
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
                                ? esc_html__('Update task', 'eventcrew')
                                : esc_html__('Add task', 'eventcrew');
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
<?php if ($events_available && [] !== $events) : ?>
<script>
// Populates the date and times from the chosen event, and shows the free-text
// name only for "Other". Inline and dependency-free because this is the whole
// of EventCrew's JavaScript - enqueuing a file for it would cost a build step
// and an asset pipeline the plugin otherwise has no use for.
//
// Everything it does is a convenience: the fields it fills are ordinary inputs
// the organizer can overwrite, and with JavaScript off the form still submits
// correctly - just without the auto-fill.
( function () {
    var picker = document.querySelector( '[data-eventcrew-events]' );
    var otherRow = document.querySelector( '[data-eventcrew-other-label]' );

    if ( ! picker ) {
        return;
    }

    function apply( overwrite ) {
        var option = picker.options[ picker.selectedIndex ];
        var isOther = option.value === <?php echo wp_json_encode($event_other); ?>;

        if ( otherRow ) {
            otherRow.style.display = isOther ? '' : 'none';
        }

        if ( isOther ) {
            return;
        }

        [
            [ 'eventcrew-task-date', option.getAttribute( 'data-date' ) ],
            [ 'eventcrew-starts-at', option.getAttribute( 'data-starts' ) ],
            [ 'eventcrew-ends-at', option.getAttribute( 'data-ends' ) ]
        ].forEach( function ( pair ) {
            var field = document.getElementById( pair[ 0 ] );

            // Never clobber something already typed unless the organizer just
            // changed the event, which is an explicit "use this one instead".
            if ( field && pair[ 1 ] && ( overwrite || ! field.value ) ) {
                field.value = pair[ 1 ];
            }
        } );
    }

    picker.addEventListener( 'change', function () {
        apply( true );
    } );

    apply( false );
}() );
</script>
<?php endif; ?>
