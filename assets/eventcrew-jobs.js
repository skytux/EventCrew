/*
 * EventCrew background jobs: while the bar at the top says jobs are
 * running, ask admin-ajax every few seconds. Each answer runs one job on the
 * server and returns the new badges, so the page shows "Done" or "Failed"
 * without a reload. "Try again" and the reload links work here too.
 *
 * A finished translation comes with the new text: it is put in its field
 * (.ec-translated[data-ec-row]) and the badge says "Done" with no reload.
 *
 * A form marked data-ec-ajax-save (the Messages page) is posted to
 * admin-ajax instead of leaving the page: the answer comes at once (the
 * translation only waits in the queue), the field shows "Translating…",
 * and the poll starts. When the post fails, the form is posted as usual.
 */
(function () {
    'use strict';

    var config = window.eventcrewJobs;
    if (!config || !window.fetch || !window.FormData) {
        return;
    }

    var busy = false;
    var timer = null;
    var pending = false;

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
        if (0 === active && !document.querySelector('[data-ec-job] .ec-job-reload')) {
            // Every result is already on the page: nothing to reload for.
            node.parentNode.removeChild(node);
            return;
        }
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

    // Puts a finished translation in its field. False when the field is not on this page.
    function fill(row, value) {
        var box = null;
        document.querySelectorAll('.ec-translated[data-ec-row]').forEach(function (node) {
            if (node.getAttribute('data-ec-row') === row) {
                box = node;
            }
        });
        if (!box || !value) {
            return false;
        }
        var set = function (field, text) {
            if (field && field.value !== text) {
                field.value = text;
                field.dispatchEvent(new Event('input', {bubbles: true}));
            }
        };
        set(box.querySelector('[name$="[source]"]'), value.source || '');
        // The language menu as the field draws it: the language picked by
        // hand, or the first entry naming the detected one.
        var menu = box.querySelector('select.ec-translated-lang');
        if (menu) {
            if (menu.options.length && value.menu) {
                menu.options[0].textContent = value.menu;
            }
            menu.value = value.by_hand || '';
        }
        var texts = value.texts || {};
        var manual = value.manual || {};
        // Every language field gets what is stored, '' when nothing is, and
        // the "edited by hand" mark exactly where it is stored.
        box.querySelectorAll('.ec-translated-text[data-ec-lang]').forEach(function (part) {
            var lang = part.getAttribute('data-ec-lang');
            var has = Object.prototype.hasOwnProperty.call(texts, lang);
            set(part.querySelector('[name$="[texts][' + lang + ']"]'), has ? texts[lang] : '');
            part.querySelectorAll('label + em').forEach(function (mark) {
                mark.parentNode.removeChild(mark);
            });
            var label = part.querySelector('label');
            if (manual[lang] && label && value.mark) {
                var em = document.createElement('em');
                em.textContent = '(' + value.mark + ')';
                if (!label.nextSibling || 3 !== label.nextSibling.nodeType) {
                    label.parentNode.insertBefore(document.createTextNode(' '), label.nextSibling);
                }
                label.parentNode.insertBefore(em, label.nextSibling.nextSibling);
            }
            part.hidden = lang === value.lang && !manual[lang];
        });
        box.classList.remove('ec-translating');
        return true;
    }

    // Polls now, or right after the poll that is under way.
    function start() {
        clearTimeout(timer);
        if (busy) {
            pending = true;
            return;
        }
        poll('');
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
                    var job = jobs[id];
                    if (!node || !job.html) {
                        return;
                    }
                    node.outerHTML = job.row && job.done && fill(job.row, job.value) ? job.done : job.html;
                });
                showBar(answer.data.active, answer.data.bar);
                if (answer.data.active > 0 || pending) {
                    pending = false;
                    schedule();
                }
            })
            .catch(function () {
                busy = false;
                schedule();
            });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ec-ajax-save')) {
            return;
        }
        event.preventDefault();
        var buttons = form.querySelectorAll('[type=submit]');
        var notice = form.querySelector('[data-ec-ajax-notice]');
        buttons.forEach(function (button) { button.disabled = true; });
        var done = function () {
            buttons.forEach(function (button) { button.disabled = false; });
        };
        var fallback = function () {
            done();
            HTMLFormElement.prototype.submit.call(form);
        };

        fetch(config.url, {method: 'POST', credentials: 'same-origin', body: new FormData(form)})
            .then(function (response) { return response.json(); })
            .then(function (answer) {
                if (!answer || !answer.success) {
                    fallback();
                    return;
                }
                done();
                if (notice) {
                    notice.innerHTML = '';
                    if (answer.data.message) {
                        var box = document.createElement('div');
                        box.className = 'notice inline notice-' + (answer.data.type || 'success');
                        var line = document.createElement('p');
                        line.textContent = answer.data.message;
                        box.appendChild(line);
                        notice.appendChild(box);
                    }
                }
                // "Translate again" was sent with this post; a later save must not send it again.
                form.querySelectorAll('input[type=hidden][name$="[again]"]').forEach(function (mark) {
                    mark.value = '';
                });
                var jobs = answer.data.jobs || {};
                var texts = answer.data.texts || {};
                var any = false;
                form.querySelectorAll('.ec-translated[data-ec-row]').forEach(function (node) {
                    var row = node.getAttribute('data-ec-row');
                    var html = jobs[row];
                    if (!html) {
                        if (texts[row]) {
                            fill(row, texts[row]);
                        }
                        return;
                    }
                    var slot = node.querySelector('.ec-translated-job');
                    if (slot) {
                        slot.innerHTML = html;
                    }
                    node.classList.add('ec-translating');
                    any = true;
                });
                if (any) {
                    start();
                }
            })
            .catch(fallback);
    });

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

    // Not named start: that would replace start() above, and a save that
    // queues a job would then fail and post the form a second time.
    var first = bar();
    if (first && Number(first.getAttribute('data-active')) > 0) {
        poll('');
    }
})();
