<?php

/**
 * People admin view.
 *
 * @var \EventCrew\Admin\PeopleListTable $table Prepared list table of people.
 * @var \EventCrew\Models\Person|null $editing Person open in the editor, if any.
 * @var \EventCrew\Support\Standing|null $editing_standing Standing of the edited person.
 * @var array<int, array{person: string, credits: int, note: string, granted_by: string, granted_at: string}> $credit_grants Recent credit-grant audit rows.
 * @var string $nonce_action Nonce action for the save form.
 * @var string $page_slug Admin page slug, for form targets.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_is_edit = $editing instanceof \EventCrew\Models\Person;
?>
<div class="wrap">
    <h1><?php esc_html_e('People', 'eventcrew'); ?></h1>

    <div id="col-container" class="wp-clearfix">
        <div id="col-left" style="width:35%;float:left;padding-right:2%;box-sizing:border-box">
            <div class="form-wrap">
                <h2>
                    <?php
                    echo $eventcrew_is_edit
                        ? esc_html__('Edit person', 'eventcrew')
                        : esc_html__('Add a person', 'eventcrew');
                    ?>
                </h2>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="eventcrew_save_person">
                    <?php wp_nonce_field($nonce_action); ?>
                    <?php if ($eventcrew_is_edit) : ?>
                        <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $editing->id); ?>">
                    <?php endif; ?>

                    <div class="form-field form-required">
                        <label for="eventcrew-email"><?php esc_html_e('Email', 'eventcrew'); ?></label>
                        <input
                            name="email"
                            id="eventcrew-email"
                            type="email"
                            required
                            value="<?php echo esc_attr($eventcrew_is_edit ? $editing->email : ''); ?>">
                        <p><?php esc_html_e('This is how a person is identified across Telegram and the web.', 'eventcrew'); ?></p>
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
                        <label for="eventcrew-notify-muted">
                            <input
                                name="notify_muted"
                                id="eventcrew-notify-muted"
                                type="checkbox"
                                value="1"
                                <?php checked($eventcrew_is_edit && $editing->notifyMuted); ?>>
                            <?php esc_html_e('Mute bot DMs', 'eventcrew'); ?>
                        </label>
                        <p><?php esc_html_e('Stop the bot sending this person signup, cancellation and no-show confirmations by direct message. The group board is unaffected.', 'eventcrew'); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="eventcrew-person-notes"><?php esc_html_e('Notes', 'eventcrew'); ?></label>
                        <textarea name="notes" id="eventcrew-person-notes" rows="3"><?php
                            echo esc_textarea($eventcrew_is_edit ? $editing->notes : '');
                        ?></textarea>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php
                            echo $eventcrew_is_edit
                                ? esc_html__('Update person', 'eventcrew')
                                : esc_html__('Add person', 'eventcrew');
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
                                <td><?php esc_html_e('Open-task email', 'eventcrew'); ?></td>
                                <td>
                                    <?php if ($editing->acceptsOpenTaskEmail()) : ?>
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
                            <?php if (null !== $editing_standing) : ?>
                                <tr>
                                    <td><?php esc_html_e('Standing', 'eventcrew'); ?></td>
                                    <td><?php echo esc_html($editing_standing->ratedSummary()); ?></td>
                                </tr>
                                <tr>
                                    <td><?php esc_html_e('Free-entry credits', 'eventcrew'); ?></td>
                                    <td><?php echo esc_html((string) $editing_standing->creditBalance); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p class="description">
                        <?php esc_html_e('Verification and open-task consent can only be given by the person themselves, so neither can be set from this screen.', 'eventcrew'); ?>
                    </p>

                    <h3><?php esc_html_e('Give a free-entry credit', 'eventcrew'); ?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="eventcrew_grant_credit">
                        <?php wp_nonce_field($nonce_action); ?>
                        <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $editing->id); ?>">
                        <div class="form-field">
                            <label for="eventcrew-grant-credits"><?php esc_html_e('Credits', 'eventcrew'); ?></label>
                            <input name="grant_credits" id="eventcrew-grant-credits" type="number" value="1" min="1" step="1" style="width:5em">
                        </div>
                        <div class="form-field">
                            <label for="eventcrew-grant-note"><?php esc_html_e('Reason (optional)', 'eventcrew'); ?></label>
                            <input name="grant_note" id="eventcrew-grant-note" type="text" placeholder="<?php esc_attr_e('e.g. covered a cleaning task', 'eventcrew'); ?>">
                        </div>
                        <p class="submit">
                            <button type="submit" class="button"><?php esc_html_e('Grant credits', 'eventcrew'); ?></button>
                        </p>
                        <p class="description"><?php esc_html_e('A one-off bonus, on top of the credits earned for completed tasks.', 'eventcrew'); ?></p>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div id="col-right" style="width:63%;float:left">
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
                <?php $table->search_box(__('Search people', 'eventcrew'), 'eventcrew-person-search'); ?>
            </form>
            <?php $table->display(); ?>
        </div>
    </div>

    <?php if ([] !== $credit_grants) : ?>
        <h2><?php esc_html_e('Recent credit grants', 'eventcrew'); ?></h2>
        <table class="wp-list-table widefat fixed striped" style="max-width:60em">
            <thead>
                <tr>
                    <th><?php esc_html_e('Person', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Credits', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Reason', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Granted by', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('When', 'eventcrew'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($credit_grants as $eventcrew_grant) : ?>
                    <tr>
                        <td><?php echo esc_html($eventcrew_grant['person']); ?></td>
                        <td><?php echo esc_html((string) $eventcrew_grant['credits']); ?></td>
                        <td><?php echo '' === $eventcrew_grant['note'] ? '&mdash;' : esc_html($eventcrew_grant['note']); ?></td>
                        <td><?php echo esc_html($eventcrew_grant['granted_by']); ?></td>
                        <td><?php echo esc_html($eventcrew_grant['granted_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
