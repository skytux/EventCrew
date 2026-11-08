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
 * @var list<array{label: string, conditions: string, people: list<array{id: int, name: string, holds: bool}>}> $condition_roles Roles with conditions and who meets them.
 * @var list<array{id: int, name: string, email: string, when: string, by: string, reason: string}>|null $turned_down Turned-down newcomer requests (Turned down tab), null when not wired.
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
        <a href="#" class="nav-tab" data-ec-tab="conditions"><?php esc_html_e('Role conditions', 'eventcrew'); ?></a>
        <?php if (null !== ($turned_down ?? null)) : ?>
            <a href="#" class="nav-tab" data-ec-tab="turned-down"><?php esc_html_e('Turned down', 'eventcrew'); ?></a>
        <?php endif; ?>
    </h2>

    <div class="ec-tab-panel" data-ec-tab="people">
    <div id="col-container" class="wp-clearfix">
        <div id="col-left" class="eventcrew-col-left">
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

                    <?php $eventcrew_restricted_roles = \EventCrew\Support\Roles::assignable(); ?>
                    <?php if ([] !== $eventcrew_restricted_roles) : ?>
                        <div class="form-field">
                            <p><strong><?php esc_html_e('Roles', 'eventcrew'); ?></strong></p>
                            <?php foreach ($eventcrew_restricted_roles as $eventcrew_restricted_role) : ?>
                                <label style="display:block">
                                    <input
                                        name="roles[]"
                                        type="checkbox"
                                        value="<?php echo esc_attr($eventcrew_restricted_role['slug']); ?>"
                                        <?php checked($eventcrew_is_edit && $editing->hasRole($eventcrew_restricted_role['slug'])); ?>>
                                    <?php echo esc_html(\EventCrew\Support\Roles::display($eventcrew_restricted_role['slug'])); ?>
                                </label>
                            <?php endforeach; ?>
                            <p><?php esc_html_e('Only people with a role see its tasks and can sign up for them.', 'eventcrew'); ?></p>
                        </div>
                    <?php endif; ?>

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
                            <?php
                            /*
                             * This row used to call an acceptsOpenTaskEmail()
                             * that does not exist on Person, so opening anyone
                             * here was a fatal error - and it reported an
                             * "opted in" date from a column nothing has written
                             * since the opt-in model was dropped in v0.6.
                             *
                             * What it shows now is the state that actually
                             * governs the send: the per-type preference, which
                             * defaults on and which only the person themselves
                             * can change.
                             */
                            $eventcrew_prefs = new \EventCrew\Support\NotificationPreferences();
                            $eventcrew_open_task = \EventCrew\Support\NotificationPreferences::OPEN_TASK;
                            ?>
                            <tr>
                                <td><?php esc_html_e('Open-task alerts', 'eventcrew'); ?></td>
                                <td>
                                    <?php
                                    $eventcrew_channels = [];

                                    if ($eventcrew_prefs->emailAllowed($editing, $eventcrew_open_task)) {
                                        $eventcrew_channels[] = __('email', 'eventcrew');
                                    }

                                    if ($eventcrew_prefs->dmAllowed($editing, $eventcrew_open_task)) {
                                        $eventcrew_channels[] = __('Telegram', 'eventcrew');
                                    }

                                    echo [] === $eventcrew_channels
                                        ? esc_html__('Switched off', 'eventcrew')
                                        : esc_html(implode(__(' and ', 'eventcrew'), $eventcrew_channels));
                                    ?>
                                </td>
                            </tr>
                            <?php if ($editing->isAnonymized()) : ?>
                                <tr>
                                    <td><?php esc_html_e('Anonymised', 'eventcrew'); ?></td>
                                    <td>
                                        <?php
                                        printf(
                                            /* translators: %s: the date the record was stripped */
                                            esc_html__('%s — retention period passed', 'eventcrew'),
                                            esc_html($editing->anonymizedAt ?? '')
                                        );
                                        ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if (null !== $editing_standing) : ?>
                                <tr>
                                    <td><?php esc_html_e('Standing', 'eventcrew'); ?></td>
                                    <td><?php echo esc_html($editing_standing->ratedSummary()); ?></td>
                                </tr>
                                <tr>
                                    <td><?php esc_html_e('Free-entry credits', 'eventcrew'); ?></td>
                                    <td><?php echo esc_html((string) $editing_standing->creditBalance . \EventCrew\Support\ExtraTaskCredits::pendingSuffix((new \EventCrew\Support\ExtraTaskCredits())->pendingFor($editing->id))); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p class="description">
                        <?php esc_html_e('Verifying an email and choosing which alerts to receive are both things only the person themselves can do, so neither can be set from this screen.', 'eventcrew'); ?>
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

                    <?php if ($editing->isBanned()) : ?>
                        <h3><?php esc_html_e('Blocked', 'eventcrew'); ?></h3>
                        <p>
                            <?php
                            printf(
                                /* translators: %s: date and time the block was set */
                                esc_html__('Blocked since %s. They cannot sign up for tasks or sign in on the web.', 'eventcrew'),
                                esc_html((string) $editing->bannedAt)
                            );
                            ?>
                        </p>
                        <?php if ('' !== $editing->banReason) : ?>
                            <p class="description"><?php echo esc_html($editing->banReason); ?></p>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="eventcrew_unban_person">
                            <?php wp_nonce_field($nonce_action); ?>
                            <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $editing->id); ?>">
                            <p class="submit">
                                <button type="submit" class="button"><?php esc_html_e('Unban', 'eventcrew'); ?></button>
                            </p>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="col-right" class="eventcrew-col-right">
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
                <?php $table->search_box(__('Search people', 'eventcrew'), 'eventcrew-person-search'); ?>
            </form>
            <?php $table->display(); ?>
        </div>
    </div>

    <?php if ([] !== $credit_grants) : ?>
        <h2><?php esc_html_e('Recent credit grants', 'eventcrew'); ?></h2>
        <div class="eventcrew-scroll">
        <table class="widefat striped" style="max-width:60em">
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
        </div>
    <?php endif; ?>
    </div>

    <?php if (null !== ($turned_down ?? null)) : ?>
    <div class="ec-tab-panel" data-ec-tab="turned-down">
        <p class="description" style="max-width:60em">
            <?php esc_html_e('Newcomers an organizer turned down, and requests nobody answered in 48 hours. Accept gives the OK as the OK button does: they can take tasks, and they are told.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
        </p>
        <?php if ([] === $turned_down) : ?>
            <p><?php esc_html_e('Nobody was turned down.', 'eventcrew'); ?></p>
        <?php else : ?>
            <div class="eventcrew-scroll">
            <table class="widefat striped" style="max-width:70em">
                <thead>
                    <tr>
                        <th><?php esc_html_e('When', 'eventcrew'); ?></th>
                        <th><?php esc_html_e('Person', 'eventcrew'); ?></th>
                        <th><?php esc_html_e('Email', 'eventcrew'); ?></th>
                        <th><?php esc_html_e('Turned down by', 'eventcrew'); ?></th>
                        <th><?php esc_html_e('Reason', 'eventcrew'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($turned_down as $eventcrew_refused) : ?>
                        <tr>
                            <td><?php echo '' === $eventcrew_refused['when'] ? '&mdash;' : esc_html($eventcrew_refused['when']); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['page' => $page_slug, 'person' => $eventcrew_refused['id']], admin_url('admin.php'))); ?>">
                                    <?php echo esc_html($eventcrew_refused['name']); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($eventcrew_refused['email']); ?></td>
                            <td><?php echo '' === $eventcrew_refused['by'] ? '&mdash;' : esc_html($eventcrew_refused['by']); ?></td>
                            <td><?php echo '' === $eventcrew_refused['reason'] ? '&mdash;' : esc_html($eventcrew_refused['reason']); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0">
                                    <input type="hidden" name="action" value="eventcrew_accept_newcomer">
                                    <?php wp_nonce_field($nonce_action); ?>
                                    <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $eventcrew_refused['id']); ?>">
                                    <button type="submit" class="button"><?php esc_html_e('Accept', 'eventcrew'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="ec-tab-panel" data-ec-tab="conditions">
        <p class="description">
            <?php esc_html_e('Who meets the conditions set on each role (Settings, Roles). Meeting them lets nobody sign up; it is a signal. Give the role by editing a person, in the Crew app, or with the bot’s /people. ✅ = already has the role.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
        </p>

        <?php if ([] === $condition_roles) : ?>
            <p><?php esc_html_e('No role has conditions set.', 'eventcrew'); ?></p>
        <?php endif; ?>

        <?php foreach ($condition_roles as $eventcrew_condition_role) : ?>
            <h2><?php echo esc_html($eventcrew_condition_role['label']); ?></h2>
            <p class="description"><?php echo esc_html($eventcrew_condition_role['conditions']); ?></p>
            <?php if ([] === $eventcrew_condition_role['people']) : ?>
                <p><?php esc_html_e('Nobody meets them yet.', 'eventcrew'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:40em">
                    <tbody>
                        <?php foreach ($eventcrew_condition_role['people'] as $eventcrew_condition_person) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(add_query_arg(['page' => $page_slug, 'person' => $eventcrew_condition_person['id']], admin_url('admin.php'))); ?>">
                                        <?php echo esc_html($eventcrew_condition_person['name']); ?>
                                    </a>
                                    <?php echo $eventcrew_condition_person['holds'] ? ' ✅' : ''; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endforeach; ?>
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
