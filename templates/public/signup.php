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
                <p class="eventcrew-muted eventcrew-hint"><?php esc_html_e('No password — we email you a one-time link that signs you in. It’s good for 30 minutes.', 'eventcrew'); ?></p>
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

        var data = new FormData(form);
        data.append('eventcrew_ajax', '1');

        fetch(ajaxUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            if (res && typeof res.board === 'string') {
                board.innerHTML = res.board;
            }
            if (res && res.notice) {
                showToast(res.notice);
            }
            // The sign-in link was just requested: reveal the "check your inbox"
            // note in place and turn the button into a clear Resend.
            if (isSignin) {
                var sent = document.getElementById('eventcrew-signin-sent');
                if (sent) { sent.hidden = false; }
                if (button) { button.textContent = <?php echo wp_json_encode(__('Resend link', 'eventcrew')); ?>; }
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
            // A Turnstile token is single-use; reset the widget so a second
            // sign-in attempt (or a rejected one) can be solved afresh.
            if (window.turnstile && form.querySelector('.cf-turnstile')) {
                try { window.turnstile.reset(); } catch (e) {}
            }
        }).catch(function () {
            if (button) {
                button.disabled = false;
            }
        });
    });
})();
</script>
