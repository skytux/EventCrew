/*
 * EventCrew background jobs: while the bar at the top says jobs are
 * running, ask admin-ajax every few seconds. Each answer runs one job on the
 * server and returns the new badges, so the page shows "Done" or "Failed"
 * without a reload. "Try again" and the reload links work here too.
 */
(function () {
    'use strict';

    var config = window.eventcrewJobs;
    if (!config || !window.fetch || !window.FormData) {
        return;
    }

    var busy = false;
    var timer = null;

    function ids() {
        var out = [];
        document.querySelectorAll('[data-ec-job]').forEach(function (node) {
            out.push(node.getAttribute('data-ec-job'));
        });
        return out;
    }

    function bar() {
        return document.querySelector('[data-ec-jobs-bar]');
    }

    function showBar(active, text) {
        var node = bar();
        if (!node) {
            return;
        }
        node.setAttribute('data-active', String(active));
        var line = node.querySelector('p');
        line.textContent = text + ' ';
        if (0 === active) {
            node.classList.remove('notice-info');
            node.classList.add('notice-success');
            var link = document.createElement('a');
            link.href = '#';
            link.className = 'ec-job-reload';
            link.textContent = config.reload;
            line.appendChild(link);
        }
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(function () { poll(''); }, 3000);
    }

    function poll(retry) {
        if (busy) {
            return;
        }
        busy = true;

        var body = new FormData();
        body.append('action', config.action);
        body.append('_ajax_nonce', config.nonce);
        body.append('ids', ids().join(','));
        if (retry) {
            body.append('retry', retry);
        }

        fetch(config.url, {method: 'POST', credentials: 'same-origin', body: body})
            .then(function (response) { return response.json(); })
            .then(function (answer) {
                busy = false;
                if (!answer || !answer.success) {
                    return;
                }
                var jobs = answer.data.jobs || {};
                Object.keys(jobs).forEach(function (id) {
                    var node = document.querySelector('[data-ec-job="' + id + '"]');
                    if (node && jobs[id].html) {
                        node.outerHTML = jobs[id].html;
                    }
                });
                showBar(answer.data.active, answer.data.bar);
                if (answer.data.active > 0) {
                    schedule();
                }
            })
            .catch(function () {
                busy = false;
                schedule();
            });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var reload = target.closest('.ec-job-reload');
        if (reload) {
            event.preventDefault();
            window.location.reload();
            return;
        }
        var retry = target.closest('[data-ec-retry]');
        if (retry) {
            event.preventDefault();
            var id = retry.getAttribute('data-ec-retry');
            var badge = retry.closest('[data-ec-job]');
            if (badge) {
                badge.textContent = '…';
            }
            clearTimeout(timer);
            busy = false;
            poll(id);
        }
    });

    var start = bar();
    if (start && Number(start.getAttribute('data-active')) > 0) {
        poll('');
    }
})();
