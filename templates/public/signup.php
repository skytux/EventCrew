<?php

/**
 * Public signup board, rendered by the [eventcrew_signup] shortcode / block.
 *
 * @var array<string, mixed> $view The SignupController view model:
 *   person, standing, csrf, groups, and the four ajax action names.
 */



declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var \EventCrew\Models\Person|null $eventcrew_person */
$eventcrew_person = $view['person'];
/** @var \EventCrew\Support\Standing|null $eventcrew_standing */
$eventcrew_standing = $view['standing'];
$eventcrew_csrf = (string) $view['csrf'];
$eventcrew_turnstile_site_key = (string) $view['turnstile_site_key'];
/** @var array<string, string> $eventcrew_ticket_dates date => label a credit can be spent on */
$eventcrew_ticket_dates = is_array($view['ticket_dates'] ?? null) ? $view['ticket_dates'] : [];
$eventcrew_redeem_action = (string) ($view['redeem_action'] ?? '');
/** @var array<string, array{label: string, dm: bool, email: bool}> $eventcrew_notify_matrix */
$eventcrew_notify_matrix = is_array($view['notify_matrix'] ?? null) ? $view['notify_matrix'] : [];
$eventcrew_prefs_action = (string) ($view['prefs_action'] ?? '');
/** @var array{upcoming: array<int, array{label: string, when: string, url: string}>, past: array<int, array{label: string, when: string, url: string}>} $eventcrew_my_tickets */
$eventcrew_my_tickets = is_array($view['my_tickets'] ?? null)
    ? $view['my_tickets']
    : ['upcoming' => [], 'past' => []];
/** @var array<int, array{task_id: int, label: string, when: string, calendar_url: string}> $eventcrew_my_upcoming */
$eventcrew_my_upcoming = is_array($view['my_upcoming'] ?? null) ? $view['my_upcoming'] : [];
/** @var array<int, array{when: string, label: string, status: string}> $eventcrew_my_history */
$eventcrew_my_history = is_array($view['my_history'] ?? null) ? $view['my_history'] : [];
$eventcrew_manage_endpoint = (string) ($view['manage_endpoint'] ?? '');
$eventcrew_manage_token = (string) ($view['manage_token'] ?? '');
$eventcrew_ajax = admin_url('admin-ajax.php');
// The URL to return to after each action: this very page. get_permalink() gives
// the clean canonical URL of the page the shortcode sits on - unlike
// add_query_arg([], null), which yields an empty URI (so home_url() fell back to
// the site root) and sent every action to the homepage.
$eventcrew_here = get_permalink();
if (false === $eventcrew_here) {
    $eventcrew_here = home_url('/');
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect-carried status.
$eventcrew_notice_code = isset($_GET['eventcrew_notice']) ? sanitize_key(wp_unslash($_GET['eventcrew_notice'])) : '';
$eventcrew_notice_text = \EventCrew\Web\SignupController::noticeText($eventcrew_notice_code);
?>
<?php
// The stylesheet is registered and enqueued by SignupController; see
// assets/eventcrew.css. It used to live here as an inline <style> block, which
// meant it was neither cacheable nor versioned - and, because the PWA service
// worker caches the page HTML, a restyle never reached an installed app.
?>
<div class="eventcrew-signup">
    <div id="eventcrew-toast" class="eventcrew-toast<?php echo '' !== $eventcrew_notice_text ? ' show' : ''; ?>" role="status" aria-live="polite"><?php echo esc_html($eventcrew_notice_text); ?></div>

    <?php if (null === $eventcrew_person) :
        // Signed out there are only two things - sign in, and look at the board
        // - so there is nothing to tab between and no tab strip is drawn.
        ?>
        <section class="eventcrew-section">
            <h2><?php esc_html_e('Sign in', 'eventcrew'); ?></h2>
            <form class="eventcrew-action eventcrew-signin" data-eventcrew-signin method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['login_action']); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                <?php
                /*
                 * The captcha lives inside the row, between the field and the
                 * button, and switches the row to a column. Left beside a
                 * side-by-side field and button it made an L of three things
                 * that do not share an edge; stacked, all three line up and the
                 * order reads the way the form is filled in.
                 */
                ?>
                <div class="eventcrew-signin-row<?php echo '' !== $eventcrew_turnstile_site_key ? ' has-captcha' : ''; ?>">
                    <label for="eventcrew-email" class="screen-reader-text"><?php esc_html_e('Email', 'eventcrew'); ?></label>
                    <input
                        type="email"
                        id="eventcrew-email"
                        name="email"
                        required
                        autocomplete="email"
                        placeholder="<?php esc_attr_e('hello@example.com', 'eventcrew'); ?>"
                        class="wp-element-input"
                    >
                    <?php if ('' !== $eventcrew_turnstile_site_key) : ?>
                        <div
                            class="cf-turnstile eventcrew-turnstile"
                            data-sitekey="<?php echo esc_attr($eventcrew_turnstile_site_key); ?>"
                            data-theme="auto"></div>
                    <?php endif; ?>
                    <button type="submit" class="wp-element-button"><?php esc_html_e('Email me a sign-in link', 'eventcrew'); ?></button>
                </div>
                <p class="eventcrew-muted eventcrew-hint"><?php esc_html_e('No password — we email you a link, good for 30 minutes.', 'eventcrew'); ?></p>
                <?php
                /*
                 * The privacy line sits with the field it is about, not in a
                 * footer: this is the moment someone hands over an address, and
                 * a notice they have to go looking for afterwards is not a
                 * notice. No tickbox - signing up is what makes the processing
                 * necessary, so there is no consent here to collect, and a box
                 * demanding consent for something we would do anyway would
                 * misdescribe the basis rather than strengthen it.
                 *
                 * The link is dropped entirely when there is no notice to point
                 * at, which is the unconfigured install: a dead link is worse
                 * than the sentence on its own.
                 */
                $eventcrew_privacy_url = \EventCrew\Support\PrivacyPolicy::noticeUrl();
                ?>
                <p class="eventcrew-muted eventcrew-hint">
                    <?php echo esc_html(\EventCrew\Support\PrivacyPolicy::noticeLine()); ?>
                    <?php if ('' !== $eventcrew_privacy_url) : ?>
                        <a href="<?php echo esc_url($eventcrew_privacy_url); ?>">
                            <?php echo esc_html(\EventCrew\Support\PrivacyPolicy::noticeLinkLabel()); ?>
                        </a>
                    <?php endif; ?>
                </p>
                <?php if ('' !== $eventcrew_turnstile_site_key) : ?>
                    <script src="<?php echo esc_url(\EventCrew\Support\Turnstile::SCRIPT_URL); ?>" async defer></script>
                <?php endif; ?>
            </form>
            <p id="eventcrew-signin-sent" class="eventcrew-muted eventcrew-hint" hidden><?php esc_html_e('Check your inbox for the sign-in link — and your spam folder if it’s not there. Press the button again to resend.', 'eventcrew'); ?></p>
        </section>

        <div id="eventcrew-board" class="eventcrew-board">
            <h2><?php esc_html_e('Board', 'eventcrew'); ?></h2>
            <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-board.php'; ?>
        </div>
        <?php
    else :
        ?>
        <?php
        // Sign out lives here, above the tabs, rather than inside Settings:
        // it is the one thing you might want from any tab, and hunting for it
        // is a poor last impression.
        ?>
        <div class="eventcrew-identity eventcrew-muted">
            <span>
                <?php
                printf(/* translators: %s: person's name */
                    esc_html__('Signed in as %s.', 'eventcrew'),
                    esc_html($eventcrew_person->name())
                ); ?>
            </span>
            <form class="eventcrew-signout" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['logout_action']); ?>">
                <?php
                /*
                 * Signing out is now checked like every other action, so this
                 * has to carry the token. It did not when logout sat above the
                 * gate, and the release that moved it forgot this form - which
                 * left the button answering "please sign in" to someone who was
                 * signed in and asking to stop being.
                 */
                ?>
                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                <button type="submit" class="eventcrew-linkbtn"><?php esc_html_e('Sign out', 'eventcrew'); ?></button>
            </form>
        </div>

        <?php
        /*
         * Standing and credits sit with the name, above the tabs, rather than
         * inside one of them: "how am I doing" and "what can I spend" are the
         * context you want while reading any tab, not a thing to go and look
         * up. The score explainer follows it, collapsed.
         */
        ?>
        <?php if (null !== $eventcrew_standing) : ?>
            <p class="eventcrew-standing">
                <span><?php echo esc_html($eventcrew_standing->ratedSummary()); ?></span>
                &middot;
                <span><?php echo esc_html(sprintf(/* translators: %d: number of free-entry credits */
                    _n('%d credit', '%d credits', $eventcrew_standing->creditBalance, 'eventcrew'),
                    $eventcrew_standing->creditBalance
                )); ?></span>
            </p>
            <details class="eventcrew-disclosure eventcrew-score-help">
                <summary><?php esc_html_e('How your score works', 'eventcrew'); ?></summary>
                <table>
                    <tbody>
                    <?php foreach (\EventCrew\Support\StandingExplainer::rows() as $eventcrew_label => $eventcrew_percent) : ?>
                        <tr>
                            <td><?php echo esc_html($eventcrew_label); ?></td>
                            <td class="eventcrew-num"><?php echo esc_html($eventcrew_percent . '%'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="eventcrew-muted"><?php esc_html_e('Recent tasks count for more than old ones. You’re rated once you’ve completed a few tasks, and you earn one free-entry credit for every two completed tasks.', 'eventcrew'); ?></p>
            </details>
        <?php endif; ?>

        <?php
        /*
         * Real anchors, not buttons: with JavaScript off every panel stays
         * visible and these degrade to jump links down one long page, which is
         * the same posture the rest of this page takes. The script upgrades
         * them to a tablist in place.
         */
        ?>
        <nav class="eventcrew-tabs" aria-label="<?php esc_attr_e('Your crew page', 'eventcrew'); ?>">
            <?php
            $eventcrew_tabs = [
                'board' => __('Board', 'eventcrew'),
                'me' => __('Me', 'eventcrew'),
                'settings' => __('Settings', 'eventcrew'),
            ];
            foreach ($eventcrew_tabs as $eventcrew_key => $eventcrew_label) :
                ?>
                <a
                    class="eventcrew-tab"
                    id="eventcrew-tab-<?php echo esc_attr($eventcrew_key); ?>"
                    href="#eventcrew-panel-<?php echo esc_attr($eventcrew_key); ?>"
                    data-eventcrew-tab="<?php echo esc_attr($eventcrew_key); ?>"
                ><?php echo esc_html($eventcrew_label); ?></a>
            <?php endforeach; ?>
        </nav>

        <section class="eventcrew-panel" id="eventcrew-panel-board" data-eventcrew-panel="board" aria-labelledby="eventcrew-tab-board">
            <h2 class="eventcrew-panel-title"><?php esc_html_e('Board', 'eventcrew'); ?></h2>
            <div id="eventcrew-board" class="eventcrew-board">
                <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-board.php'; ?>
            </div>

            <?php if ('' !== (string) $view['telegram_group_link']) : ?>
                <p class="eventcrew-tgline">
                    <a class="eventcrew-tglink wp-element-button is-style-outline" href="<?php echo esc_url((string) $view['telegram_group_link']); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e('Open our group in Telegram', 'eventcrew'); ?> →
                    </a>
                </p>
            <?php endif; ?>
        </section>

        <section class="eventcrew-panel" id="eventcrew-panel-me" data-eventcrew-panel="me" aria-labelledby="eventcrew-tab-me">
            <h2 class="eventcrew-panel-title"><?php esc_html_e('Me', 'eventcrew'); ?></h2>

            <?php
            /*
             * Two named sections rather than a loose stack, each always drawn
             * even when it has nothing in it: a heading that is missing is
             * indistinguishable from a feature that is missing, and "you are
             * not on anything yet" is itself worth knowing.
             */
            ?>
            <div class="eventcrew-me-section">
                <h3 class="eventcrew-subhead"><?php esc_html_e('My tasks', 'eventcrew'); ?></h3>
                <?php if ([] === $eventcrew_my_upcoming) : ?>
                    <p class="eventcrew-muted"><?php esc_html_e('You’re not signed up for anything yet — take a look at the board.', 'eventcrew'); ?></p>
                <?php else : ?>
                    <ul class="eventcrew-plain-list">
                        <?php foreach ($eventcrew_my_upcoming as $eventcrew_mine) : ?>
                            <li>
                                <span class="eventcrew-mine-label"><?php echo esc_html($eventcrew_mine['label']); ?></span>
                                <span class="eventcrew-mine-when">
                                    <?php echo esc_html($eventcrew_mine['when']); ?>
                                    · <a href="<?php echo esc_url($eventcrew_mine['calendar_url']); ?>"><?php esc_html_e('Add to calendar', 'eventcrew'); ?></a>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php if ([] !== $eventcrew_my_history) : ?>
                <div class="eventcrew-me-section eventcrew-divider">
                    <h3 class="eventcrew-subhead"><?php esc_html_e('What you’ve done', 'eventcrew'); ?></h3>
                    <ul class="eventcrew-plain-list">
                        <?php foreach ($eventcrew_my_history as $eventcrew_past) : ?>
                            <li>
                                <span class="eventcrew-mine-label"><?php echo esc_html($eventcrew_past['label']); ?></span>
                                <span class="eventcrew-mine-when">
                                    <?php echo esc_html($eventcrew_past['when']); ?>
                                    · <?php echo esc_html($eventcrew_past['status']); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="eventcrew-me-section eventcrew-divider">
                <h3 class="eventcrew-subhead"><?php esc_html_e('Tickets', 'eventcrew'); ?></h3>

                <?php
                // Spending a credit lives here rather than beside the credit
                // count: what it produces is a ticket, and this is where the
                // tickets are.
                ?>
                <?php if ('' !== $eventcrew_redeem_action && [] !== $eventcrew_ticket_dates) : ?>
                    <form class="eventcrew-action eventcrew-redeem" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_redeem_action); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                        <label for="eventcrew-ticket-date"><?php esc_html_e('Spend a free-entry credit on', 'eventcrew'); ?></label>
                        <select name="ticket_date" id="eventcrew-ticket-date">
                            <?php foreach ($eventcrew_ticket_dates as $eventcrew_date => $eventcrew_date_label) : ?>
                                <option value="<?php echo esc_attr((string) $eventcrew_date); ?>"><?php echo esc_html($eventcrew_date_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="wp-element-button"><?php esc_html_e('Get my ticket', 'eventcrew'); ?></button>
                    </form>
                <?php endif; ?>

                <?php if ([] === $eventcrew_my_tickets['upcoming'] && [] === $eventcrew_my_tickets['past']) : ?>
                    <p class="eventcrew-muted"><?php esc_html_e('No tickets yet. Sign up for a task, or spend a free-entry credit.', 'eventcrew'); ?></p>
                <?php else : ?>
                    <?php foreach (['upcoming' => __('Upcoming', 'eventcrew'), 'past' => __('Past', 'eventcrew')] as $eventcrew_group => $eventcrew_group_label) : ?>
                        <?php if ([] !== $eventcrew_my_tickets[$eventcrew_group]) : ?>
                            <p class="eventcrew-muted eventcrew-listhead"><strong><?php echo esc_html($eventcrew_group_label); ?></strong></p>
                            <ul class="eventcrew-ticket-list">
                                <?php foreach ($eventcrew_my_tickets[$eventcrew_group] as $eventcrew_ticket) : ?>
                                    <li>
                                        <a href="<?php echo esc_url($eventcrew_ticket['url']); ?>" target="_blank" rel="noopener">
                                            <?php echo esc_html($eventcrew_ticket['label']); ?>
                                        </a>
                                        — <?php echo esc_html($eventcrew_ticket['when']); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="eventcrew-panel" id="eventcrew-panel-settings" data-eventcrew-panel="settings" aria-labelledby="eventcrew-tab-settings">
            <h2 class="eventcrew-panel-title"><?php esc_html_e('Settings', 'eventcrew'); ?></h2>

            <?php if ('' !== $eventcrew_prefs_action && [] !== $eventcrew_notify_matrix) : ?>
                <h3 class="eventcrew-subhead"><?php esc_html_e('Notifications', 'eventcrew'); ?></h3>
                <form class="eventcrew-prefs" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_prefs_action); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                    <table class="eventcrew-matrix">
                        <thead>
                            <tr>
                                <th></th>
                                <th><?php esc_html_e('Telegram', 'eventcrew'); ?></th>
                                <th><?php esc_html_e('Email', 'eventcrew'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($eventcrew_notify_matrix as $eventcrew_type => $eventcrew_pref) : ?>
                            <tr>
                                <td><?php echo esc_html($eventcrew_pref['label']); ?></td>
                                <td class="eventcrew-tick">
                                    <input type="checkbox" name="prefs[<?php echo esc_attr((string) $eventcrew_type); ?>][dm]" value="1" <?php checked($eventcrew_pref['dm']); ?>>
                                </td>
                                <td class="eventcrew-tick">
                                    <input type="checkbox" name="prefs[<?php echo esc_attr((string) $eventcrew_type); ?>][email]" value="1" <?php checked($eventcrew_pref['email']); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="eventcrew-muted"><?php esc_html_e('Signup confirmations and task reminders are always sent on both channels.', 'eventcrew'); ?></p>
                    <button type="submit" class="wp-element-button"><?php esc_html_e('Save preferences', 'eventcrew'); ?></button>
                </form>
            <?php endif; ?>

            <?php
            $eventcrew_revoke_action = (string) ($view['revoke_action'] ?? '');

            if ('' !== $eventcrew_revoke_action) :
                ?>
                <h3 class="eventcrew-subhead"><?php esc_html_e('Security', 'eventcrew'); ?></h3>
                <p class="eventcrew-muted">
                    <?php esc_html_e('Signing in leaves this browser signed in for 30 days. If you have used a shared or borrowed device — or you think somebody else has your sign-in link — this signs out every browser and phone at once, including this one. Nothing is lost; you just sign in again.', 'eventcrew'); ?>
                </p>
                <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                    onsubmit="return confirm('<?php echo esc_js(__('Sign out everywhere, including here?', 'eventcrew')); ?>');">
                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_revoke_action); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                    <button type="submit" class="eventcrew-linkbtn"><?php esc_html_e('Sign out everywhere', 'eventcrew'); ?></button>
                </form>
            <?php endif; ?>

            <?php if ('' !== $eventcrew_manage_token) : ?>
                <h3 class="eventcrew-subhead"><?php esc_html_e('Account', 'eventcrew'); ?></h3>
                <p class="eventcrew-muted"><?php esc_html_e('To stop individual emails, use Notifications above. To leave for good, delete your data — this erases your account and history and cannot be undone.', 'eventcrew'); ?></p>
                <form class="eventcrew-danger" method="post" action="<?php echo esc_url($eventcrew_manage_endpoint); ?>"
                    onsubmit="return confirm('<?php echo esc_js(__('Delete your account and all your history? This cannot be undone.', 'eventcrew')); ?>');">
                    <input type="hidden" name="token" value="<?php echo esc_attr($eventcrew_manage_token); ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="eventcrew-linkbtn eventcrew-linkbtn-danger"><?php esc_html_e('Delete my data', 'eventcrew'); ?></button>
                </form>
            <?php endif; ?>

        </section>
        <?php
    endif; ?>
</div>
<script>
/*
 * Progressive enhancement: turn the three sections into tabs.
 *
 * The markup ships as a jump-link nav over three visible panels, so with
 * JavaScript off the page is one readable scroll and every link still goes
 * somewhere. Only when this runs does it become a tablist - which is why the
 * hiding lives behind the .is-tabbed class this adds, and not in the
 * stylesheet's default state.
 *
 * Unlike the admin's tabs, the last-used tab is deliberately NOT remembered:
 * the board is what this page is for, and it must be what you land on.
 */
(function () {
    var root = document.querySelector('.eventcrew-signup');
    var nav = root && root.querySelector('.eventcrew-tabs');

    if (!nav) { return; }  // signed out: nothing to tab between

    var tabs = [].slice.call(nav.querySelectorAll('.eventcrew-tab'));
    var panels = [].slice.call(root.querySelectorAll('[data-eventcrew-panel]'));

    if (tabs.length === 0 || panels.length === 0) { return; }

    nav.setAttribute('role', 'tablist');
    panels.forEach(function (panel) {
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('tabindex', '0');
    });

    function panelFor(key) {
        return panels.filter(function (p) { return p.getAttribute('data-eventcrew-panel') === key; })[0];
    }

    function show(key, focusTab) {
        tabs.forEach(function (tab) {
            var isActive = tab.getAttribute('data-eventcrew-tab') === key;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.setAttribute('aria-controls', 'eventcrew-panel-' + tab.getAttribute('data-eventcrew-tab'));
            // Roving tabindex: one stop for the whole strip, arrows move within.
            tab.setAttribute('tabindex', isActive ? '0' : '-1');
            tab.classList.toggle('is-active', isActive);
            if (isActive && focusTab) { tab.focus(); }
        });

        panels.forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-eventcrew-panel') === key);
        });

        // replaceState, not the hash directly: setting location.hash would jump
        // the page to the panel, undoing the point of a tab. Deep links still
        // work, because the hash is read on load below.
        if (window.history && window.history.replaceState) {
            try { window.history.replaceState(null, '', '#eventcrew-panel-' + key); } catch (e) {}
        }
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            show(tab.getAttribute('data-eventcrew-tab'), false);
        });

        tab.addEventListener('keydown', function (e) {
            var next = null;
            if (e.key === 'ArrowRight') { next = tabs[(index + 1) % tabs.length]; }
            if (e.key === 'ArrowLeft') { next = tabs[(index - 1 + tabs.length) % tabs.length]; }
            if (e.key === 'Home') { next = tabs[0]; }
            if (e.key === 'End') { next = tabs[tabs.length - 1]; }
            if (!next) { return; }
            e.preventDefault();
            show(next.getAttribute('data-eventcrew-tab'), true);
        });
    });

    /*
     * Which tab opens. The board wins unless something specific says otherwise:
     * a deep link, or a notice from an action that lives on another tab - the
     * preferences and delete forms are full-page posts, so without that second
     * rule saving your preferences would dump you back on the board with no
     * sign it had worked.
     */
    function initialKey() {
        var fromHash = (window.location.hash || '').replace('#eventcrew-panel-', '');
        if (fromHash && panelFor(fromHash)) { return fromHash; }

        var notice = (window.location.search.match(/[?&]eventcrew_notice=([a-z_]+)/) || [])[1] || '';
        if (notice === 'prefs_saved' && panelFor('settings')) { return 'settings'; }
        if (notice.indexOf('ticket_') === 0 && panelFor('me')) { return 'me'; }

        return 'board';
    }

    root.classList.add('is-tabbed');
    show(initialKey(), false);
})();

/*
 * Progressive enhancement: send Sign up / Cancel through admin-ajax and swap the
 * board's HTML for the fresh copy the server returns, so the button flips state
 * and the counts update without a page reload or losing scroll position.
 * Delegated from the container, so the replaced buttons keep working.
 */
(function () {
    var root = document.querySelector('.eventcrew-signup');
    var board = document.getElementById('eventcrew-board');
    var toast = document.getElementById('eventcrew-toast');

    // Pin the toast to the viewport, not the widget: a transformed ancestor
    // (which many themes have) would otherwise capture position:fixed and leave
    // it stuck inside the content column.
    if (toast) {
        document.body.appendChild(toast);
    }

    var toastTimer = null;
    function showToast(text) {
        if (!toast) { return; }
        if (!text) { toast.classList.remove('show'); return; }
        toast.textContent = text;
        toast.classList.add('show');
        if (toastTimer) { clearTimeout(toastTimer); }
        toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 4000);
    }

    // A server-rendered toast (from a full-page redirect) auto-hides too.
    if (toast && toast.classList.contains('show')) {
        toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 4000);
    }

    /*
     * Take the notice out of the address bar once it has been shown.
     *
     * The redirect after an action carries its outcome in ?eventcrew_notice,
     * which the page turns into a toast - but the parameter stays in the URL,
     * so a refresh, a bookmark or a shared link says "You're signed out" again
     * to somebody who is not, days later. Nothing is re-run by that: the toast
     * is display only, and the action itself happened before the redirect. It
     * is a message outliving the thing it described.
     *
     * replaceState rewrites the address without a reload and without adding a
     * history entry, so the back button behaves and the refresh that follows
     * asks for a clean URL. Wrapped because a URL the browser will not parse is
     * not worth an exception over a cosmetic tidy-up.
     */
    if (window.history && window.history.replaceState && -1 !== window.location.search.indexOf('eventcrew_notice=')) {
        try {
            var tidy = new URL(window.location.href);
            tidy.searchParams.delete('eventcrew_notice');
            window.history.replaceState({}, '', tidy.toString());
        } catch (e) {}
    }

    // Remember the sign-in email so a return visit (or a resend) doesn't have to
    // retype it - the one bit of friction in a passwordless, leave-and-come-back
    // sign-in. Runs regardless of the fetch path below.
    var EMAIL_KEY = 'eventcrew_email';
    var emailField = document.getElementById('eventcrew-email');
    if (emailField && !emailField.value) {
        try { emailField.value = localStorage.getItem(EMAIL_KEY) || ''; } catch (e) {}
    }

    if (!root || !board || !window.fetch) {
        return;
    }

    var ajaxUrl = <?php echo wp_json_encode($eventcrew_ajax); ?>;

    /*
     * The sign-in button waits for the spam check.
     *
     * Turnstile renders and solves asynchronously and writes its token into a
     * hidden field. Pressing before that lands submits an empty token, which is
     * refused outright - so the press did nothing, and only a second one, by
     * which point the widget had finished, actually sent the link. Rather than
     * let someone spend a press finding that out, the button says what it is
     * waiting for and only becomes live once there is a token to send.
     *
     * The safety valve is the timeout below. Ad blockers and privacy extensions
     * do block the challenge script outright, and a button that stays disabled
     * for ever is a form nobody can sign in through at all. After GIVE_UP the
     * button goes live regardless; the press then reaches the server, which
     * refuses it and says why. A wasted press beats a dead page.
     *
     * None of this runs without the widget, and none of it runs with JavaScript
     * off - the button ships enabled and the form posts normally.
     */
    var CHALLENGE_STEP = 200;
    var CHALLENGE_GIVE_UP = 15000;
    /*
     * How long to wait for a token once somebody has pressed.
     *
     * Ten seconds, not the four this started at: a challenge solving from cold
     * after a reset is slower than the one that solved during page load, and
     * four seconds was short enough that pressing Resend gave up before the new
     * token arrived - so the widget visibly reloaded, nothing was sent, and it
     * took a second press. Still bounded, and the button says what it is doing
     * throughout, so the wait is visible rather than dead.
     */
    var REFRESH_WAIT = 10000;

    /*
     * How long after a send before the widget is quietly re-armed.
     *
     * A spent token has to be replaced before anything can be sent again, and
     * doing it here - while the "check your inbox" message is being read -
     * means Resend usually has a fresh one waiting and sends on the first
     * press. Doing it at the instant of success looked like the form reloading
     * (v1.23.1), and not doing it at all made Resend take two presses. A beat
     * later is neither.
     */
    var REARM_AFTER = 1200;
    var unsolvedNotice = <?php echo wp_json_encode(
        __('The spam check hasn’t finished yet — give it a moment, then try again.', 'eventcrew')
    ); ?>;

    var signinForm = root.querySelector('form[data-eventcrew-signin]');
    var signinButton = signinForm ? signinForm.querySelector('button') : null;
    // Whatever the button should say when it is live. Becomes "Resend link"
    // after a link has gone, so it is read back rather than assumed.
    var signinLiveLabel = signinButton ? signinButton.textContent : '';
    var waitingLabel = <?php echo wp_json_encode(__('Checking you’re human…', 'eventcrew')); ?>;
    var submitting = false;

    /*
     * The best token available from either place Cloudflare keeps it.
     *
     * There are two, and they are not filled at the same moment: the hidden
     * cf-turnstile-response field lags turnstile.getResponse() by a few
     * seconds. Whichever is set, they hold the same token, so reading the field
     * first and the API second returns a usable one as early as possible.
     *
     * This is only safe because send() now writes the result into the request
     * explicitly. Gating on this while letting FormData take the field on its
     * own is what let the button go live on a token the form did not send.
     */
    function challengeToken(form) {
        var scope = form || signinForm;
        var field = scope ? scope.querySelector('[name="cf-turnstile-response"]') : null;

        if (field && field.value) {
            return field.value;
        }

        try {
            return (window.turnstile && window.turnstile.getResponse()) || '';
        } catch (err) {
            return '';
        }
    }

    function hasChallenge(form) {
        return !!(form && form.querySelector('.cf-turnstile'));
    }

    function setSigninLive(live) {
        if (!signinButton || submitting) {
            return;
        }

        signinButton.disabled = !live;
        signinButton.textContent = live ? signinLiveLabel : waitingLabel;
    }

    /*
     * A Turnstile token goes stale on its own, and the widget does not let on.
     *
     * It solves as soon as the page loads and the tick stays on screen, but the
     * token behind it is only good for about five minutes and is single-use.
     * Anyone who opens the page, reads it, types an address and then presses -
     * or who leaves the tab sitting - sends a token Cloudflare answers with
     * "timeout-or-duplicate", so the widget says success and the server says
     * failure. Pressing again works, because the reset after a submit fetches a
     * fresh one. That is the whole of "it fails the first time".
     *
     * So the age is tracked here and anything past STALE_AFTER is replaced
     * before it is sent. Well under Cloudflare's own expiry, because the gap
     * that matters is between the press and the check reaching them.
     */
    var STALE_AFTER = 100000;
    var tokenSeenAt = 0;
    var lastToken = '';
    var tokenSpent = false;

    function noteToken(token) {
        if (token !== lastToken) {
            lastToken = token;
            tokenSeenAt = token ? Date.now() : 0;
            // A token we have not sent yet.
            tokenSpent = false;
        }
    }

    /*
     * Only a token we actually have can be stale. With none at all there is
     * nothing to replace, and resetting to wait for one would spend the
     * timeout achieving nothing - better to send and let the server say so,
     * which it now does out loud.
     */
    function tokenIsStale() {
        if (!lastToken) {
            return false;
        }

        // Spent counts as stale: a token is good for one send, so the one
        // already used is no more use than an expired one.
        return tokenSpent || (Date.now() - tokenSeenAt) > STALE_AFTER;
    }

    /*
     * Replaces the current token and hands the new one to `done`, or '' if none
     * arrived in time.
     *
     * REFRESH_WAIT, not CHALLENGE_GIVE_UP: the fifteen seconds are the safety
     * valve for the button on page load, where waiting costs nothing because
     * nobody is watching yet. Here somebody has just pressed and is watching,
     * and a challenge that has not answered in a few seconds is not going to.
     * Waiting the full fifteen twice - once before sending, once on the retry -
     * is what turned a refusal into half a minute of nothing followed by a
     * toast.
     *
     * Resetting only when there is something to replace: a widget that has not
     * produced a token yet may be mid-solve or waiting to be clicked, and
     * resetting it throws away whatever progress it had.
     */
    function refreshChallenge(form, done) {
        if (!hasChallenge(form) || !window.turnstile) {
            done('');

            return;
        }

        if (lastToken) {
            try { window.turnstile.reset(); } catch (err) {}
            noteToken('');
        }

        var waited = 0;

        (function poll() {
            var token = challengeToken(form);

            if (token) {
                noteToken(token);
                done(token);

                return;
            }

            if (waited >= REFRESH_WAIT) {
                done('');

                return;
            }

            waited += CHALLENGE_STEP;
            setTimeout(poll, CHALLENGE_STEP);
        })();
    }

    if (hasChallenge(signinForm) && signinButton) {
        setSigninLive(false);

        var starved = 0;

        setInterval(function () {
            if (submitting) {
                return;
            }

            var token = challengeToken(signinForm);
            noteToken(token);

            if (token) {
                starved = 0;
                setSigninLive(true);

                return;
            }

            starved += CHALLENGE_STEP;

            // Past the timeout the button stays live and empty-handed rather
            // than dead; see the note above.
            setSigninLive(starved >= CHALLENGE_GIVE_UP);
        }, CHALLENGE_STEP);
    }

    root.addEventListener('submit', function (e) {
        var form = e.target.closest('form.eventcrew-action');

        if (!form) {
            return;
        }

        e.preventDefault();

        var button = form.querySelector('button');
        if (button) {
            button.disabled = true;
        }

        var isSignin = form.hasAttribute('data-eventcrew-signin');
        if (isSignin && emailField) {
            try { localStorage.setItem(EMAIL_KEY, emailField.value || ''); } catch (e) {}
        }

        // Holds the watcher above off the button until the request is done, so
        // it cannot re-enable it or rewrite its label mid-flight.
        submitting = true;

        /*
         * A token that is spent or has sat on screen a while is replaced before
         * it goes, rather than after Cloudflare has rejected it. And if there
         * is no token at all - the widget has not solved, or is waiting to be
         * clicked - the request is not sent.
         *
         * Sending it anyway was the old behaviour and it cannot succeed: the
         * server refuses an empty token without even asking Cloudflare, so the
         * round trip buys nothing but delay and a line in the log. Saying so
         * here is immediate and tells somebody what to do about it, which
         * "couldn't verify you're human" after half a minute did not.
         */
        if (hasChallenge(form) && (tokenIsStale() || !lastToken)) {
            showWaitingOnButton();

            refreshChallenge(form, function (token) {
                if (!token) {
                    giveUp(button, unsolvedNotice);

                    return;
                }

                send(form, button, isSignin, false);
            });

            return;
        }

        send(form, button, isSignin, false);
    });

    /** Labels the sign-in button as waiting, past the submitting guard. */
    function showWaitingOnButton() {
        if (signinButton) {
            signinButton.textContent = waitingLabel;
        }
    }

    /** Abandons an attempt without sending it, saying why. */
    function giveUp(button, message) {
        submitting = false;
        showToast(message);

        if (button) {
            button.disabled = false;
            button.textContent = signinLiveLabel;
        }
    }

    function send(form, button, isSignin, isRetry) {
        var data = new FormData(form);
        data.append('eventcrew_ajax', '1');

        /*
         * Carry the token explicitly, because the two places it lives are not
         * filled at the same moment.
         *
         * Cloudflare hands turnstile.getResponse() the token as soon as the
         * challenge is solved, and writes it into the hidden
         * cf-turnstile-response field a few seconds later. Measured on a real
         * page: getResponse() 645 characters while the field was still empty,
         * then both 645 a moment after. FormData only sees the field - so a
         * press inside that gap submitted nothing, the server refused it for
         * having no token, and the tick was on screen the whole time saying
         * otherwise.
         *
         * It also explains the one clue that made no sense: focusing the email
         * box first "fixed" it, because typing spends exactly the few seconds
         * the field needed to catch up.
         *
         * Setting it here means the request carries whichever copy exists, so
         * what the button was enabled on and what is sent are finally the same
         * value - which is what v1.23.0 set out to do and got backwards, by
         * trusting the field that lags over the API that leads.
         */
        var token = challengeToken(form);

        if (token) {
            data.set('cf-turnstile-response', token);
        }

        fetch(ajaxUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            /*
             * A refused challenge is worth one silent second attempt with a
             * fresh token before it becomes the visitor's problem. The common
             * causes - a token that expired while the page sat open, or one
             * already spent - are both cured by resetting and asking again, and
             * telling somebody "couldn't verify you're human" for either is
             * blaming them for a clock.
             *
             * Once only, and only for the captcha: a second refusal is a real
             * one and gets shown.
             */
            if (!isRetry && res && 'captcha_failed' === res.code && hasChallenge(form)) {
                showWaitingOnButton();

                refreshChallenge(form, function (token) {
                    // No fresh token means the second attempt would be refused
                    // for exactly the reason the first was. Say so instead.
                    if (!token) {
                        giveUp(button, unsolvedNotice);

                        return;
                    }

                    send(form, button, isSignin, true);
                });

                return;
            }

            if (res && typeof res.board === 'string') {
                /*
             * Swapping the board's HTML destroys the button that was just
             * pressed, and with it the focus - dumping a keyboard or screen
             * reader user back at the top of the document with no idea what
             * happened. Put focus on whatever now stands in that row's place,
             * so the next tab press carries on from where they were.
             */
            var focusedTask = form.querySelector('input[name="task_id"]');
            focusedTask = focusedTask ? focusedTask.value : '';

            board.innerHTML = res.board;

            if (focusedTask) {
                var row = board.querySelector('[data-eventcrew-task="' + focusedTask + '"]');
                var target = row && row.querySelector('button, a');
                if (target) { target.focus(); }
            }
            }
            if (res && res.notice) {
                showToast(res.notice);
            }
            /*
             * The sign-in link was actually sent: reveal the "check your inbox"
             * note in place and turn the button into a clear Resend.
             *
             * Gated on the outcome, not on "this was the sign-in form". It used
             * to fire on any response, so a refused captcha or a mistyped
             * address told people to go and check an inbox nothing had been
             * sent to - which is worse than saying nothing, because they then
             * wait instead of pressing the button again.
             */
            if (isSignin && res && 'check_email' === res.code) {
                var sent = document.getElementById('eventcrew-signin-sent');
                if (sent) { sent.hidden = false; }
                // Remembered as the live label too, so the watcher restores
                // "Resend link" rather than the original wording once the
                // freshly reset widget has solved again.
                signinLiveLabel = <?php echo wp_json_encode(__('Resend link', 'eventcrew')); ?>;
                if (button) { button.textContent = signinLiveLabel; }
            }
            // A redeemed free-entry ticket: open it. Prefer a new tab; if the
            // browser blocks the popup, navigate this one to the ticket instead.
            if (res && res.ticket_url) {
                var opened = window.open(res.ticket_url, '_blank');
                if (!opened) {
                    window.location.assign(res.ticket_url);
                }
            }
            if (button) {
                button.disabled = false;
            }

            /*
             * The token is spent, but the widget is left alone.
             *
             * Resetting here re-ran the challenge the instant the link was
             * sent: the tick vanished and the box visibly worked itself out
             * again, directly under a message saying to go and check an inbox.
             * It reads as the form reloading, and it is asking a question
             * nobody has been posed - there is nothing to submit until they
             * press Resend.
             *
             * Marking it spent instead defers that to the next press, where
             * the refresh happens for a reason and the wait belongs. The
             * button stays live and reading "Resend link" in the meantime,
             * because the field it is gated on still holds a value.
             */
            tokenSpent = true;

            // Whatever the label was during the wait, put the live one back.
            if (button && signinButton === button) {
                button.textContent = signinLiveLabel;
            }

            submitting = false;

            // Re-arm in the background so a Resend has a token ready. The
            // watcher picks the new one up and clears the spent flag with it.
            if (hasChallenge(form) && window.turnstile) {
                setTimeout(function () {
                    if (submitting) {
                        return;
                    }

                    try { window.turnstile.reset(); } catch (err) {}
                }, REARM_AFTER);
            }
        }).catch(function () {
            if (button) {
                button.disabled = false;
            }

            submitting = false;
        });
    }
})();
</script>
