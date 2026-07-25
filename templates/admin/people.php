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
 * @var array<int, array{slug: string, label: string}> $lead_roles Active roles, for the Leadership columns.
 * @var array<int, array{id: int, name: string, can_lead: bool, by_role: array<string, int>}> $lead_eligible People who meet the leader bar.
 * @var array<int, array{id: int, name: string}> $lead_allowed People with leader permission.
 * @var int $lead_threshold Completions required in each role.
 * @var bool $lead_default Whether the leader slot is on by default globally.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_is_edit = $editing instanceof \EventCrew\Models\Person;
?>
<div class="wrap">
    <h1><?php esc_html_e('People', 'eventcrew'); ?></h1>

    <h2 class="nav-tab-wrapper eventcrew-people-tabs" style="margin:.5em 0 1em">
        <a href="#" class="nav-tab" data-ec-tab="people"><?php esc_html_e('People', 'eventcrew'); ?></a>
        <a href="#" class="nav-tab" data-ec-tab="leadership"><?php esc_html_e('Leadership', 'eventcrew'); ?></a>
    </h2>

    <div class="ec-tab-panel" data-ec-tab="people">
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
                        <label for="eventcrew-can-lead">
                            <input
                                name="can_lead"
                                id="eventcrew-can-lead"
                                type="checkbox"
                                value="1"
                                <?php checked($eventcrew_is_edit && $editing->canLead); ?>>
                            <?php esc_html_e('Crew leader', 'eventcrew'); ?>
                        </label>
                        <p><?php esc_html_e('May take the reserved 🧭 Leader slot and mark that night’s crew from the bot, without being an organizer.', 'eventcrew'); ?></p>
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

                    <h3><?php esc_html_e('One-time at-risk pass', 'eventcrew'); ?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="eventcrew_grant_pass">
                        <?php wp_nonce_field($nonce_action); ?>
                        <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $editing->id); ?>">
                        <p class="submit">
                            <button type="submit" class="button"><?php echo $editing->hasAtRiskPass
                                ? esc_html__('Pass already granted — re-grant', 'eventcrew')
                                : esc_html__('Grant a one-time pass', 'eventcrew'); ?></button>
                        </p>
                        <p class="description"><?php esc_html_e('Lets them sign up once even if their standing is at risk. Spent automatically on their next successful signup.', 'eventcrew'); ?></p>
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

    <div class="ec-tab-panel" data-ec-tab="leadership">
        <p class="description">
            <?php echo esc_html(sprintf(
                /* translators: %d: completions required in each role */
                __('Eligible = has completed at least %d tasks in every active role. Eligibility is only a suggestion; grant leader by editing a person, or with the bot’s /allow.', 'eventcrew'),
                $lead_threshold
            )); ?>
            <br>
            <?php echo $lead_default
                ? esc_html__('The leader slot is on by default for new events.', 'eventcrew')
                : esc_html__('The leader slot is off by default; turn it on per event on the Roster screen.', 'eventcrew'); ?>
        </p>

        <h2><?php esc_html_e('Eligible', 'eventcrew'); ?></h2>
        <?php if ([] === $lead_eligible) : ?>
            <p><?php esc_html_e('Nobody has completed enough of every role yet.', 'eventcrew'); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:60em">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Person', 'eventcrew'); ?></th>
                        <?php foreach ($lead_roles as $eventcrew_role) : ?>
                            <th><?php echo esc_html($eventcrew_role['label']); ?></th>
                        <?php endforeach; ?>
                        <th><?php esc_html_e('Leader', 'eventcrew'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lead_eligible as $eventcrew_leader) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['page' => $page_slug, 'person' => $eventcrew_leader['id']], admin_url('admin.php'))); ?>">
                                    <?php echo esc_html($eventcrew_leader['name']); ?>
                                </a>
                            </td>
                            <?php foreach ($lead_roles as $eventcrew_role) : ?>
                                <td><?php echo esc_html((string) ($eventcrew_leader['by_role'][$eventcrew_role['slug']] ?? 0)); ?></td>
                            <?php endforeach; ?>
                            <td><?php echo $eventcrew_leader['can_lead']
                                ? '<span class="dashicons dashicons-yes" style="color:#1a7f37"></span>'
                                : '&mdash;'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?php esc_html_e('Allowed to lead', 'eventcrew'); ?></h2>
        <?php if ([] === $lead_allowed) : ?>
            <p><?php esc_html_e('No one has been granted leader permission yet.', 'eventcrew'); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:40em">
                <tbody>
                    <?php foreach ($lead_allowed as $eventcrew_leader) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['page' => $page_slug, 'person' => $eventcrew_leader['id']], admin_url('admin.php'))); ?>">
                                    <?php echo esc_html($eventcrew_leader['name']); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
    // In-page tabs, the same pattern as Settings. With JS off both panels show.
    (function () {
        var nav = document.querySelector('.eventcrew-people-tabs');
        if (!nav) { return; }

        var panels = document.querySelectorAll('.ec-tab-panel');
        var tabs = nav.querySelectorAll('.nav-tab');

        function show(key) {
            panels.forEach(function (p) {
                p.style.display = p.getAttribute('data-ec-tab') === key ? '' : 'none';
            });
            tabs.forEach(function (t) {
                t.classList.toggle('nav-tab-active', t.getAttribute('data-ec-tab') === key);
            });
            try { localStorage.setItem('eventcrew_people_tab', key); } catch (e) {}
        }

        tabs.forEach(function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                show(t.getAttribute('data-ec-tab'));
            });
        });

        // Editing a person always belongs on the People tab; otherwise reopen
        // the last-used tab (a save reloads the page), else People.
        var initial = 'people';
        <?php if (! $eventcrew_is_edit) : ?>
        try {
            var saved = localStorage.getItem('eventcrew_people_tab');
            if (saved && nav.querySelector('.nav-tab[data-ec-tab="' + saved + '"]')) {
                initial = saved;
            }
        } catch (e) {}
        <?php endif; ?>
        show(initial);
    })();
    </script>
</div>
