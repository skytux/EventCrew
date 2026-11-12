<?php

/**
 * The "Get the app" pop-in of the Crew app page (Owner 2026-11-12), for a
 * signed-in person only: a modal card with the app icon, Install the app
 * and Remind me later, and a close ×. AppInstallPrompt says whether it is
 * due; the script below shows it only in a browser tab that can install
 * the app (an iPhone or iPad, or a browser that offered to install it).
 *
 * Three steps in one card, one shown at a time:
 * - offer: the pitch and the two buttons;
 * - ios: on an iPhone or iPad, how to add the app to the Home Screen;
 * - notify: once the app is installed (here, or on its first opening from
 *   the Home Screen, once), Turn on notifications. It presses the
 *   Notifications card's own "Turn on notifications on this device" link
 *   (signup-push.php), then opens that card so the person can choose the
 *   notices.
 *
 * The page is only shown signed in, which is never cached (the CSRF token),
 * so the due-or-not answer is this person's, now.
 *
 * Expects $eventcrew_app_install (SignupController::appInstallView() or
 * null), $eventcrew_ajax and $eventcrew_csrf from signup.php.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var array{popin: bool, installed: bool, offer_notify: bool, action: string, name: string, icon: string, color: string}|null $eventcrew_app_install */
/** @var string $eventcrew_ajax */
/** @var string $eventcrew_csrf */

if (null === $eventcrew_app_install) {
    return;
}
?>
<div class="eventcrew-app" data-eventcrew-app
    data-ajax="<?php echo esc_url($eventcrew_ajax); ?>"
    data-action="<?php echo esc_attr($eventcrew_app_install['action']); ?>"
    data-csrf="<?php echo esc_attr($eventcrew_csrf); ?>"
    data-popin="<?php echo $eventcrew_app_install['popin'] ? '1' : '0'; ?>"
    data-installed="<?php echo $eventcrew_app_install['installed'] ? '1' : '0'; ?>"
    data-offer-notify="<?php echo $eventcrew_app_install['offer_notify'] ? '1' : '0'; ?>">
<dialog class="eventcrew-app-dialog" aria-labelledby="eventcrew-app-title-offer"
    style="--eventcrew-app-color: <?php echo esc_attr($eventcrew_app_install['color']); ?>">
    <div class="eventcrew-app-card">
        <button type="button" class="eventcrew-app-close" data-eventcrew-app-close
            aria-label="<?php esc_attr_e('Close', 'eventcrew'); ?>"><span aria-hidden="true">×</span></button>
        <div class="eventcrew-app-hero">
            <img src="<?php echo esc_url($eventcrew_app_install['icon']); ?>" width="72" height="72" alt="" loading="lazy">
        </div>
        <div class="eventcrew-app-step" data-eventcrew-app-step="offer">
            <h2 class="eventcrew-app-title" id="eventcrew-app-title-offer">
                <?php
                /* translators: %s: the app's short name, like "EDH Crew". */
                echo esc_html(sprintf(__('Get the %s app', 'eventcrew'), $eventcrew_app_install['name']));
                ?>
            </h2>
            <p><?php esc_html_e('Useful notifications you choose. No need for Telegram.', 'eventcrew'); ?></p>
            <div class="eventcrew-app-actions">
                <button type="button" class="wp-element-button eventcrew-linkbtn" data-eventcrew-app-install><?php esc_html_e('Install the app', 'eventcrew'); ?></button>
                <button type="button" class="eventcrew-settings-btn" data-eventcrew-app-later><?php esc_html_e('Remind me later', 'eventcrew'); ?></button>
            </div>
        </div>
        <div class="eventcrew-app-step" data-eventcrew-app-step="ios" hidden>
            <h2 class="eventcrew-app-title" id="eventcrew-app-title-ios"><?php esc_html_e('Add it to your Home Screen', 'eventcrew'); ?></h2>
            <ol class="eventcrew-app-how">
                <li>
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3v12M7.5 7.5 12 3l4.5 4.5M8 10H6a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1h-2"/></svg>
                    <span><?php esc_html_e('Tap Share in the browser bar.', 'eventcrew'); ?></span>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M12 8.5v7M8.5 12h7"/></svg>
                    <span><?php esc_html_e('Choose Add to Home Screen.', 'eventcrew'); ?></span>
                </li>
                <li>
                    <img src="<?php echo esc_url($eventcrew_app_install['icon']); ?>" width="24" height="24" alt="" loading="lazy">
                    <span>
                        <?php
                        /* translators: %s: the app's short name, like "EDH Crew". */
                        echo esc_html(sprintf(__('Open %s from your Home Screen.', 'eventcrew'), $eventcrew_app_install['name']));
                        ?>
                    </span>
                </li>
            </ol>
        </div>
        <div class="eventcrew-app-step" data-eventcrew-app-step="notify" hidden>
            <h2 class="eventcrew-app-title" id="eventcrew-app-title-notify"><?php esc_html_e('Turn on notifications', 'eventcrew'); ?></h2>
            <p><?php esc_html_e('Then choose which notices reach you in the app.', 'eventcrew'); ?></p>
            <div class="eventcrew-app-actions">
                <button type="button" class="wp-element-button eventcrew-linkbtn" data-eventcrew-app-notify
                    data-eventcrew-goto="eventcrew-notifications"><?php esc_html_e('Turn on notifications', 'eventcrew'); ?></button>
            </div>
        </div>
    </div>
</dialog>
</div>
<script>
(function () {
    var box = document.querySelector('[data-eventcrew-app]');
    var dialog = box && box.querySelector('dialog');
    if (!dialog || 'function' !== typeof dialog.showModal) { return; }
    var part = function (name) { return box.querySelector('[data-eventcrew-app-' + name + ']'); };
    var ua = navigator.userAgent || '';
    // iPadOS Safari says it is a Mac; a Mac has no touch screen.
    var ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
        || true === window.navigator.standalone;
    var offer = null, returnTo = null, step = 'offer', closing = '';

    var post = function (op) {
        var data = new FormData();
        data.append('action', box.dataset.action);
        data.append('csrf', box.dataset.csrf);
        data.append('op', op);
        fetch(box.dataset.ajax, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true })
            .catch(function () {});
    };
    var show = function (name) {
        step = name;
        [].forEach.call(box.querySelectorAll('[data-eventcrew-app-step]'), function (s) {
            s.hidden = s.getAttribute('data-eventcrew-app-step') !== name;
        });
        dialog.setAttribute('aria-labelledby', 'eventcrew-app-title-' + name);
        if (dialog.open) { var b = box.querySelector('[data-eventcrew-app-step="' + name + '"] button'); if (b) { b.focus(); } }
    };
    var open = function (name) {
        show(name);
        if (dialog.open) { return; }
        returnTo = document.activeElement;
        dialog.showModal();
    };
    // How it was closed decides what is stored: × and Esc close it until
    // the next sign-in; the notifications step, once seen, is done.
    var close = function (how) {
        closing = how;
        dialog.close();
    };
    dialog.addEventListener('close', function () {
        var how = closing;
        closing = '';
        if ('notify' === step) { post('offered'); } else if ('later' !== how) { post('close'); }
        // Turn on notifications moves on to the Notifications card instead.
        if ('notify' !== how && returnTo && returnTo.focus && document.contains(returnTo)) {
            returnTo.focus({ preventScroll: true });
        }
    });
    // Esc closes the dialog by itself (then 'close' above runs).
    part('close').addEventListener('click', function () { close('close'); });
    // A tap on the dim backdrop, outside the card, is a close too.
    dialog.addEventListener('click', function (e) { if (e.target === dialog) { close('close'); } });
    [].forEach.call(box.querySelectorAll('[data-eventcrew-app-later]'), function (b) {
        b.addEventListener('click', function () { post('later'); close('later'); });
    });
    part('install').addEventListener('click', function () {
        if (ios) { show('ios'); return; }
        if (!offer) { return; }
        var asked = offer;
        offer = null;
        asked.prompt();
        asked.userChoice.then(function (choice) {
            if ('accepted' !== choice.outcome) { close('close'); }
        });
    });
    // Presses the Notifications card's own switch (signup-push.php) in this
    // same tap, which the browser's permission question needs; then the
    // click goes on to the page's [data-eventcrew-goto] handler, which opens
    // Settings at the Notifications card.
    part('notify').addEventListener('click', function () {
        var on = document.querySelector('[data-eventcrew-push-on]');
        var toggle = on && on.closest('[data-eventcrew-push-toggle]');
        if (on && toggle && !toggle.hidden && !on.hidden && !on.disabled) { on.click(); }
        close('notify');
    });

    window.addEventListener('appinstalled', function () {
        if ('1' !== box.dataset.installed) { post('installed'); box.dataset.installed = '1'; }
        if (dialog.open) { show('notify'); }
    });

    if (standalone) {
        if ('1' !== box.dataset.installed) { post('installed'); }
        if ('1' !== box.dataset.offerNotify) { return; }
        // Asked already (allowed or blocked): nothing to offer.
        if ('Notification' in window && 'default' !== Notification.permission) { post('offered'); return; }
        open('notify');
        return;
    }

    if ('1' !== box.dataset.popin) { return; }

    if (ios) { open('offer'); return; }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        offer = event;
        if (!dialog.open) { open('offer'); }
    });
})();
</script>
