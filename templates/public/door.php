<?php

/**
 * The sales desk (door page): one night's entry list for the door crew's
 * phones, served by Web\DoorController over admin-ajax.
 *
 * A whole page of its own, not a theme page: it has to load fast on a phone at
 * the door and must not be cached, so it carries its own small stylesheet and
 * script and no theme or CDN assets. The first state is printed into the page;
 * after that the script asks for fresh state every few seconds while the tab is
 * visible and redraws only when the state's version has changed.
 *
 * The "Add" button opens a popup for someone not on the lists yet: a ticket
 * sold at the door, a Holvi buyer missing from the imported list, a guest, or
 * a crew member, who either spends a credit or signs up for one of tonight's
 * tasks. A member who has no record yet can be added there with a name and an
 * email.
 *
 * The "+1" and "−1" buttons at the top count people in without a name, such
 * as the organizers themselves. That count is part of the "checked in" total.
 *
 * The "Scan" button opens the phone's camera and reads the QR code on a
 * crew, free-entry or (later) buyer ticket. The server finds the person on
 * tonight's list (DoorDesk::scan) without marking them; the popup then shows
 * the name, a +1 counter up to what that person may bring, and "Mark
 * arrived". The QR code is read by the browser's own BarcodeDetector where
 * there is one, and otherwise by the bundled decoder of the qr library
 * (assets/vendor/qr-decode, MIT), which is loaded only when Scan is tapped.
 *
 * The "Switch" button (two opposite arrows) opens the "Other device" popup,
 * which shows a one-time login link as a QR code: for this person on a second phone, or ("Login as") for another
 * organizer or sales-desk crew member (Support\DoorHandoff). The QR code
 * is drawn here as SVG by the bundled qrcode-generator library
 * (assets/vendor/qrcode-generator), the one file this page loads besides
 * itself.
 *
 * @var array<string, mixed> $eventcrew_state  DoorDesk::state() for the night
 * @var array<string, mixed> $eventcrew_config ajaxUrl, csrf, who, pollMs, searchMin, maxPlus, actions, qrDecodeUrl
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_text = [
    'out' => __('Check in', 'eventcrew'),
    'guests' => __('guests', 'eventcrew'),
    'credits' => __('credits', 'eventcrew'),
    'noMatch' => __('No member matches. Add them below as a new member.', 'eventcrew'),
    'choose' => __('Choose', 'eventcrew'),
    /* translators: %s: the list name, "Crew working" or "Free-entry credit" */
    'onList' => __('Already on tonight’s list: %s', 'eventcrew'),
    'swap' => __('Take a task instead', 'eventcrew'),
    'addTask' => __('Add a task', 'eventcrew'),
    /* translators: %d: how many free-entry credits the member has */
    'useCredit' => __('Use a credit (%d left)', 'eventcrew'),
    'noCredit' => __('No credit to use', 'eventcrew'),
    'noTasks' => __('No task tonight has a free place.', 'eventcrew'),
    'free' => __('free', 'eventcrew'),
    'signUp' => __('Sign up', 'eventcrew'),
    'signedUp' => __('✓ Signed up', 'eventcrew'),
    'askCredit' => __('Count toward free-entry credit?', 'eventcrew'),
    'yes' => __('Yes', 'eventcrew'),
    'no' => __('No', 'eventcrew'),
    'needName' => __('Give the guest’s name.', 'eventcrew'),
    'extra' => __('extra', 'eventcrew'),
    'empty' => __('Nobody on this list.', 'eventcrew'),
    'failed' => __('That did not go through. Check the connection and try again.', 'eventcrew'),
    'fewer' => __('One guest fewer', 'eventcrew'),
    'more' => __('One guest more', 'eventcrew'),
    'ownTitle' => __('Open on other device', 'eventcrew'),
    /* translators: %s: the name of the person the link logs in */
    'asTitle' => __('Login as %s', 'eventcrew'),
    // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    'scan' => __('Scan this QR code with the camera of the other phone. The link works once and stops working after 15 minutes.', 'eventcrew'),
    /* translators: %s: the name of the person the link logs in */
    'asWho' => __('The other phone opens the sales desk as %s.', 'eventcrew'),
    'qrLabel' => __('QR code of the login link', 'eventcrew'),
    'noQr' => __('The QR code could not be drawn. Use Copy link instead.', 'eventcrew'),
    'copied' => __('Link copied.', 'eventcrew'),
    'selected' => __('The link is selected. Copy it with the copy command of your phone.', 'eventcrew'),
    'noPeople' => __('Nobody else can run the sales desk tonight yet.', 'eventcrew'),
    'showQr' => __('Show QR code', 'eventcrew'),
    'loading' => __('Loading…', 'eventcrew'),
    // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    'noCamera' => __('The camera could not be opened. Allow the camera for this page, or find the name on the list instead.', 'eventcrew'),
    'noDecoder' => __('This phone cannot read QR codes here. Find the name on the list instead.', 'eventcrew'),
    'already' => __('Already marked arrived.', 'eventcrew'),
    /* translators: %s: the name of the person just marked arrived */
    'arrived' => __('Welcome %s', 'eventcrew'),
];
?><!doctype html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php esc_html_e('Sales desk', 'eventcrew'); ?></title>
<style>
:root{--fg:#1d1d1f;--muted:#6e6e73;--bg:#fff;--card:#f5f5f7;--line:#d2d2d7;--in:#1a7f37;--in-bg:#dafbe1;--accent:#0b57d0;--err:#b3261e;--err-bg:#fde7e7}
@media (prefers-color-scheme:dark){:root{--fg:#f5f5f7;--muted:#a1a1a6;--bg:#111;--card:#1c1c1e;--line:#38383a;--in:#4ac26b;--in-bg:#12361e;--accent:#8ab4f8;--err:#ff8a80;--err-bg:#3b1414}}
*{box-sizing:border-box}
body{margin:0;font:16px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--fg);background:var(--bg)}
header{position:sticky;top:0;z-index:2;background:var(--bg);border-bottom:1px solid var(--line);padding:6px 12px 8px}
h1{font-size:14px;font-weight:600;color:var(--muted);margin:0}
.ec-count{font-size:24px;font-weight:700;line-height:1.15;font-variant-numeric:tabular-nums}
.ec-count small{font-size:16px;font-weight:400;color:var(--muted)}
input[type=search]{width:100%;font:inherit;padding:8px 12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--fg);margin-top:6px}
main{padding:8px 16px 40px}
h2{font-size:15px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin:20px 0 6px}
ul{list-style:none;margin:0;padding:0}
li{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--line)}
li.is-in .ec-name{color:var(--in)}
.ec-who{flex:1;min-width:0}
.ec-name{font-weight:600;overflow-wrap:anywhere}
.ec-detail{font-size:13px;color:var(--muted)}
button{font:inherit;border:1px solid var(--line);background:var(--card);color:var(--fg);border-radius:10px;min-height:44px;min-width:44px;padding:0 12px;cursor:pointer}
button:disabled{opacity:.5}
.ec-plus{display:flex;align-items:center;gap:4px}
.ec-plus span{min-width:4.5em;text-align:center;font-size:14px;font-variant-numeric:tabular-nums}
.ec-toggle{width:48px;padding:0;color:var(--muted)}
.ec-toggle svg{display:block;width:24px;height:24px;margin:auto}
.ec-toggle.is-in{background:var(--in);border-color:var(--in);color:var(--bg)}
.ec-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-width:58px;min-height:52px;padding:5px 6px}
.ec-tile svg{display:block;width:24px;height:24px}
.ec-tile span{font-size:11px;line-height:1.1;white-space:nowrap}
.ec-spin{display:inline-block;width:20px;height:20px;border:2.5px solid var(--muted);border-right-color:transparent;border-radius:50%;animation:ec-spin .8s linear infinite}
@keyframes ec-spin{to{transform:rotate(360deg)}}
button.is-busy{position:relative;color:transparent!important}
button.is-busy:disabled{opacity:1}
button.is-busy::after{content:"";position:absolute;inset:0;margin:auto;width:20px;height:20px;border:2.5px solid var(--fg);border-right-color:transparent;border-radius:50%;animation:ec-spin .8s linear infinite}
.ec-primary.is-busy::after,.ec-toggle.is-in.is-busy::after{border-color:var(--bg);border-right-color:transparent}
li.ec-loading{justify-content:center;border-bottom:0;padding:16px 0}
.ec-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.ec-msg{margin:8px 0;padding:8px 12px;border-radius:10px;background:var(--card)}
.ec-msg:empty{display:none}
.ec-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.ec-anon{display:flex;align-items:center;gap:8px;margin-top:6px}
.ec-anon-label{color:var(--muted);margin-right:auto}
.ec-busy{display:flex;justify-content:center;margin-top:6px}
#ec-anon-count{min-width:2ch;text-align:center;font-size:20px;font-weight:700;font-variant-numeric:tabular-nums}
.ec-primary{background:var(--accent);border-color:var(--accent);color:var(--bg);font-weight:700}
dialog{width:calc(100% - 32px);max-width:480px;margin:16px auto auto;padding:16px;border:1px solid var(--line);border-radius:14px;background:var(--bg);color:var(--fg)}
dialog::backdrop{background:rgba(0,0,0,.5)}
dialog h3{font-size:17px;margin:12px 0 8px}
dialog label{display:block;margin-top:10px}
dialog input[type=text],dialog input[type=email]{width:100%;font:inherit;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--fg);margin-top:4px}
.ec-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:8px}
.ec-dialog-head h2{margin:0}
.ec-stack{display:flex;flex-direction:column;gap:8px;margin-top:12px}
.ec-stack button{width:100%}
#ec-members,#ec-tasks{max-height:45vh;overflow-y:auto}
.ec-muted{color:var(--muted)}
.ec-buttons{display:flex;gap:8px}
#ec-people{max-height:45vh;overflow-y:auto}
#ec-qr{margin:12px auto;max-width:280px}
#ec-qr svg{display:block;width:100%;height:auto}
#ec-qr-link{width:100%;font:13px/1.4 ui-monospace,monospace;padding:8px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--fg)}
#ec-scan-video{display:block;width:100%;max-height:55vh;object-fit:cover;border-radius:10px;background:#000}
#ec-scan-name{font-size:32px;line-height:1.2;font-weight:700;margin:12px 0 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ec-msg.is-error{background:var(--err-bg);border:2px solid var(--err);color:var(--err);font-weight:700}
.ec-msg.is-welcome{background:var(--in-bg);border:2px solid var(--in);color:var(--in);font-size:26px;line-height:1.2;font-weight:700;text-align:center}
#ec-scan-already{color:var(--in);font-weight:700}
.ec-scan-plus{display:flex;align-items:center;justify-content:center;gap:12px;margin-top:12px}
#ec-scan-count{min-width:5em;text-align:center;font-size:20px;font-weight:700;font-variant-numeric:tabular-nums}
#ec-scan-mark{min-height:64px;font-size:20px}
[hidden]{display:none!important}
</style>
</head>
<body>
<header>
    <div class="ec-top">
    <div>
    <h1 id="ec-day"></h1>
    <div class="ec-count" aria-live="polite"><span id="ec-in">0</span> <small><?php
        /* translators: %s: the number of people expected tonight. */
        printf(esc_html__('of %s checked in', 'eventcrew'), '<span id="ec-expected">0</span>');
    ?></small></div>
    </div>
    <div class="ec-buttons">
    <button type="button" class="ec-tile" id="ec-scan-open"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v.01M14 21h3M21 17v4h-1"/></svg><span><?php esc_html_e('Scan', 'eventcrew'); ?></span></button>
    <button type="button" class="ec-tile" id="ec-other-open"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3 3 7l4 4M3 7h14M17 13l4 4-4 4M21 17H7"/></svg><span><?php esc_html_e('Switch', 'eventcrew'); ?></span></button>
    <button type="button" class="ec-tile ec-primary" id="ec-add-open"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span><?php esc_html_e('Add', 'eventcrew'); ?></span></button>
    </div>
    </div>
    <div class="ec-anon">
        <span class="ec-anon-label"><?php
            /* translators: label before the count of people let in without a name, and its -1 and +1 buttons. */
            esc_html_e('Without a name:', 'eventcrew');
        ?></span>
        <button type="button" id="ec-anon-less" aria-label="<?php esc_attr_e('Take one person off the count without a name', 'eventcrew'); ?>">−1</button>
        <span id="ec-anon-count" aria-live="polite">0</span>
        <button type="button" id="ec-anon-more" aria-label="<?php esc_attr_e('Count one person in without a name', 'eventcrew'); ?>">+1</button>
    </div>
    <div class="ec-busy" id="ec-busy" role="status" hidden><span class="ec-spin" aria-hidden="true"></span><span class="ec-sr"><?php esc_html_e('Loading…', 'eventcrew'); ?></span></div>
    <input type="search" id="ec-filter" placeholder="<?php esc_attr_e('Find a name on the list', 'eventcrew'); ?>" autocomplete="off">
</header>
<main>
    <p class="ec-msg" id="ec-msg" role="status"></p>
    <div id="ec-sections"></div>
</main>
<dialog id="ec-add" aria-labelledby="ec-add-title">
    <div class="ec-dialog-head">
        <button type="button" id="ec-add-back"><?php esc_html_e('‹ Back', 'eventcrew'); ?></button>
        <h2 id="ec-add-title"><?php esc_html_e('Add', 'eventcrew'); ?></h2>
        <button type="button" id="ec-add-close"><?php esc_html_e('Close', 'eventcrew'); ?></button>
    </div>
    <p class="ec-msg" id="ec-add-msg" role="status"></p>

    <div id="ec-step-choose" class="ec-stack">
        <button type="button" id="ec-pick-ticket"><?php esc_html_e('Ticket', 'eventcrew'); ?></button>
        <button type="button" id="ec-pick-holvi"><?php esc_html_e('Bought on Holvi, not on the list', 'eventcrew'); ?></button>
        <button type="button" id="ec-pick-guest"><?php esc_html_e('Guest', 'eventcrew'); ?></button>
        <button type="button" id="ec-pick-member"><?php esc_html_e('Crew member', 'eventcrew'); ?></button>
    </div>

    <div id="ec-step-ticket" hidden>
        <h3><?php esc_html_e('Ticket sold at the door', 'eventcrew'); ?></h3>
        <label><?php esc_html_e('Name (optional)', 'eventcrew'); ?>
            <input type="text" id="ec-ticket-name" autocomplete="off"></label>
        <div class="ec-stack">
            <button type="button" class="ec-primary" id="ec-ticket-add"><?php esc_html_e('Add and check in', 'eventcrew'); ?></button>
        </div>
    </div>

    <div id="ec-step-holvi" hidden>
        <h3><?php esc_html_e('Bought on Holvi, not on the list', 'eventcrew'); ?></h3>
        <label><?php esc_html_e('Name (optional)', 'eventcrew'); ?>
            <input type="text" id="ec-holvi-name" autocomplete="off"></label>
        <label><?php esc_html_e('Email (optional)', 'eventcrew'); ?>
            <input type="email" id="ec-holvi-email" autocomplete="off"></label>
        <p class="ec-muted"><?php esc_html_e('The email is only shown on the list. Nothing is sent to it.', 'eventcrew'); ?></p>
        <div class="ec-stack">
            <button type="button" class="ec-primary" id="ec-holvi-add"><?php esc_html_e('Add and check in', 'eventcrew'); ?></button>
        </div>
    </div>

    <div id="ec-step-guest" hidden>
        <h3><?php esc_html_e('Guest', 'eventcrew'); ?></h3>
        <label><?php esc_html_e('Name', 'eventcrew'); ?>
            <input type="text" id="ec-guest-name" autocomplete="off"></label>
        <p><?php esc_html_e('Extra people they may bring (0 = just the guest):', 'eventcrew'); ?></p>
        <div class="ec-plus">
            <button type="button" id="ec-guest-less" aria-label="<?php esc_attr_e('One fewer', 'eventcrew'); ?>">−</button>
            <span id="ec-guest-count">+0</span>
            <button type="button" id="ec-guest-more" aria-label="<?php esc_attr_e('One more', 'eventcrew'); ?>">+</button>
        </div>
        <div class="ec-stack">
            <button type="button" class="ec-primary" id="ec-guest-add"><?php esc_html_e('Add and check in', 'eventcrew'); ?></button>
        </div>
    </div>

    <div id="ec-step-member" hidden>
        <h3><?php esc_html_e('Crew member', 'eventcrew'); ?></h3>
        <input type="search" id="ec-member" placeholder="<?php esc_attr_e('Member name or email', 'eventcrew'); ?>" autocomplete="off">
        <ul id="ec-members"></ul>
        <h3><?php esc_html_e('New member', 'eventcrew'); ?></h3>
        <p class="ec-muted"><?php esc_html_e('They get an email to confirm their address and join the Telegram group.', 'eventcrew'); ?></p>
        <label><?php esc_html_e('Name', 'eventcrew'); ?>
            <input type="text" id="ec-new-name" autocomplete="off"></label>
        <label><?php esc_html_e('Email', 'eventcrew'); ?>
            <input type="email" id="ec-new-email" autocomplete="off"></label>
        <div class="ec-stack">
            <button type="button" id="ec-new-add"><?php esc_html_e('Add new member', 'eventcrew'); ?></button>
        </div>
    </div>

    <div id="ec-step-choice" hidden>
        <h3 id="ec-choice-name"></h3>
        <div class="ec-stack">
            <button type="button" id="ec-choice-credit"></button>
            <button type="button" id="ec-choice-work"><?php esc_html_e('Working tonight: pick a task', 'eventcrew'); ?></button>
        </div>
    </div>

    <div id="ec-step-tasks" hidden>
        <h3><?php esc_html_e('Which task?', 'eventcrew'); ?></h3>
        <ul id="ec-tasks"></ul>
    </div>
</dialog>
<dialog id="ec-other" aria-labelledby="ec-other-title">
    <div class="ec-dialog-head">
        <button type="button" id="ec-other-back"><?php esc_html_e('‹ Back', 'eventcrew'); ?></button>
        <h2 id="ec-other-title"><?php esc_html_e('Other device', 'eventcrew'); ?></h2>
        <button type="button" id="ec-other-close"><?php esc_html_e('Close', 'eventcrew'); ?></button>
    </div>
    <p class="ec-msg" id="ec-other-msg" role="status"></p>

    <div id="ec-other-choose" class="ec-stack">
        <button type="button" id="ec-pick-as"><?php esc_html_e('Login as', 'eventcrew'); ?></button>
        <button type="button" id="ec-pick-own"><?php esc_html_e('Open on other device', 'eventcrew'); ?></button>
    </div>

    <div id="ec-other-people" hidden>
        <h3><?php esc_html_e('Login as', 'eventcrew'); ?></h3>
        <p class="ec-muted"><?php esc_html_e('Organizers, and tonight’s welcome crew once marked arrived.', 'eventcrew'); ?></p>
        <ul id="ec-people"></ul>
    </div>

    <div id="ec-other-qr" hidden>
        <h3 id="ec-qr-title"></h3>
        <p id="ec-qr-text"></p>
        <div id="ec-qr"></div>
        <input type="text" id="ec-qr-link" readonly aria-label="<?php esc_attr_e('Login link', 'eventcrew'); ?>">
        <div class="ec-stack">
            <button type="button" id="ec-qr-copy"><?php esc_html_e('Copy link', 'eventcrew'); ?></button>
        </div>
    </div>
</dialog>
<dialog id="ec-scan" aria-labelledby="ec-scan-title">
    <div class="ec-dialog-head">
        <h2 id="ec-scan-title"><?php esc_html_e('Scan ticket', 'eventcrew'); ?></h2>
        <button type="button" id="ec-scan-close"><?php esc_html_e('Close', 'eventcrew'); ?></button>
    </div>
    <p class="ec-msg" id="ec-scan-msg" role="status"></p>

    <div id="ec-scan-camera">
        <video id="ec-scan-video" playsinline muted></video>
        <p class="ec-muted"><?php esc_html_e('Point the camera at the QR code on the ticket.', 'eventcrew'); ?></p>
    </div>

    <div id="ec-scan-result" hidden>
        <p id="ec-scan-name"></p>
        <p class="ec-muted" id="ec-scan-detail"></p>
        <p id="ec-scan-already" hidden><?php esc_html_e('Already marked arrived.', 'eventcrew'); ?></p>
        <div class="ec-scan-plus" id="ec-scan-plus">
            <button type="button" id="ec-scan-less" aria-label="<?php esc_attr_e('One guest fewer', 'eventcrew'); ?>">−</button>
            <span id="ec-scan-count" aria-live="polite">+0</span>
            <button type="button" id="ec-scan-more" aria-label="<?php esc_attr_e('One guest more', 'eventcrew'); ?>">+</button>
        </div>
        <div class="ec-stack">
            <button type="button" class="ec-primary" id="ec-scan-mark"><?php esc_html_e('Mark arrived', 'eventcrew'); ?></button>
            <button type="button" id="ec-scan-next"><?php esc_html_e('Scan another', 'eventcrew'); ?></button>
        </div>
    </div>
</dialog>
<script src="<?php echo esc_url(EVENTCREW_PLUGIN_URL . 'assets/vendor/qrcode-generator/qrcode.js?ver=' . EVENTCREW_VERSION); ?>"></script>
<script>
(function () {
    'use strict';

    <?php
    // JSON with <, > and & escaped, so no name on the list can close this
    // script element.
    $eventcrew_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- hex-escaped JSON, see above.
    ?>
    var config = <?php echo wp_json_encode($eventcrew_config, $eventcrew_flags); ?>;
    var text = <?php echo wp_json_encode($eventcrew_text, $eventcrew_flags); ?>;
    var state = <?php echo wp_json_encode($eventcrew_state, $eventcrew_flags); ?>;
    <?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>

    // Plus-ones typed for a row that is not in yet. They go along when the
    // row is marked in; a redraw from the server keeps them.
    var localPlus = {};
    var timer = null;

    // The Add popup: which step shows, the member picked, the guest's count.
    var steps = ['choose', 'ticket', 'holvi', 'guest', 'member', 'choice', 'tasks'];
    var current = 'choose';
    var chosen = null;
    var guestPlus = 0;

    var el = function (id) { return document.getElementById(id); };

    // A fixed string, no data in it, so innerHTML is safe.
    var tickIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"'
        + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>';

    function node(tag, cls, content) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (undefined !== content) { n.textContent = content; }
        return n;
    }

    // While a request is out for more than a moment, a spinner shows where
    // the person tapped: on the button itself. A request no tap started gets
    // the spinner line instead, inside the open popup (which covers the
    // header) or in the header.
    var tapped = null;
    var lineCount = 0;

    // Capture runs before the button's own handler, the bubbling listener
    // after it, so a post() made inside a click handler sees its button.
    document.addEventListener('click', function (event) {
        tapped = event.target.closest ? event.target.closest('button') : null;
    }, true);
    document.addEventListener('click', function () { tapped = null; });

    function showLine() {
        var line = el('ec-busy');
        var open = document.querySelector('dialog[open]');
        var head = open ? open.querySelector('.ec-dialog-head') : null;

        if (head) {
            head.after(line);
        } else if (open) {
            open.prepend(line);
        } else {
            document.querySelector('header').insertBefore(line, el('ec-filter'));
        }

        line.hidden = false;
    }

    // Starts the spinner for one request; the function it returns stops it.
    function busy(source) {
        var shown = '';
        var wait = setTimeout(function () {
            if (source && source.isConnected) {
                source.classList.add('is-busy');
                source.setAttribute('aria-busy', 'true');
                shown = 'button';
                return;
            }

            lineCount++;
            shown = 'line';
            showLine();
        }, 300);

        return function () {
            clearTimeout(wait);

            if ('button' === shown) {
                source.classList.remove('is-busy');
                source.removeAttribute('aria-busy');
            } else if ('line' === shown) {
                lineCount = Math.max(0, lineCount - 1);
                el('ec-busy').hidden = 0 === lineCount;
            }
        };
    }

    // A list request that did not go through: the list stops saying
    // spinning and says that instead, then the usual handler runs.
    function listFailed(list, handler) {
        return function (error) {
            if (!error || 'session' !== error.message) {
                list.textContent = '';
                listError(list, null);
            }

            handler(error);
        };
    }

    // A list waiting for its answer shows a spinner in its middle.
    function listLoading(list) {
        var li = node('li', 'ec-loading');

        li.setAttribute('role', 'status');
        li.appendChild(node('span', 'ec-spin')).setAttribute('aria-hidden', 'true');
        li.appendChild(node('span', 'ec-sr', text.loading));
        list.textContent = '';
        list.appendChild(li);
    }

    function post(action, fields) {
        var body = new URLSearchParams();
        body.append('action', config.actions[action]);
        body.append('csrf', config.csrf);
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });

        var done = busy(tapped);

        return fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                done();
                return answer(response);
            }, function (error) {
                done();
                throw error;
            });
    }

    function answer(response) {
        // The session has ended (the night closed, or the cookie is gone):
        // reloading lands on the page that says so.
        if (401 === response.status) {
            location.reload();
            return Promise.reject(new Error('session'));
        }

        return response.json();
    }

    // While the Add popup is open, messages show inside it.
    function say(message) {
        var target = 'ec-msg';

        if (el('ec-add').open) {
            target = 'ec-add-msg';
        } else if (el('ec-scan').open) {
            // Only failures reach say() while the scan popup is open.
            scanMessage(message, true);
            return;
        }

        el(target).textContent = message || '';
    }

    function plusOf(row) {
        return row.in || undefined === localPlus[row.key] ? row.plus : localPlus[row.key];
    }

    function draw() {
        var query = el('ec-filter').value.trim().toLowerCase();
        var holder = el('ec-sections');

        // The date, then who this device is logged in as.
        el('ec-day').textContent = config.who ? state.day + ' · ' + config.who : state.day;
        el('ec-in').textContent = state.in;
        el('ec-expected').textContent = state.expected;
        el('ec-anon-count').textContent = state.anonymous;
        el('ec-anon-less').disabled = state.anonymous <= 0;
        holder.textContent = '';

        state.sections.forEach(function (section) {
            var rows = section.rows.filter(function (row) {
                return '' === query || -1 !== (row.name + ' ' + row.detail).toLowerCase().indexOf(query);
            });
            var inCount = section.rows.filter(function (row) { return row.in; }).length;
            var list = node('ul');

            holder.appendChild(node('h2', '', section.title + ' · ' + inCount + '/' + section.rows.length));

            if (0 === rows.length) {
                list.appendChild(node('li', 'ec-muted', text.empty));
            }

            rows.forEach(function (row) { list.appendChild(rowNode(row)); });
            holder.appendChild(list);
        });
    }

    function rowNode(row) {
        var li = node('li', row.in ? 'is-in' : '');
        var who = node('div', 'ec-who');
        var plus = node('div', 'ec-plus');
        var count = plusOf(row);
        var less = node('button', '', '−');
        var more = node('button', '', '+');
        var toggle = node('button', 'ec-toggle' + (row.in ? ' is-in' : ''));

        // A tick: grey outline until the person is in, then green. The name
        // stays "Check in"; aria-pressed says whether they are in, and a tap
        // on a green tick undoes it.
        toggle.innerHTML = tickIcon;
        toggle.setAttribute('aria-label', text.out);

        who.appendChild(node('div', 'ec-name', row.name));
        if (row.detail) { who.appendChild(node('div', 'ec-detail', row.detail)); }

        // A row whose max is 0 has no guest count at all: free-entry credit
        // rows, and crew whose tasks tonight allow no +1.
        if (row.max > 0) {
            less.setAttribute('aria-label', text.fewer);
            more.setAttribute('aria-label', text.more);
            less.disabled = count <= 0;
            more.disabled = count >= row.max;
            less.addEventListener('click', function () { setPlus(row, count - 1); });
            more.addEventListener('click', function () { setPlus(row, count + 1); });
            plus.appendChild(less);
            plus.appendChild(node('span', '', '+' + count + ' ' + text.guests));
            plus.appendChild(more);
        }

        toggle.setAttribute('aria-pressed', row.in ? 'true' : 'false');
        toggle.addEventListener('click', function () {
            toggle.disabled = true;
            post('mark', { kind: row.kind, ref: row.ref, in: row.in ? '0' : '1', plus: plusOf(row) })
                .then(apply, failed);
        });

        li.appendChild(who);
        li.appendChild(plus);
        li.appendChild(toggle);

        return li;
    }

    function setPlus(row, value) {
        value = Math.max(0, Math.min(row.max, value));

        if (!row.in) {
            localPlus[row.key] = value;
            draw();
            return;
        }

        post('plus', { kind: row.kind, ref: row.ref, plus: value }).then(apply, failed);
    }

    function apply(data) {
        if (data && data.state) {
            state = data.state;
            draw();
        }

        return data;
    }

    function failed(error) {
        if (!error || 'session' !== error.message) {
            say(text.failed);
            draw();
        }
    }

    function poll() {
        clearTimeout(timer);
        timer = null;

        if (document.hidden) {
            return;
        }

        fetch(config.ajaxUrl + '?action=' + encodeURIComponent(config.actions.state), { credentials: 'same-origin' })
            .then(answer)
            .then(function (fresh) {
                if (fresh && fresh.version && fresh.version !== state.version) {
                    state = fresh;
                    draw();
                }
            })
            .catch(function () { /* The next poll tries again. */ })
            .then(function () {
                if (!document.hidden && null === timer) {
                    timer = setTimeout(poll, config.pollMs);
                }
            });
    }

    // An answer without the list that was asked for (an error status such
    // as 403, or ok:false) is not an empty list: the list says what the
    // server said, or that the request did not go through.
    function listError(list, data) {
        list.appendChild(node('li', 'ec-muted', data && data.message ? data.message : text.failed));
    }

    function searchMembers() {
        var query = el('ec-member').value.trim();
        var list = el('ec-members');

        if (query.length < config.searchMin) {
            list.textContent = '';
            return;
        }

        listLoading(list);

        post('search', { q: query }).then(function (data) {
            list.textContent = '';

            if (!data || !Array.isArray(data.members)) {
                listError(list, data);
                return;
            }

            if (0 === data.members.length) {
                list.appendChild(node('li', 'ec-muted', text.noMatch));
                return;
            }

            data.members.forEach(function (member) {
                var li = node('li');
                var who = node('div', 'ec-who');
                var pick = node('button', '', text.choose);

                who.appendChild(node('div', 'ec-name', member.name));
                li.appendChild(who);
                list.appendChild(li);

                // Already in tonight's lists: shown so the door knows. Crew
                // can take another task; a free-entry credit holder can take
                // a task, which gives their credit back.
                if (member.list) {
                    who.appendChild(node('div', 'ec-detail', text.onList.replace('%s', member.list)));
                    pick.textContent = 'credit' === member.kind ? text.swap : text.addTask;
                    pick.addEventListener('click', function () { chosen = member; showTasks(); });
                    li.appendChild(pick);

                    return;
                }

                who.appendChild(node('div', 'ec-detail', member.credits + ' ' + text.credits));
                pick.addEventListener('click', function () { showChoice(member); });
                li.appendChild(pick);
            });
        }, listFailed(list, failed));
    }

    function step(name) {
        current = name;
        steps.forEach(function (s) { el('ec-step-' + s).hidden = s !== name; });
        el('ec-add-back').hidden = 'choose' === name;
        el('ec-add-msg').textContent = '';
    }

    function openAdd() {
        ['ec-ticket-name', 'ec-holvi-name', 'ec-holvi-email', 'ec-guest-name', 'ec-member', 'ec-new-name', 'ec-new-email'].forEach(function (id) {
            el(id).value = '';
        });
        el('ec-members').textContent = '';
        chosen = null;
        setGuestPlus(0);
        step('choose');
        el('ec-add').showModal();
    }

    function setGuestPlus(value) {
        guestPlus = Math.max(0, Math.min(config.maxPlus, value));
        el('ec-guest-count').textContent = '+' + guestPlus + ' ' + text.extra;
        el('ec-guest-less').disabled = guestPlus <= 0;
        el('ec-guest-more').disabled = guestPlus >= config.maxPlus;
    }

    // An answer that ends the Add: on success the popup closes and the
    // message shows on the page; otherwise it stays open and says why.
    function done(result) {
        apply(result);

        if (result && result.ok) {
            el('ec-add').close();
        }

        say(result ? result.message : text.failed);
    }

    function showChoice(member, message) {
        var credit = el('ec-choice-credit');

        chosen = member;
        step('choice');
        el('ec-choice-name').textContent = member.name;
        credit.textContent = member.credits > 0 ? text.useCredit.replace('%d', member.credits) : text.noCredit;
        credit.disabled = member.credits <= 0;
        say(message);
    }

    function showTasks() {
        var list = el('ec-tasks');

        step('tasks');
        listLoading(list);

        post('tasks', { person: chosen.id }).then(function (data) {
            list.textContent = '';

            if (!data || !Array.isArray(data.tasks)) {
                listError(list, data);
                return;
            }

            if (0 === data.tasks.length) {
                list.appendChild(node('li', 'ec-muted', text.noTasks));
                return;
            }

            data.tasks.forEach(function (task) {
                var li = node('li');
                var who = node('div', 'ec-who');
                var join = node('button', 'ec-primary', text.signUp);

                who.appendChild(node('div', 'ec-name', task.label));
                // free is null for a task with no limit: there is no count to show.
                if (null !== task.free) {
                    who.appendChild(node('div', 'ec-detail', task.free + ' ' + text.free));
                }
                // A task they hold shows as signed up. After a signup the
                // popup stays open, so the same person can take another task;
                // Close ends it.
                var signed = node('span', 'ec-detail', text.signedUp);

                function work(button, fields) {
                    button.disabled = true;
                    post('work', fields).then(function (result) {
                        apply(result);
                        say(result ? result.message : text.failed);

                        if (result && result.ok) {
                            li.replaceChild(signed, button);
                        } else {
                            button.disabled = false;
                        }
                    }, function (error) {
                        button.disabled = false;
                        failed(error);
                    });
                }

                // A restricted task (Organize, DJ, ...) asks first whether it
                // counts toward free-entry credit: Sign up turns into the
                // question with a Yes and a No button.
                join.addEventListener('click', function () {
                    if (!task.ask_credit) {
                        work(join, { person: chosen.id, task: task.id });
                        return;
                    }

                    var ask = node('div', 'ec-ask');
                    ask.appendChild(node('div', 'ec-detail', text.askCredit));
                    [[text.yes, '1'], [text.no, '0']].forEach(function (pair) {
                        var answer = node('button', 'ec-primary', pair[0]);
                        answer.addEventListener('click', function () {
                            work(ask, { person: chosen.id, task: task.id, counts_credit: pair[1] });
                        });
                        ask.appendChild(answer);
                    });
                    li.replaceChild(ask, join);
                });

                li.appendChild(who);
                li.appendChild(task.mine ? signed : join);
                list.appendChild(li);
            });
        }, listFailed(list, failed));
    }

    // The Other device popup: which step shows, and where Back goes from the
    // QR code (the people list after "Login as", else the first step).
    var otherStep = 'choose';
    var qrFrom = 'choose';

    function sayOther(message) {
        el('ec-other-msg').textContent = message || '';
    }

    function otherShow(name) {
        otherStep = name;
        ['choose', 'people', 'qr'].forEach(function (s) { el('ec-other-' + s).hidden = s !== name; });
        el('ec-other-back').hidden = 'choose' === name;
        sayOther('');

        // A login link stays on screen only while its step shows.
        if ('qr' !== name) {
            el('ec-qr').textContent = '';
            el('ec-qr-link').value = '';
        }
    }

    function otherFailed(error) {
        if (!error || 'session' !== error.message) {
            sayOther(text.failed);
        }
    }

    // The QR code as an SVG: one 1x1 square per dark module, on white with
    // a 4-module quiet zone, so a phone camera reads it in dark mode too.
    function drawQr(url) {
        var holder = el('ec-qr');
        var ns = 'http://www.w3.org/2000/svg';
        var qr, count, size, svg, back, path, d = '', r, c;

        holder.textContent = '';

        if ('function' !== typeof window.qrcode) {
            holder.appendChild(node('p', 'ec-muted', text.noQr));
            return;
        }

        qr = window.qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        count = qr.getModuleCount();
        size = count + 8;

        for (r = 0; r < count; r += 1) {
            for (c = 0; c < count; c += 1) {
                if (qr.isDark(r, c)) {
                    d += 'M' + (c + 4) + ',' + (r + 4) + 'h1v1h-1z';
                }
            }
        }

        svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', text.qrLabel);
        svg.setAttribute('shape-rendering', 'crispEdges');
        back = document.createElementNS(ns, 'rect');
        back.setAttribute('width', size);
        back.setAttribute('height', size);
        back.setAttribute('fill', '#fff');
        path = document.createElementNS(ns, 'path');
        path.setAttribute('d', d);
        path.setAttribute('fill', '#000');
        svg.appendChild(back);
        svg.appendChild(path);
        holder.appendChild(svg);
    }

    // Asks the server for a one-time login link - for this person when
    // person is 0 - and shows it as a QR code and as text.
    function showLink(person, from) {
        post('handoff', { person: person }).then(function (data) {
            if (!data || !data.ok) {
                sayOther(data && data.message ? data.message : text.failed);
                return;
            }

            qrFrom = from;
            otherShow('qr');
            el('ec-qr-title').textContent = 0 === person ? text.ownTitle : text.asTitle.replace('%s', data.name);
            el('ec-qr-text').textContent = 0 === person ? text.scan : text.scan + ' ' + text.asWho.replace('%s', data.name);
            el('ec-qr-link').value = data.url;
            drawQr(data.url);
        }, otherFailed);
    }

    function showPeople() {
        var list = el('ec-people');

        otherShow('people');
        listLoading(list);

        post('people', {}).then(function (data) {
            list.textContent = '';

            if (!data || !Array.isArray(data.people)) {
                listError(list, data);
                return;
            }

            if (0 === data.people.length) {
                list.appendChild(node('li', 'ec-muted', text.noPeople));
                return;
            }

            data.people.forEach(function (person) {
                var li = node('li');
                var who = node('div', 'ec-who');
                var pick = node('button', '', text.showQr);

                who.appendChild(node('div', 'ec-name', person.name));
                pick.addEventListener('click', function () { showLink(person.id, 'people'); });
                li.appendChild(who);
                li.appendChild(pick);
                list.appendChild(li);
            });
        }, listFailed(list, otherFailed));
    }

    // The clipboard API needs a secure page and a permission; when it is not
    // there or says no, the link text is selected for the phone's own copy.
    function copyLink() {
        var input = el('ec-qr-link');

        function select() {
            input.focus();
            input.select();
            input.setSelectionRange(0, input.value.length);
            sayOther(text.selected);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(function () { sayOther(text.copied); }, select);
            return;
        }

        select();
    }

    var searchTimer = null;

    el('ec-other-open').addEventListener('click', function () {
        otherShow('choose');
        el('ec-other').showModal();
    });
    el('ec-other-close').addEventListener('click', function () { el('ec-other').close(); });
    el('ec-other-back').addEventListener('click', function () {
        otherShow('qr' === otherStep ? qrFrom : 'choose');
    });
    el('ec-other').addEventListener('click', function (event) {
        if (event.target === el('ec-other')) {
            el('ec-other').close();
        }
    });
    // Closing the popup in any way removes the link from the page.
    el('ec-other').addEventListener('close', function () { otherShow('choose'); });
    el('ec-pick-own').addEventListener('click', function () { showLink(0, 'choose'); });
    el('ec-pick-as').addEventListener('click', showPeople);
    el('ec-qr-copy').addEventListener('click', copyLink);

    el('ec-filter').addEventListener('input', draw);

    el('ec-add-open').addEventListener('click', openAdd);
    el('ec-add-close').addEventListener('click', function () { el('ec-add').close(); });
    el('ec-add-back').addEventListener('click', function () {
        // Someone already on a list came straight from the search to the
        // tasks, so Back goes to the search.
        var onList = chosen && chosen.list;

        step({ choice: 'member', tasks: onList ? 'member' : 'choice' }[current] || 'choose');
    });
    // A tap on the dimmed area outside the popup lands on the dialog itself.
    el('ec-add').addEventListener('click', function (event) {
        if (event.target === el('ec-add')) {
            el('ec-add').close();
        }
    });
    el('ec-pick-ticket').addEventListener('click', function () { step('ticket'); el('ec-ticket-name').focus(); });
    el('ec-pick-holvi').addEventListener('click', function () { step('holvi'); el('ec-holvi-name').focus(); });
    el('ec-pick-guest').addEventListener('click', function () { step('guest'); el('ec-guest-name').focus(); });
    el('ec-pick-member').addEventListener('click', function () { step('member'); el('ec-member').focus(); });

    el('ec-ticket-add').addEventListener('click', function () {
        post('ticket', { name: el('ec-ticket-name').value.trim() }).then(done, failed);
    });

    el('ec-holvi-add').addEventListener('click', function () {
        post('holvi', {
            name: el('ec-holvi-name').value.trim(),
            email: el('ec-holvi-email').value.trim()
        }).then(done, failed);
    });

    // The count of people let in without a name. Each tap is sent on its
    // own; the answer carries the new state, which redraws the count.
    function countAnonymous(stepBy, button) {
        button.disabled = true;
        post('count', { step: stepBy }).then(apply, failed).then(function () {
            button.disabled = false;
            draw();
        });
    }

    el('ec-anon-more').addEventListener('click', function () { countAnonymous('1', el('ec-anon-more')); });
    el('ec-anon-less').addEventListener('click', function () { countAnonymous('-1', el('ec-anon-less')); });

    el('ec-guest-less').addEventListener('click', function () { setGuestPlus(guestPlus - 1); });
    el('ec-guest-more').addEventListener('click', function () { setGuestPlus(guestPlus + 1); });
    el('ec-guest-add').addEventListener('click', function () {
        var name = el('ec-guest-name').value.trim();

        if ('' === name) {
            say(text.needName);
            return;
        }

        post('guest', { name: name, plus: guestPlus }).then(done, failed);
    });

    el('ec-new-add').addEventListener('click', function () {
        post('newMember', { name: el('ec-new-name').value.trim(), email: el('ec-new-email').value.trim() })
            .then(function (data) {
                if (data && data.ok && data.member) {
                    showChoice(data.member, data.message);
                    return;
                }

                say(data ? data.message : text.failed);
            }, failed);
    });

    el('ec-choice-credit').addEventListener('click', function () {
        post('give', { person: chosen.id }).then(done, failed);
    });
    el('ec-choice-work').addEventListener('click', showTasks);

    el('ec-member').addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(searchMembers, 300);
    });

    // The Scan popup. The camera runs while the popup shows the camera; a
    // code read is sent to the server, which finds the person on tonight's
    // list without marking them.
    var scanStream = null;
    var scanTimer = null;
    var scanRead = null;
    var scanRow = null;
    var scanPlus = 0;
    var scanLast = '';
    var scanLastAt = 0;

    // isError colours the message red, so a failed scan stands out;
    // isWelcome makes it big on green, for a person just marked arrived.
    function scanMessage(message, isError, isWelcome) {
        var line = el('ec-scan-msg');

        line.textContent = message || '';
        line.classList.toggle('is-error', !!message && !!isError);
        line.classList.toggle('is-welcome', !!message && !isError && !!isWelcome);
    }

    // The name on one line: from 32px down to 18px until it fits, then
    // cut with "…".
    function fitScanName() {
        var name = el('ec-scan-name');
        var size = 32;

        name.style.fontSize = size + 'px';

        while (size > 18 && name.scrollWidth > name.clientWidth) {
            size -= 1;
            name.style.fontSize = size + 'px';
        }
    }

    function stopCamera() {
        clearTimeout(scanTimer);
        scanTimer = null;

        if (scanStream) {
            scanStream.getTracks().forEach(function (track) { track.stop(); });
            scanStream = null;
        }

        el('ec-scan-video').srcObject = null;
    }

    // A function that reads the QR code in the video, or '' when there is
    // none: the browser's BarcodeDetector, else the bundled decoder.
    function scanReader() {
        if (scanRead) {
            return Promise.resolve(scanRead);
        }

        var native = 'BarcodeDetector' in window
            ? window.BarcodeDetector.getSupportedFormats().then(function (formats) {
                return -1 !== formats.indexOf('qr_code');
            }, function () { return false; })
            : Promise.resolve(false);

        return native.then(function (supported) {
            if (supported) {
                var detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                scanRead = function (video) {
                    return detector.detect(video).then(function (codes) {
                        return codes.length ? codes[0].rawValue : '';
                    });
                };
                return scanRead;
            }

            return import(config.qrDecodeUrl).then(function (module) {
                var canvas = document.createElement('canvas');
                var context = canvas.getContext('2d', { willReadFrequently: true });

                scanRead = function (video) {
                    // A smaller picture reads faster and a phone camera is
                    // sharp enough for a code that fills part of it.
                    var scale = Math.min(1, 800 / Math.max(video.videoWidth, video.videoHeight));
                    canvas.width = Math.round(video.videoWidth * scale);
                    canvas.height = Math.round(video.videoHeight * scale);
                    context.drawImage(video, 0, 0, canvas.width, canvas.height);

                    try {
                        return Promise.resolve(module.decodeQR(context.getImageData(0, 0, canvas.width, canvas.height)));
                    } catch (error) {
                        return Promise.resolve('');
                    }
                };
                return scanRead;
            });
        });
    }

    function startCamera() {
        el('ec-scan-camera').hidden = false;
        el('ec-scan-result').hidden = true;
        scanRow = null;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            scanMessage(text.noCamera, true);
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
            .then(function (stream) {
                var video = el('ec-scan-video');

                if (!el('ec-scan').open) {
                    stream.getTracks().forEach(function (track) { track.stop(); });
                    return;
                }

                scanStream = stream;
                video.srcObject = stream;
                video.play();

                return scanReader().then(function (read) {
                    scanLoop(read);
                }, function () {
                    stopCamera();
                    scanMessage(text.noDecoder, true);
                });
            }, function () {
                scanMessage(text.noCamera, true);
            });
    }

    function scanLoop(read) {
        var video = el('ec-scan-video');

        if (!scanStream) {
            return;
        }

        var next = function () { scanTimer = setTimeout(function () { scanLoop(read); }, 250); };

        if (video.readyState < 2) {
            next();
            return;
        }

        read(video).then(function (found) {
            // The same code again within a few seconds is the one just
            // answered: still in front of the camera.
            if (!found || (found === scanLast && Date.now() - scanLastAt < 4000)) {
                next();
                return;
            }

            scanLast = found;
            scanLastAt = Date.now();
            stopCamera();
            post('scan', { text: found }).then(showScan, function (error) {
                failed(error);
                startCamera();
            });
        }, next);
    }

    function findRow(key) {
        var found = null;

        state.sections.forEach(function (section) {
            section.rows.forEach(function (row) {
                if (row.key === key) { found = row; }
            });
        });

        return found;
    }

    function showScan(data) {
        apply(data);
        scanRow = data && data.ok ? findRow(data.key) : null;

        if (!scanRow) {
            scanMessage(data ? data.message : text.failed, true);
            startCamera();
            return;
        }

        scanMessage('');
        el('ec-scan-camera').hidden = true;
        el('ec-scan-result').hidden = false;
        el('ec-scan-name').textContent = scanRow.name;
        fitScanName();
        el('ec-scan-detail').textContent = scanRow.detail || '';
        el('ec-scan-already').hidden = !scanRow.in;
        el('ec-scan-plus').hidden = scanRow.max <= 0;
        el('ec-scan-mark').disabled = false;
        // Someone already in keeps the guests counted for them; anyone else
        // starts at none.
        setScanPlus(scanRow.in ? scanRow.plus : 0);
    }

    function setScanPlus(value) {
        scanPlus = Math.max(0, Math.min(scanRow ? scanRow.max : 0, value));
        el('ec-scan-count').textContent = '+' + scanPlus + ' ' + text.guests;
        el('ec-scan-less').disabled = scanPlus <= 0;
        el('ec-scan-more').disabled = !scanRow || scanPlus >= scanRow.max;
    }

    el('ec-scan-open').addEventListener('click', function () {
        scanMessage('');
        scanLast = '';
        el('ec-scan').showModal();
        startCamera();
    });
    el('ec-scan-close').addEventListener('click', function () { el('ec-scan').close(); });
    el('ec-scan').addEventListener('close', function () {
        stopCamera();
        scanRow = null;
    });
    el('ec-scan-less').addEventListener('click', function () { setScanPlus(scanPlus - 1); });
    el('ec-scan-more').addEventListener('click', function () { setScanPlus(scanPlus + 1); });
    el('ec-scan-next').addEventListener('click', function () {
        scanMessage('');
        startCamera();
    });
    el('ec-scan-mark').addEventListener('click', function () {
        var row = scanRow;

        el('ec-scan-mark').disabled = true;
        post('mark', { kind: row.kind, ref: row.ref, in: '1', plus: scanPlus }).then(function (data) {
            apply(data);

            if (data && data.ok) {
                // Ready for the next person in the queue.
                startCamera();
                scanMessage(text.arrived.replace('%s', row.name), false, true);
                return;
            }

            el('ec-scan-mark').disabled = false;
            scanMessage(text.failed, true);
        }, function (error) {
            el('ec-scan-mark').disabled = false;
            failed(error);
        });
    });

    // No polling while the tab is hidden; one fresh read as soon as it shows.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            poll();
        }
    });

    draw();
    timer = setTimeout(poll, config.pollMs);
}());
</script>
</body>
</html>
