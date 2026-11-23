/* Windowsill: swaps a pending dashboard weather widget for a freshly fetched one (same origin only). */
(function (window, document) {
    'use strict';

    var UNAVAILABLE = 'Weather is unavailable right now.';
    var FAILED = "Couldn't refresh.";
    // A held-back refresh (data-windowsill-retry-after) is retried at most this many times per page load.
    var MAX_RETRIES = 2;
    var retries = 0;

    // Parse the response in a detached <template>; accept exactly one top-level section.windowsill.
    function parseFragment(doc, text) {
        var tpl = doc.createElement('template');
        tpl.innerHTML = text;
        var children = tpl.content.children;
        if (children.length !== 1 || !children[0].matches || !children[0].matches('section.windowsill')) {
            return null;
        }
        return children[0];
    }

    function fail(doc, el) {
        if (el.querySelector('.windowsill-now')) {
            var status = doc.createElement('p');
            status.className = 'windowsill-status';
            status.textContent = FAILED;
            el.appendChild(status);
        } else {
            el.textContent = UNAVAILABLE;
        }
    }

    function refreshOne(doc, el, fetchFn) {
        var url;
        try {
            url = new URL(el.getAttribute('data-windowsill-refresh'), doc.location.href);
        } catch (e) {
            return Promise.resolve();
        }
        if (url.origin !== doc.location.origin) {
            return Promise.resolve();
        }

        return Promise.resolve()
            .then(function () {
                return fetchFn(url.href, {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
            })
            .then(function (response) {
                // A redirect means the session expired and we landed on the login page.
                if (!response.ok || response.redirected) {
                    throw new Error('Refresh failed');
                }
                return response.text();
            })
            .then(function (text) {
                var fresh = parseFragment(doc, text);
                if (!fresh) {
                    throw new Error('Unexpected refresh response');
                }
                el.parentNode.replaceChild(fresh, el);
                scheduleRetry(doc, fresh, fetchFn);
            })
            .catch(function () {
                fail(doc, el);
            });
    }

    function scheduleRetry(doc, el, fetchFn) {
        var seconds = parseInt(el.getAttribute('data-windowsill-retry-after'), 10);
        if (el.getAttribute('data-windowsill-refresh') === null || !(seconds >= 1 && seconds <= 60) || retries >= MAX_RETRIES) {
            return;
        }
        retries++;
        window.setTimeout(function () { return refreshOne(doc, el, fetchFn); }, seconds * 1000);
    }

    function refreshAll(doc, fetchFn) {
        var els = Array.prototype.slice.call(doc.querySelectorAll('[data-windowsill-refresh]'));
        return Promise.all(els.map(function (el) { return refreshOne(doc, el, fetchFn); }));
    }

    window.KBWindowsill = { refreshAll: refreshAll };

    function start() {
        if (typeof window.fetch !== 'function') {
            return;
        }
        refreshAll(document, function (url, options) { return window.fetch(url, options); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}(window, document));
