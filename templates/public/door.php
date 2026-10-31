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
 * @var array<string, mixed> $eventcrew_state  DoorDesk::state() for the night
 * @var array<string, mixed> $eventcrew_config ajaxUrl, csrf, pollMs, searchMin, maxPlus, actions
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_text = [
    'in' => __('✓ In', 'eventcrew'),
    'out' => __('Check in', 'eventcrew'),
    'guests' => __('guests', 'eventcrew'),
    'credits' => __('credits', 'eventcrew'),
    'noMatch' => __('No member matches. Add them below as a new member.', 'eventcrew'),
    'choose' => __('Choose', 'eventcrew'),
    /* translators: %s: the list name, "Crew working" or "Free-entry credit" */
    'onList' => __('Already on tonight’s list: %s', 'eventcrew'),
    'swap' => __('Take a task instead', 'eventcrew'),
    /* translators: %d: how many free-entry credits the member has */
    'useCredit' => __('Use a credit (%d left)', 'eventcrew'),
    'noCredit' => __('No credit to use', 'eventcrew'),
    'noTasks' => __('No task tonight has a free place.', 'eventcrew'),
    'free' => __('free', 'eventcrew'),
    'signUp' => __('Sign up', 'eventcrew'),
    'needName' => __('Give the guest’s name.', 'eventcrew'),
    'extra' => __('extra', 'eventcrew'),
    'empty' => __('Nobody on this list.', 'eventcrew'),
    'failed' => __('That did not go through. Check the connection and try again.', 'eventcrew'),
    'fewer' => __('One guest fewer', 'eventcrew'),
    'more' => __('One guest more', 'eventcrew'),
    /* translators: %d: how many people were counted in without a name tonight */
    'anonymous' => __('Counted without a name: %d', 'eventcrew'),
];
?><!doctype html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php esc_html_e('Sales desk', 'eventcrew'); ?></title>
<style>
:root{--fg:#1d1d1f;--muted:#6e6e73;--bg:#fff;--card:#f5f5f7;--line:#d2d2d7;--in:#1a7f37;--in-bg:#dafbe1;--accent:#0b57d0}
@media (prefers-color-scheme:dark){:root{--fg:#f5f5f7;--muted:#a1a1a6;--bg:#111;--card:#1c1c1e;--line:#38383a;--in:#4ac26b;--in-bg:#12361e;--accent:#8ab4f8}}
*{box-sizing:border-box}
body{margin:0;font:16px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--fg);background:var(--bg)}
header{position:sticky;top:0;z-index:2;background:var(--bg);border-bottom:1px solid var(--line);padding:10px 16px}
h1{font-size:18px;margin:0}
.ec-count{font-size:28px;font-weight:700;font-variant-numeric:tabular-nums}
.ec-count small{font-size:16px;font-weight:400;color:var(--muted)}
input[type=search]{width:100%;font:inherit;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--fg);margin-top:8px}
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
.ec-toggle.is-in{background:var(--in-bg);border-color:var(--in);color:var(--in);font-weight:700}
.ec-msg{margin:8px 0;padding:8px 12px;border-radius:10px;background:var(--card)}
.ec-msg:empty{display:none}
.ec-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.ec-anon{display:flex;align-items:center;gap:8px;margin-top:8px}
.ec-anon span{color:var(--muted);font-variant-numeric:tabular-nums}
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
[hidden]{display:none!important}
</style>
</head>
<body>
<header>
    <h1 id="ec-day"></h1>
    <div class="ec-top">
    <div class="ec-count" aria-live="polite"><span id="ec-in">0</span> <small><?php
        /* translators: %s: the number of people expected tonight. */
        printf(esc_html__('of %s checked in', 'eventcrew'), '<span id="ec-expected">0</span>');
    ?></small></div>
    <button type="button" class="ec-primary" id="ec-add-open"><?php esc_html_e('Add', 'eventcrew'); ?></button>
    </div>
    <div class="ec-anon">
        <button type="button" id="ec-anon-less" aria-label="<?php esc_attr_e('Take one person off the count without a name', 'eventcrew'); ?>">−1</button>
        <button type="button" id="ec-anon-more" aria-label="<?php esc_attr_e('Count one person in without a name', 'eventcrew'); ?>">+1</button>
        <span id="ec-anon-count"></span>
    </div>
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

    function node(tag, cls, content) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (undefined !== content) { n.textContent = content; }
        return n;
    }

    function post(action, fields) {
        var body = new URLSearchParams();
        body.append('action', config.actions[action]);
        body.append('csrf', config.csrf);
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });

        return fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(answer);
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
        el(el('ec-add').open ? 'ec-add-msg' : 'ec-msg').textContent = message || '';
    }

    function plusOf(row) {
        return row.in || undefined === localPlus[row.key] ? row.plus : localPlus[row.key];
    }

    function draw() {
        var query = el('ec-filter').value.trim().toLowerCase();
        var holder = el('ec-sections');

        el('ec-day').textContent = state.day;
        el('ec-in').textContent = state.in;
        el('ec-expected').textContent = state.expected;
        el('ec-anon-count').textContent = text.anonymous.replace('%d', state.anonymous);
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
        var toggle = node('button', 'ec-toggle' + (row.in ? ' is-in' : ''), row.in ? text.in : text.out);

        who.appendChild(node('div', 'ec-name', row.name));
        if (row.detail) { who.appendChild(node('div', 'ec-detail', row.detail)); }

        // Crew and free-entry credit rows have no guest count at all (their
        // max is 0); a guest can bring as many as they are allowed.
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

    function searchMembers() {
        var query = el('ec-member').value.trim();
        var list = el('ec-members');

        if (query.length < config.searchMin) {
            list.textContent = '';
            return;
        }

        post('search', { q: query }).then(function (data) {
            list.textContent = '';

            if (!data.members || 0 === data.members.length) {
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
                // have nothing to add; a free-entry credit holder can still
                // take a task, which gives their credit back.
                if (member.list) {
                    who.appendChild(node('div', 'ec-detail', text.onList.replace('%s', member.list)));

                    if ('credit' === member.kind) {
                        pick.textContent = text.swap;
                        pick.addEventListener('click', function () { chosen = member; showTasks(); });
                        li.appendChild(pick);
                    }

                    return;
                }

                who.appendChild(node('div', 'ec-detail', member.credits + ' ' + text.credits));
                pick.addEventListener('click', function () { showChoice(member); });
                li.appendChild(pick);
            });
        }, failed);
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

        post('tasks', { person: chosen.id }).then(function (data) {
            step('tasks');
            list.textContent = '';

            if (!data.tasks || 0 === data.tasks.length) {
                list.appendChild(node('li', 'ec-muted', text.noTasks));
                return;
            }

            data.tasks.forEach(function (task) {
                var li = node('li');
                var who = node('div', 'ec-who');
                var join = node('button', 'ec-primary', text.signUp);

                who.appendChild(node('div', 'ec-name', task.label));
                who.appendChild(node('div', 'ec-detail', task.free + ' ' + text.free));
                join.addEventListener('click', function () {
                    join.disabled = true;
                    post('work', { person: chosen.id, task: task.id }).then(done, failed);
                });

                li.appendChild(who);
                li.appendChild(join);
                list.appendChild(li);
            });
        }, failed);
    }

    var searchTimer = null;

    el('ec-filter').addEventListener('input', draw);

    el('ec-add-open').addEventListener('click', openAdd);
    el('ec-add-close').addEventListener('click', function () { el('ec-add').close(); });
    el('ec-add-back').addEventListener('click', function () {
        var swapping = chosen && 'credit' === chosen.kind;

        step({ choice: 'member', tasks: swapping ? 'member' : 'choice' }[current] || 'choose');
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
