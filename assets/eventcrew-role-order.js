/*
 * EventCrew role order: drag and drop for the role list on the Crew app's
 * Roles card and on the wp-admin Settings > Roles tab.
 *
 * Markup (both pages):
 *   [data-eventcrew-role-order]           the list; data-url is where to
 *                                         post, data-fields a JSON object of
 *                                         the fields every save carries
 *                                         (action, csrf or nonce), data-error
 *                                         the text for a failed save
 *   [data-eventcrew-role-slug]            one role (a direct child)
 *   [data-eventcrew-role-handle]          its ☰ handle, a hidden button
 *   [data-eventcrew-role-arrow]           its ↑ / ↓ fallback (no JavaScript)
 *   [data-eventcrew-role-order-status]    where "Role order saved." shows
 *
 * With this script running the handles show and the arrows hide. A row is
 * dragged by its handle with a mouse, a finger or a pen (Pointer Events);
 * only the handle has touch-action: none, so the page still scrolls
 * elsewhere. The rows reorder while dragging and the page scrolls near the
 * top and bottom of the screen. On a focused handle, ↑ / ↓ move the row.
 *
 * Each change saves the whole order in one request (order[] = every slug)
 * and the page stays. When the save fails the list goes back as it was and
 * the error shows.
 */
(function () {
    'use strict';

    if (!window.fetch || !window.FormData) {
        return;
    }

    var EDGE = 48;

    function setup(list) {
        if (list.getAttribute('data-eventcrew-role-ready')) {
            return;
        }
        list.setAttribute('data-eventcrew-role-ready', '1');

        var fields = {};
        try {
            fields = JSON.parse(list.getAttribute('data-fields') || '{}');
        } catch (e) {
            return;
        }

        var url = list.getAttribute('data-url');
        var errorText = list.getAttribute('data-error') || '';
        var status = list.parentNode.querySelector('[data-eventcrew-role-order-status]');
        var busy = false;
        var drag = null;
        var statusTimer = null;
        var justDragged = false;

        list.classList.add('is-dragdrop');
        list.querySelectorAll('[data-eventcrew-role-handle]').forEach(function (handle) {
            handle.hidden = false;
        });

        function items() {
            return [].slice.call(list.children).filter(function (node) {
                return node.hasAttribute('data-eventcrew-role-slug');
            });
        }

        function order() {
            return items().map(function (node) {
                return node.getAttribute('data-eventcrew-role-slug');
            });
        }

        function sibling(node, forward) {
            var next = forward ? node.nextElementSibling : node.previousElementSibling;
            while (next && !next.hasAttribute('data-eventcrew-role-slug')) {
                next = forward ? next.nextElementSibling : next.previousElementSibling;
            }
            return next;
        }

        // Moves the neighbour rather than the row itself, so the row being
        // dragged (and its focused handle) never leaves the page.
        function stepDown(row) {
            var next = sibling(row, true);
            if (!next) {
                return false;
            }
            list.insertBefore(next, row);
            return true;
        }

        function stepUp(row) {
            var prev = sibling(row, false);
            if (!prev) {
                return false;
            }
            list.insertBefore(prev, row.nextSibling);
            return true;
        }

        function restore(slugs) {
            var rows = items();
            var after = rows.length ? rows[rows.length - 1].nextSibling : null;
            slugs.forEach(function (slug) {
                rows.forEach(function (row) {
                    if (row.getAttribute('data-eventcrew-role-slug') === slug) {
                        list.insertBefore(row, after);
                    }
                });
            });
        }

        function show(text, failed) {
            if (!status) {
                return;
            }
            window.clearTimeout(statusTimer);
            status.textContent = text;
            status.classList.toggle('is-error', !!failed);
            if (!failed && text) {
                statusTimer = window.setTimeout(function () { status.textContent = ''; }, 4000);
            }
        }

        function save(before) {
            var now = order();
            if (now.join(' ') === before.join(' ')) {
                return;
            }

            var body = new FormData();
            Object.keys(fields).forEach(function (key) {
                body.append(key, fields[key]);
            });
            body.append('ajax', '1');
            now.forEach(function (slug) {
                body.append('order[]', slug);
            });

            busy = true;
            list.classList.add('is-saving');
            show('', false);

            fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) {
                    return response.json().catch(function () { return null; });
                })
                .catch(function () { return null; })
                .then(function (answer) {
                    busy = false;
                    list.classList.remove('is-saving');
                    var notice = answer && answer.data && answer.data.notice ? answer.data.notice : '';
                    if (answer && answer.success) {
                        show(notice, false);
                        return;
                    }
                    restore(before);
                    show(notice || errorText, true);
                });
        }

        // Puts the dragged row where the pointer is: past every neighbour
        // whose middle it has crossed.
        function place(y) {
            var row = drag.row;
            var next = sibling(row, true);
            var prev = sibling(row, false);
            var box;

            while (next) {
                box = next.getBoundingClientRect();
                if (y <= box.top + box.height / 2) {
                    break;
                }
                stepDown(row);
                next = sibling(row, true);
            }

            while (prev) {
                box = prev.getBoundingClientRect();
                if (y >= box.top + box.height / 2) {
                    break;
                }
                stepUp(row);
                prev = sibling(row, false);
            }
        }

        function scrollLoop() {
            if (!drag) {
                return;
            }
            var y = drag.y;
            var speed = 0;
            if (y < EDGE) {
                speed = -Math.ceil((EDGE - y) / 3);
            } else if (y > window.innerHeight - EDGE) {
                speed = Math.ceil((y - (window.innerHeight - EDGE)) / 3);
            }
            if (speed) {
                window.scrollBy(0, speed);
                place(y);
            }
            drag.frame = window.requestAnimationFrame(scrollLoop);
        }

        function onMove(event) {
            if (!drag || event.pointerId !== drag.pointer) {
                return;
            }
            event.preventDefault();
            drag.y = event.clientY;
            place(event.clientY);
        }

        function onEnd(event) {
            if (!drag || event.pointerId !== drag.pointer) {
                return;
            }
            var done = drag;
            drag = null;
            window.cancelAnimationFrame(done.frame);
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onEnd);
            window.removeEventListener('pointercancel', onEnd);
            done.row.classList.remove('is-dragging');
            list.classList.remove('is-dragging');
            // The click that ends a drag must not open the role it lands on.
            justDragged = true;
            window.setTimeout(function () { justDragged = false; }, 0);
            save(done.before);
        }

        list.addEventListener('pointerdown', function (event) {
            var handle = event.target.closest('[data-eventcrew-role-handle]');
            if (!handle || drag || busy || (event.pointerType === 'mouse' && event.button !== 0)) {
                return;
            }
            var row = handle.closest('[data-eventcrew-role-slug]');
            if (!row || row.parentNode !== list) {
                return;
            }
            event.preventDefault();
            drag = { row: row, pointer: event.pointerId, y: event.clientY, before: order(), frame: 0 };
            row.classList.add('is-dragging');
            list.classList.add('is-dragging');
            window.addEventListener('pointermove', onMove, { passive: false });
            window.addEventListener('pointerup', onEnd);
            window.addEventListener('pointercancel', onEnd);
            drag.frame = window.requestAnimationFrame(scrollLoop);
        });

        // A handle is a button inside a form (Crew app) or a <summary>
        // (wp-admin): pressing it must neither post nor open the role.
        list.addEventListener('click', function (event) {
            if (justDragged || event.target.closest('[data-eventcrew-role-handle]')) {
                event.preventDefault();
                justDragged = false;
            }
        }, true);

        list.addEventListener('keydown', function (event) {
            var handle = event.target.closest('[data-eventcrew-role-handle]');
            if (!handle || busy || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) {
                return;
            }
            event.preventDefault();
            var row = handle.closest('[data-eventcrew-role-slug]');
            var before = order();
            if (event.key === 'ArrowUp' ? stepUp(row) : stepDown(row)) {
                handle.focus();
                save(before);
            }
        });
    }

    function init() {
        document.querySelectorAll('[data-eventcrew-role-order]').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // The Crew app draws some tabs after the page opens (and again when they
    // change): set up the lists that arrive then too.
    document.addEventListener('eventcrew:panel', init);
})();
