<?php
/**
 * Volunteers admin view.
 *
 * @var \EventCrew\Admin\VolunteersListTable $table Prepared list table of volunteers.
 * @var \EventCrew\Models\Volunteer|null $editing Volunteer open in the editor, if any.
 * @var string $nonce_action Nonce action for the save form.
 * @var string $page_slug Admin page slug, for form targets.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_is_edit = $editing instanceof \EventCrew\Models\Volunteer;
?>
<div class="wrap">
    <h1><?php esc_html_e('Volunteers', 'eventcrew'); ?></h1>

    <div id="col-container" class="wp-clearfix">
        <div id="col-left" style="width:35%;float:left;padding-right:2%;box-sizing:border-box">
            <div class="form-wrap">
                <h2>
                    <?php
                    echo $eventcrew_is_edit
                        ? esc_html__('Edit volunteer', 'eventcrew')
                        : esc_html__('Add a volunteer', 'eventcrew');
                    ?>
                </h2>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="eventcrew_save_volunteer">
                    <?php wp_nonce_field($nonce_action); ?>
                    <?php if ($eventcrew_is_edit) : ?>
                        <input type="hidden" name="volunteer_id" value="<?php echo esc_attr((string) $editing->id); ?>">
                    <?php endif; ?>

                    <div class="form-field form-required">
                        <label for="eventcrew-email"><?php esc_html_e('Email', 'eventcrew'); ?></label>
                        <input
                            name="email"
                            id="eventcrew-email"
                            type="email"
                            required
                            value="<?php echo esc_attr($eventcrew_is_edit ? $editing->email : ''); ?>">
                        <p><?php esc_html_e('This is how a volunteer is identified across Telegram and the web.', 'eventcrew'); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-display-name"><?php esc_html_e('Name', 'eventcrew'); ?></label>
                        <input
                            name="display_name"
                            id="eventcrew-display-name"
                            type="text"
                            value="<?php echo esc_attr($eventcrew_is_edit ? $editing->displayName : ''); ?>">
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-is-organizer">
                            <input
                                name="is_organizer"
                                id="eventcrew-is-organizer"
                                type="checkbox"
                                value="1"
                                <?php checked($eventcrew_is_edit && $editing->isOrganizer); ?>>
                            <?php esc_html_e('Organizer', 'eventcrew'); ?>
                        </label>
                        <p><?php esc_html_e('Organizers can mark attendance from the bot.', 'eventcrew'); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-volunteer-notes"><?php esc_html_e('Notes', 'eventcrew'); ?></label>
                        <textarea name="notes" id="eventcrew-volunteer-notes" rows="3"><?php
                            echo esc_textarea($eventcrew_is_edit ? $editing->notes : '');
                        ?></textarea>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php
                            echo $eventcrew_is_edit
                                ? esc_html__('Update volunteer', 'eventcrew')
                                : esc_html__('Add volunteer', 'eventcrew');
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

                <?php if ($eventcrew_is_edit) : ?>
                    <h3><?php esc_html_e('Status', 'eventcrew'); ?></h3>
                    <table class="widefat striped">
                        <tbody>
                            <tr>
                                <td><?php esc_html_e('Email verified', 'eventcrew'); ?></td>
                                <td>
                                    <?php
                                    echo $editing->isEmailVerified()
                                        ? esc_html($editing->emailVerifiedAt ?? '')
                                        : esc_html__('Not yet', 'eventcrew');
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e('Telegram linked', 'eventcrew'); ?></td>
                                <td>
                                    <?php
                                    echo $editing->hasTelegram()
                                        ? esc_html__('Yes', 'eventcrew')
                                        : esc_html__('No', 'eventcrew');
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e('Open-shift email', 'eventcrew'); ?></td>
                                <td>
                                    <?php if ($editing->acceptsOpenShiftEmail()) : ?>
                                        <?php
                                        printf(
                                            /* translators: 1: date consent was given, 2: where it was given */
                                            esc_html__('Opted in %1$s (%2$s)', 'eventcrew'),
                                            esc_html($editing->emailOptInAt ?? ''),
                                            esc_html($editing->emailOptInSource)
                                        );
                                        ?>
                                    <?php else : ?>
                                        <?php esc_html_e('Not opted in', 'eventcrew'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="description">
                        <?php esc_html_e('Verification and open-shift consent can only be given by the volunteer themselves, so neither can be set from this screen.', 'eventcrew'); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div id="col-right" style="width:63%;float:left">
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
                <?php $table->search_box(__('Search volunteers', 'eventcrew'), 'eventcrew-volunteer-search'); ?>
            </form>
            <?php $table->display(); ?>
        </div>
    </div>
</div>
