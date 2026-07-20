<?php
/**
 * Settings admin view.
 *
 * @var array<int, array{slug: string, label: string, emoji: string, capacity: int}> $task_types
 * @var array{opted_in: int, verified: int} $opt_in_stats Consent counts for the open-shift email.
 * @var string $nonce_action Nonce action for the save form.
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

        <h2><?php esc_html_e('Task groups', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('The kinds of job a shift can be. "People needed" is only the default for new shifts - each shift keeps its own number.', 'eventcrew'); ?>
        </p>

        <table class="widefat striped" style="max-width:760px;margin:1em 0">
            <thead>
                <tr>
                    <th style="width:8em"><?php esc_html_e('Emoji', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Label', 'eventcrew'); ?></th>
                    <th style="width:14em"><?php esc_html_e('Slug', 'eventcrew'); ?></th>
                    <th style="width:10em"><?php esc_html_e('People needed', 'eventcrew'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                // One blank row is appended so a new group can always be added
                // without a JavaScript "add row" control.
                $eventcrew_rows = $task_types;
                $eventcrew_rows[] = ['slug' => '', 'label' => '', 'emoji' => '', 'capacity' => 1];
                ?>
                <?php foreach ($eventcrew_rows as $eventcrew_index => $eventcrew_type) : ?>
                    <tr>
                        <td>
                            <input
                                type="text"
                                name="task_emoji[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr($eventcrew_type['emoji']); ?>"
                                size="2">
                        </td>
                        <td>
                            <input
                                type="text"
                                name="task_label[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr($eventcrew_type['label']); ?>"
                                class="regular-text">
                        </td>
                        <td>
                            <input
                                type="text"
                                name="task_slug[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr($eventcrew_type['slug']); ?>"
                                class="regular-text"
                                <?php echo '' !== $eventcrew_type['slug'] ? 'readonly' : ''; ?>>
                        </td>
                        <td>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                name="task_capacity[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr((string) $eventcrew_type['capacity']); ?>"
                                style="width:6em">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description">
            <?php esc_html_e('Clearing a label removes that group. Existing shifts keep their stored task, so removing a group in use will show its raw slug on the roster.', 'eventcrew'); ?>
        </p>

        <?php submit_button(__('Save settings', 'eventcrew')); ?>
    </form>

    <h2><?php esc_html_e('Open-shift email', 'eventcrew'); ?></h2>
    <p>
        <?php
        printf(
            /* translators: 1: number of volunteers opted in, 2: number with a verified email */
            esc_html__('%1$d of %2$d volunteers with a verified address have opted in to hearing about open shifts.', 'eventcrew'),
            (int) $opt_in_stats['opted_in'],
            (int) $opt_in_stats['verified']
        );
        ?>
    </p>
    <p class="description">
        <?php esc_html_e('Consent is only ever given by the volunteer, through the bot or the signup page - it cannot be set here. Sending arrives in a later release; until then this number is worth watching, because the first send reaches only the people counted above.', 'eventcrew'); ?>
    </p>
</div>
