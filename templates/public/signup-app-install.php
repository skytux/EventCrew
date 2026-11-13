<?php

/**
 * The "Get the app" pop-in of the Crew app page (Owner 2026-11-12), for a
 * signed-in person only: a modal card with the app icon, Install the app
 * and Remind me later, and a close ×. AppInstallPrompt says whether it is
 * due; the script below shows it only in a browser tab that can install
 * the app (an iPhone or iPad, or a browser that offered to install it).
 *
 * Three steps in one card, one shown at a time:
 * - offer: the pitch, its size, and how to install here (Owner
 *   2026-11-13): Install the app where the browser offers one-tap install
 *   (beforeinstallprompt: Chrome, Edge, Samsung Internet, Brave); a short
 *   numbered list where it does not: on an iPhone or iPad (any browser
 *   there is Safari inside) Share, Add to Home Screen; in Firefox on
 *   Android, and any other browser with no offer, the browser menu; on a
 *   computer in Firefox or Safari, no install, notifications in this tab.
 *   Each list ends with how to turn on notifications. Remind me later
 *   under it;
 * - installed (Owner 2026-11-13): once the browser says it installed the
 *   app (appinstalled, or Install accepted in its prompt), "Installed",
 *   where to find it, and Open the app. Chrome cannot move this tab into
 *   the app, so the tab says plainly that the app is elsewhere now;
 * - notify: on the installed app's first opening from the Home Screen
 *   (once), Turn on notifications. It presses the
 *   Notifications card's own "Turn on notifications on this device" link
 *   (signup-push.php), then opens that card so the person can choose the
 *   notices.
 *
 * Installed or not on this device (Owner 2026-11-13): Chrome, Edge and
 * Samsung Internet offer to install (beforeinstallprompt) only where the
 * app is not installed, so that offer brings the pop-in back after an
 * uninstall. Where getInstalledRelatedApps() says the app is installed, a
 * browser tab shows an "Open in the app" line at the top of the page
 * instead (a line with the app icon, not a button: the theme makes buttons
 * huge). An iPhone or
 * iPad tells neither: the app records each opening (op opened, once a day)
 * and Safari offers it again once it has not been opened for a while
 * (ios_due). The installed app offers notifications once per install, and
 * only where this device has no push subscription yet.
 *
 * The bot's "📱 Get the app" DM link (Owner 2026-11-13) lands here with
 * AppInstallPrompt::INVITE_PARAM: the pop-in then opens once on load, even
 * when it was closed or put off, and the argument leaves the address. In
 * the installed app it opens Turn on notifications instead, where this
 * device has no push subscription yet. It stores nothing by itself.
 *
 * For the install numbers (AppStats, Owner 2026-11-13) the script posts
 * op tapped when Install the app is tapped, and op installing when the
 * steps by hand are shown (iPhone or iPad, and the browser menu).
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

/** @var array{popin: bool, installed: bool, ios_due: bool, record_open: bool, offer_notify: bool, action: string, name: string, icon: string, color: string, start?: string}|null $eventcrew_app_install */
/** @var string $eventcrew_ajax */
/** @var string $eventcrew_csrf */

if (null === $eventcrew_app_install) {
    return;
}

$eventcrew_app_start = (string) ($eventcrew_app_install['start'] ?? '');
?>
<div class="eventcrew-app" data-eventcrew-app
    data-ajax="<?php echo esc_url($eventcrew_ajax); ?>"
    data-action="<?php echo esc_attr($eventcrew_app_install['action']); ?>"
    data-csrf="<?php echo esc_attr($eventcrew_csrf); ?>"
    data-start="<?php echo esc_url($eventcrew_app_start); ?>"
    data-popin="<?php echo $eventcrew_app_install['popin'] ? '1' : '0'; ?>"
    data-ios-due="<?php echo $eventcrew_app_install['ios_due'] ? '1' : '0'; ?>"
    data-record-open="<?php echo $eventcrew_app_install['record_open'] ? '1' : '0'; ?>"
    data-offer-notify="<?php echo $eventcrew_app_install['offer_notify'] ? '1' : '0'; ?>"
    data-invite-param="<?php echo esc_attr(\EventCrew\Support\AppInstallPrompt::INVITE_PARAM); ?>">
<p class="eventcrew-app-openline" data-eventcrew-app-open hidden>
    <a href="<?php echo esc_url('' !== $eventcrew_app_start ? $eventcrew_app_start : '#'); ?>" target="_blank" rel="noopener">
        <img src="<?php echo esc_url($eventcrew_app_install['icon']); ?>" width="24" height="24" alt="" loading="lazy">
        <span><?php esc_html_e('Open in the app', 'eventcrew'); ?></span>
    </a>
</p>
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
            <p class="eventcrew-app-size" data-eventcrew-app-size><?php esc_html_e('Tiny: smaller than one photo.', 'eventcrew'); ?></p>
            <div class="eventcrew-app-manual" data-eventcrew-app-how="ios" hidden>
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
                    <li><span><?php esc_html_e('In the app, turn on notifications: Settings tab → Notifications → Turn on notifications on this device.', 'eventcrew'); ?></span></li>
                </ol>
                <p class="eventcrew-muted"><?php esc_html_e('On iPhone, notifications work after you add this page to your Home Screen.', 'eventcrew'); ?></p>
                <p class="eventcrew-muted"><?php esc_html_e('No Add to Home Screen in the Share menu? Open this page in Safari and try again.', 'eventcrew'); ?></p>
            </div>
            <div class="eventcrew-app-manual" data-eventcrew-app-how="menu" hidden>
                <ol class="eventcrew-app-how">
                    <li data-eventcrew-app-inapp hidden><span><?php esc_html_e('Opened inside Telegram or another app? Open this page in your browser first.', 'eventcrew'); ?></span></li>
                    <li><span><?php esc_html_e('Open the browser menu ⋮.', 'eventcrew'); ?></span></li>
                    <li><span><?php esc_html_e('Choose Install or Add to Home screen.', 'eventcrew'); ?></span></li>
                    <li>
                        <img src="<?php echo esc_url($eventcrew_app_install['icon']); ?>" width="24" height="24" alt="" loading="lazy">
                        <span>
                            <?php
                            /* translators: %s: the app's short name, like "EDH Crew". */
                            echo esc_html(sprintf(__('Open %s from your home screen.', 'eventcrew'), $eventcrew_app_install['name']));
                            ?>
                        </span>
                    </li>
                    <li><span><?php esc_html_e('In the app, turn on notifications: Settings tab → Notifications → Turn on notifications on this device.', 'eventcrew'); ?></span></li>
                </ol>
            </div>
            <div class="eventcrew-app-manual" data-eventcrew-app-how="desktop" hidden>
                <p><?php esc_html_e('This browser does not install apps, but notifications work while this page is open in a tab.', 'eventcrew'); ?></p>
                <ol class="eventcrew-app-how">
                    <li><span><?php esc_html_e('Settings tab → Notifications → Turn on notifications on this device.', 'eventcrew'); ?></span></li>
                </ol>
            </div>
            <div class="eventcrew-app-actions">
                <button type="button" class="wp-element-button eventcrew-linkbtn" data-eventcrew-app-install hidden><?php esc_html_e('Install the app', 'eventcrew'); ?></button>
                <button type="button" class="wp-element-button eventcrew-linkbtn" data-eventcrew-app-notify-here
                    data-eventcrew-goto="eventcrew-notifications" hidden><?php esc_html_e('Turn on notifications', 'eventcrew'); ?></button>
                <button type="button" class="eventcrew-settings-btn" data-eventcrew-app-later><?php esc_html_e('Remind me later', 'eventcrew'); ?></button>
            </div>
        </div>
        <div class="eventcrew-app-step" data-eventcrew-app-step="installed" hidden>
            <h2 class="eventcrew-app-title" id="eventcrew-app-title-installed"><?php esc_html_e('Installed', 'eventcrew'); ?></h2>
            <p>
                <?php
                /* translators: %s: the app's short name, like "EDH Crew". */
                echo esc_html(sprintf(__('Open %s from your home screen.', 'eventcrew'), $eventcrew_app_install['name']));
                ?>
            </p>
            <div class="eventcrew-app-actions">
                <button type="button" class="wp-element-button eventcrew-linkbtn" data-eventcrew-app-launch><?php esc_html_e('Open the app', 'eventcrew'); ?></button>
            </div>
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
    // iPadOS Safari says it is a Mac; a Mac has no touch screen. Chrome,
    // Firefox and Edge on an iPhone (CriOS, FxiOS, EdgiOS) are Safari
    // inside: the same Share steps.
    var ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    var android = /Android/.test(ua);
    var desktopFirefox = /Firefox\//.test(ua) && !android && !/Mobile|Tablet/.test(ua);
    var desktopSafari = !ios && /Macintosh/.test(ua) && /Version\/[\d.]+.*Safari\//.test(ua)
        && !/Chrome|Chromium|CriOS|Edg\/|OPR\/|Firefox\//.test(ua);
    // No one-tap install here: the steps by hand. Firefox on Android has
    // Install in its menu; any other browser with no offer is shown the
    // same menu steps when it was asked for (the invite link).
    var manual = ios ? 'ios' : (android && /Firefox\//.test(ua)) ? 'menu' : (desktopFirefox || desktopSafari) ? 'desktop' : '';
    // An app's own browser (Telegram's, an Android WebView): open the page
    // in the real browser first.
    var inApp = /Telegram/i.test(ua) || (android && /; wv\)/.test(ua));
    var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
        || true === window.navigator.standalone;
    var offer = null, returnTo = null, step = 'offer', closing = '', queue = Promise.resolve();
    var start = box.dataset.start || location.pathname;

    // The invite link (the bot's Get the app): opens the pop-in once, then
    // leaves the address, so a reload does not open it again.
    var invited = false;
    try {
        var url = new URL(location.href);
        var param = box.dataset.inviteParam || 'eventcrew_app_invite';
        if (url.searchParams.has(param)) {
            invited = true;
            url.searchParams.delete(param);
            history.replaceState(history.state, '', url.pathname + url.search + url.hash);
        }
    } catch (err) {}

    // One at a time, in order: a new install clears the notifications
    // offer, which must not overtake the offer's own "seen".
    var post = function (op) {
        var data = new FormData();
        data.append('action', box.dataset.action);
        data.append('csrf', box.dataset.csrf);
        data.append('op', op);
        queue = queue.then(function () {
            return fetch(box.dataset.ajax, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true })
                .catch(function () {});
        });
    };
    var show = function (name) {
        step = name;
        [].forEach.call(box.querySelectorAll('[data-eventcrew-app-step]'), function (s) {
            s.hidden = s.getAttribute('data-eventcrew-app-step') !== name;
        });
        dialog.setAttribute('aria-labelledby', 'eventcrew-app-title-' + name);
        if (dialog.open) {
            var b = [].filter.call(box.querySelectorAll('[data-eventcrew-app-step="' + name + '"] button'), function (x) { return !x.hidden; })[0];
            if (b) { b.focus(); }
        }
    };
    // How to install here: 'oneclick' (the Install button), 'ios',
    // 'menu' or 'desktop' (steps by hand).
    var method = function (v) {
        [].forEach.call(box.querySelectorAll('[data-eventcrew-app-how]'), function (el) {
            el.hidden = el.getAttribute('data-eventcrew-app-how') !== v;
        });
        part('install').hidden = 'oneclick' !== v;
        part('notify-here').hidden = 'desktop' !== v;
        part('size').hidden = 'desktop' === v;
        part('inapp').hidden = !inApp;
        // The next opening from the Home Screen is a new install (no
        // appinstalled event there); also counted as a try (AppStats).
        if ('ios' === v || 'menu' === v) { post('installing'); }
    };
    var open = function (name) {
        show(name);
        if (dialog.open) { return; }
        returnTo = document.activeElement;
        // Seen on this visit: the "Where do you want them?" pop-in
        // (signup-channels.php) waits for another visit.
        box.dataset.shown = '1';
        dialog.showModal();
    };
    var openOffer = function (v) {
        method(v);
        open('offer');
    };
    // How it was closed decides what is stored: × and Esc close it until
    // the next sign-in; the notifications step, once seen, is done; the
    // installed step stores nothing more (appinstalled stored the install).
    var close = function (how) {
        closing = how;
        dialog.close();
    };
    dialog.addEventListener('close', function () {
        var how = closing;
        closing = '';
        if ('notify' === step) { post('offered'); } else if ('installed' !== step && 'later' !== how) { post('close'); }
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
        if (!offer) { return; }
        var asked = offer;
        offer = null;
        // A try, counted for the install numbers (AppStats).
        post('tapped');
        asked.prompt();
        asked.userChoice.then(function (choice) {
            if ('accepted' === choice.outcome) { installedHere(); } else { close('close'); }
        });
    });

    /*
     * Open the app from this browser tab. No web API launches an installed
     * app, and Chrome cannot move a tab into it. What comes closest on
     * Android (2026-11-13, not tried on a phone yet): a new top-level
     * navigation, from a tap, to the start address inside the manifest's
     * scope. The installed WebAPK registers its scope as an Android link
     * handler, and Chrome checks a new navigation from a gesture against
     * such handlers, so it may hand the address to the app or ask whether
     * to open it there, by Chrome version. Where it does not, the new tab
     * shows the page again, and the text above says where the app is (the
     * home screen icon). Not chosen: a same-tab location change (an
     * in-page navigation, the least likely to leave Chrome) and an
     * intent:// address (needs the WebAPK's package name, which a page
     * cannot know). window.open without "noopener" (that makes it return
     * null), then the opener cut by hand; if it is blocked, this tab goes.
     */
    var launch = function (e) {
        if (e) { e.preventDefault(); }
        var w = window.open(start, '_blank');
        if (w) { try { w.opener = null; } catch (err) {} } else { location.href = start; }
    };
    part('launch').addEventListener('click', launch);
    // Kept by reference: once moved to the top it is outside the box.
    var line = part('open'), lineShown = false;
    var openLine = function () {
        if (!line || lineShown) { return; }
        lineShown = true;
        // At the top of the page, under the tab bar, where it is seen first.
        var root = box.closest('.eventcrew-signup');
        var nav = root && root.querySelector('.eventcrew-tabs');
        if (nav && nav.parentNode) { nav.parentNode.insertBefore(line, nav.nextSibling); } else if (root) { root.insertBefore(line, root.firstChild); }
        line.querySelector('a').addEventListener('click', launch);
        line.hidden = false;
    };
    // The browser installed the app from this tab: say so in the pop-in,
    // opened again if it was closed (installed from the browser menu).
    var installedHere = function () {
        openLine();
        open('installed');
    };
    // Presses the Notifications card's own switch (signup-push.php) in this
    // same tap, which the browser's permission question needs; then the
    // click goes on to the page's [data-eventcrew-goto] handler, which opens
    // Settings at the Notifications card.
    var notify = function () {
        var on = document.querySelector('[data-eventcrew-push-on]');
        var toggle = on && on.closest('[data-eventcrew-push-toggle]');
        if (on && toggle && !toggle.hidden && !on.hidden && !on.disabled) { on.click(); }
        close('notify');
    };
    part('notify').addEventListener('click', notify);
    part('notify-here').addEventListener('click', notify);

    window.addEventListener('appinstalled', function () {
        post('installed');
        installedHere();
    });

    if (standalone) {
        if ('1' === box.dataset.recordOpen) { post('opened'); }
        if (!invited && '1' !== box.dataset.offerNotify) { return; }
        // Blocked, or this device gets them already: nothing to offer.
        var sw = navigator.serviceWorker;
        (sw && 'PushManager' in window
            ? sw.getRegistration().then(function (r) { return r && r.pushManager.getSubscription(); })
            : Promise.resolve(null)
        ).catch(function () { return null; }).then(function (sub) {
            if (sub || ('Notification' in window && 'denied' === Notification.permission)) { post('offered'); return; }
            open('notify');
        });
        return;
    }

    // Installed on this device (Chrome, Edge): the Open in the app line at
    // the top, no pop-in.
    var installed = navigator.getInstalledRelatedApps
        ? navigator.getInstalledRelatedApps().catch(function () { return []; })
        : Promise.resolve([]);
    installed.then(function (apps) {
        if (!apps || !apps.length) { return; }
        openLine();
    });

    if (!invited && '1' !== box.dataset.popin) { return; }

    // No one-tap install in this browser: the steps, when due (an iPhone
    // only once it has not opened the app for a while) or invited.
    if (manual) {
        if (invited || 'ios' !== manual || '1' === box.dataset.iosDue) { openOffer(manual); }
        return;
    }

    // Offered only where the app is not installed: after an uninstall too.
    // Steps by hand shown while waiting give way to the button.
    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        offer = event;
        installed.then(function (apps) {
            if (apps && apps.length) { return; }
            if (!dialog.open) { openOffer('oneclick'); } else if ('offer' === step) { method('oneclick'); }
        });
    });

    // Invited, and the browser offers no install after a moment: installed
    // here already (Open the app), or the menu steps.
    if (invited) {
        setTimeout(function () {
            if (offer || dialog.open) { return; }
            installed.then(function (apps) {
                if (offer || dialog.open) { return; }
                if (apps && apps.length) { open('installed'); } else { openOffer('menu'); }
            });
        }, 2500);
    }
})();
</script>
