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
 * @var array<string, mixed> $eventcrew_state  DoorDesk::state() for the night
 * @var array<string, mixed> $eventcrew_config ajaxUrl, csrf, pollMs, searchMin, maxPlus, actions
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_text = [
    'in' => __('In', 'eventcrew'),
    'out' => __('Not in', 'eventcrew'),
    'give' => __('Give ticket', 'eventcrew'),
    'credits' => __('credits', 'eventcrew'),
    'noMatch' => __('No member with credit matches.', 'eventcrew'),
    'empty' => __('Nobody on this list.', 'eventcrew'),
    'failed' => __('That did not go through. Check the connection and try again.', 'eventcrew'),
    'fewer' => __('One fewer', 'eventcrew'),
    'more' => __('One more', 'eventcrew'),
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
.ec-plus span{min-width:2.2em;text-align:center;font-variant-numeric:tabular-nums}
.ec-toggle.is-in{background:var(--in-bg);border-color:var(--in);color:var(--in);font-weight:700}
.ec-msg{margin:8px 0;padding:8px 12px;border-radius:10px;background:var(--card)}
.ec-msg:empty{display:none}
.ec-give{margin-top:28px;padding-top:4px;border-top:2px solid var(--line)}
.ec-muted{color:var(--muted)}
</style>
</head>
<body>
<header>
    <h1 id="ec-day"></h1>
    <div class="ec-count" aria-live="polite"><span id="ec-in">0</span> <small>/ <span id="ec-expected">0</span> <?php esc_html_e('in', 'eventcrew'); ?></small></div>
    <input type="search" id="ec-filter" placeholder="<?php esc_attr_e('Find a name on the list', 'eventcrew'); ?>" autocomplete="off">
</header>
<main>
    <p class="ec-msg" id="ec-msg" role="status"></p>
    <div id="ec-sections"></div>

    <section class="ec-give">
        <h2><?php esc_html_e('Give ticket', 'eventcrew'); ?></h2>
        <p class="ec-muted"><?php esc_html_e('Spend one of a member’s credits on tonight. Search by name or email.', 'eventcrew'); ?></p>
        <input type="search" id="ec-member" placeholder="<?php esc_attr_e('Member name or email', 'eventcrew'); ?>" autocomplete="off">
        <ul id="ec-members"></ul>
    </section>
</main>
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

    function say(message) {
        el('ec-msg').textContent = message || '';
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

        less.setAttribute('aria-label', text.fewer);
        more.setAttribute('aria-label', text.more);
        less.disabled = count <= 0;
        more.disabled = count >= config.maxPlus;
        less.addEventListener('click', function () { setPlus(row, count - 1); });
        more.addEventListener('click', function () { setPlus(row, count + 1); });
        plus.appendChild(less);
        plus.appendChild(node('span', '', '+' + count));
        plus.appendChild(more);

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
        value = Math.max(0, Math.min(config.maxPlus, value));

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
                var give = node('button', '', text.give);

                who.appendChild(node('div', 'ec-name', member.name));
                who.appendChild(node('div', 'ec-detail', member.credits + ' ' + text.credits));
                give.addEventListener('click', function () {
                    give.disabled = true;
                    post('give', { person: member.id }).then(function (result) {
                        apply(result);
                        say(result.message);
                        searchMembers();
                    }, failed);
                });

                li.appendChild(who);
                li.appendChild(give);
                list.appendChild(li);
            });
        }, failed);
    }

    var searchTimer = null;

    el('ec-filter').addEventListener('input', draw);
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
