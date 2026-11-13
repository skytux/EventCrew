<?php

declare(strict_types=1);

namespace EventCrew\Web;

/**
 * The service worker's part for app notifications (Web Push), appended to
 * the Crew app's service worker (PwaController).
 *
 * - push: shows the notification PushNotifier sent (title, body, icon,
 *   where a tap goes; a tag per notice type, so a newer one of a type
 *   replaces the older on the lock screen, renotify so it still sounds).
 * - notificationclick: brings an open Crew app window forward and takes it
 *   to the address, else opens a new one.
 * - message "eventcrew-push-subscribe": subscribes with the key the page
 *   sends and answers on the message's port ({ok, sub} or {ok: false, name,
 *   message}). A worker's subscribe() only checks the permission, where a
 *   page's asks for it again; in an app installed from Samsung Internet that
 *   question never gets an answer, so the page subscribe() hangs
 *   (signup-push.php asks here when it does).
 *
 * Static script text, no user data: what varies arrives in the push.
 */
final class PushServiceWorker
{
    public static function js(): string
    {
        return <<<'JS'

        self.addEventListener('push', function (e) {
            var d = {};
            try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
            var o = { body: d.body || '', data: { url: d.url || self.registration.scope } };
            if (d.icon) { o.icon = d.icon; }
            if (d.tag) { o.tag = d.tag; o.renotify = true; }
            e.waitUntil(self.registration.showNotification(d.title || '', o));
        });
        self.addEventListener('message', function (e) {
            var d = e.data || {};
            var port = e.ports && e.ports[0];
            if ('eventcrew-push-subscribe' !== d.type || !port) { return; }
            e.waitUntil(
                self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: d.key }).then(
                    function (sub) { port.postMessage({ ok: true, sub: sub.toJSON() }); },
                    function (err) {
                        port.postMessage({
                            ok: false,
                            name: (err && err.name) || 'Error',
                            message: (err && err.message) || ''
                        });
                    }
                )
            );
        });
        self.addEventListener('notificationclick', function (e) {
            e.notification.close();
            var url = (e.notification.data && e.notification.data.url) || self.registration.scope;
            e.waitUntil(
                self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
                    for (var i = 0; i < list.length; i++) {
                        var c = list[i];
                        if (c.url.indexOf(self.registration.scope) === 0 && 'focus' in c) {
                            return c.focus().then(function (w) { return w && w.navigate ? w.navigate(url) : w; });
                        }
                    }
                    return self.clients.openWindow ? self.clients.openWindow(url) : null;
                })
            );
        });
        JS;
    }
}
