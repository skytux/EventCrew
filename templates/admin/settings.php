<?php
/**
 * Settings admin view.
 *
 * @var array<int, array{slug: string, label: string, emoji: string, capacity: int, archived: bool, anchor: string, start_offset: int|null, end_offset: int|null}> $roles
 * @var array{opted_in: int, verified: int} $opt_in_stats Consent counts for the open-task email.
 * @var string $nonce_action Nonce action for the save form.
 * @var bool $eventmesh_available Whether EventMesh is installed and active.
 * @var bool $auto_create_tasks Whether a newly-synced event auto-creates its tasks.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('EventCrew Settings', 'eventcrew'); ?></h1>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventcrew_save_settings">
        <?php wp_nonce_field($nonce_action); ?>

        <h2><?php esc_html_e('Roles', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('The kinds of job a task can be. "People needed" is only the default for new tasks - each task keeps its own number.', 'eventcrew'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('The timing columns are what "Create an event\'s tasks" uses. Offsets are in minutes from the chosen anchor, negative for before it: decorating might run -120 to 0 from the start, cleaning 0 to 60 from the end. Leave both blank for a role whose timing varies - its tasks are created without times for you to fill in.', 'eventcrew'); ?>
        </p>

        <table class="widefat striped" style="max-width:1100px;margin:1em 0">
            <thead>
                <tr>
                    <th style="width:5em"><?php esc_html_e('Emoji', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Label', 'eventcrew'); ?></th>
                    <th style="width:11em"><?php esc_html_e('Slug', 'eventcrew'); ?></th>
                    <th style="width:7em"><?php esc_html_e('People', 'eventcrew'); ?></th>
                    <th style="width:9em"><?php esc_html_e('Measured from', 'eventcrew'); ?></th>
                    <th style="width:7em"><?php esc_html_e('Starts', 'eventcrew'); ?></th>
                    <th style="width:7em"><?php esc_html_e('Ends', 'eventcrew'); ?></th>
                    <th style="width:7em"><?php esc_html_e('Archived', 'eventcrew'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                // One blank row is appended so a new role can always be added
                // without a JavaScript "add row" control.
                $eventcrew_rows = $roles;
                $eventcrew_rows[] = [
                    'slug' => '',
                    'label' => '',
                    'emoji' => '',
                    'capacity' => 1,
                    'archived' => false,
                    'anchor' => \EventCrew\Support\Roles::ANCHOR_START,
                    'start_offset' => null,
                    'end_offset' => null,
                ];

                $eventcrew_anchors = [
                    \EventCrew\Support\Roles::ANCHOR_START => __('Event start', 'eventcrew'),
                    \EventCrew\Support\Roles::ANCHOR_END => __('Event end', 'eventcrew'),
                ];
                ?>
                <?php foreach ($eventcrew_rows as $eventcrew_index => $eventcrew_role) : ?>
                    <tr<?php echo $eventcrew_role['archived'] ? ' style="opacity:.6"' : ''; ?>>
                        <td>
                            <input
                                type="text"
                                name="role_emoji[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr($eventcrew_role['emoji']); ?>"
                                size="2">
                        </td>
                        <td>
                            <input
                                type="text"
                                name="role_label[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr($eventcrew_role['label']); ?>"
                                class="regular-text">
                        </td>
                        <td>
                            <input
                                type="text"
                                name="role_slug[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr($eventcrew_role['slug']); ?>"
                                class="regular-text"
                                <?php echo '' !== $eventcrew_role['slug'] ? 'readonly' : ''; ?>>
                        </td>
                        <td>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                name="role_capacity[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr((string) $eventcrew_role['capacity']); ?>"
                                style="width:5em">
                        </td>
                        <td>
                            <select name="role_anchor[<?php echo esc_attr((string) $eventcrew_index); ?>]">
                                <?php foreach ($eventcrew_anchors as $eventcrew_value => $eventcrew_anchor_label) : ?>
                                    <option
                                        value="<?php echo esc_attr($eventcrew_value); ?>"
                                        <?php selected($eventcrew_role['anchor'], $eventcrew_value); ?>>
                                        <?php echo esc_html($eventcrew_anchor_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input
                                type="number"
                                step="15"
                                name="role_start_offset[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr(null === $eventcrew_role['start_offset'] ? '' : (string) $eventcrew_role['start_offset']); ?>"
                                style="width:5em">
                        </td>
                        <td>
                            <input
                                type="number"
                                step="15"
                                name="role_end_offset[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr(null === $eventcrew_role['end_offset'] ? '' : (string) $eventcrew_role['end_offset']); ?>"
                                style="width:5em">
                        </td>
                        <td>
                            <?php if ('' !== $eventcrew_role['slug']) : ?>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="role_archived[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                        value="1"
                                        <?php checked($eventcrew_role['archived']); ?>>
                                    <?php esc_html_e('Hide', 'eventcrew'); ?>
                                </label>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description">
            <?php esc_html_e('Archiving takes a role out of the task form and out of new events, while leaving every task and credit already recorded against it intact and correctly named. Roles are never deleted, because a task created months ago still has to be able to say what it was.', 'eventcrew'); ?>
        </p>

        <h2><?php esc_html_e('EventMesh', 'eventcrew'); ?></h2>
        <?php if ($eventmesh_available) : ?>
            <p>
                <label>
                    <input
                        type="checkbox"
                        name="auto_create_tasks"
                        value="1"
                        <?php checked($auto_create_tasks); ?>>
                    <?php esc_html_e('Automatically create tasks when a new event is synced from EventMesh', 'eventcrew'); ?>
                </label>
            </p>
            <p class="description">
                <?php esc_html_e('Uses the active roles\' offsets, exactly like "Create an event\'s tasks" on the Tasks page. Only ever runs the first time an event is synced - a later re-sync that corrects its time never touches tasks that may already have signups or edits on them. Use the button on the Tasks page for any event synced before this was turned on.', 'eventcrew'); ?>
            </p>
        <?php else : ?>
            <p class="description">
                <?php esc_html_e('EventMesh is not active, so there is nothing to sync tasks from.', 'eventcrew'); ?>
            </p>
        <?php endif; ?>

        <?php submit_button(__('Save settings', 'eventcrew')); ?>
    </form>

    <h2><?php esc_html_e('Open-task email', 'eventcrew'); ?></h2>
    <p>
        <?php
        printf(
            /* translators: 1: number of people opted in, 2: number with a verified email */
            esc_html__('%1$d of %2$d people with a verified address have opted in to hearing about open tasks.', 'eventcrew'),
            (int) $opt_in_stats['opted_in'],
            (int) $opt_in_stats['verified']
        );
        ?>
    </p>
    <p class="description">
        <?php esc_html_e('Consent is only ever given by the person, through the bot or the signup page - it cannot be set here. Sending arrives in a later release; until then this number is worth watching, because the first send reaches only the people counted above.', 'eventcrew'); ?>
    </p>
</div>
