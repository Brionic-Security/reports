/**
 * Brionic Reports — tracker.
 *
 * Usage (add to any page):
 *   <script defer data-site="site_xxxxx" src="https://reports.example.com/b.js"></script>
 *
 * Sends a privacy-friendly page view on load and on SPA route changes, plus
 * custom events via  window.brionic('event','name')  or  data-br-event="name".
 * Uses a "simple" text/plain beacon so there is no CORS preflight.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  if (!script) return;
  var site = script.getAttribute('data-site');
  if (!site) return;

  // How the tracker was installed (e.g. the WordPress plugin sets "wordpress").
  var via = script.getAttribute('data-via') || '';

  // Endpoint = same origin the script was served from.
  var endpoint = new URL(script.src).origin + '/collect';

  function send(payload) {
    payload.s = site;
    if (via) payload.via = via;
    var body = JSON.stringify(payload);
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }));
        return;
      }
    } catch (e) {}
    try {
      fetch(endpoint, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'text/plain' } });
    } catch (e) {}
  }

  function pageview() {
    // Ignore localhost / non-http pages.
    if (location.protocol !== 'http:' && location.protocol !== 'https:') return;
    send({
      t: 'pageview',
      p: location.pathname,
      r: document.referrer || '',
      w: window.innerWidth || (screen && screen.width) || 0
    });
  }

  // Public API for custom events.
  window.brionic = function (type, name) {
    if (type === 'event' && name) {
      send({ t: 'event', n: String(name), p: location.pathname });
    }
  };

  // Delegate clicks on elements marked with data-br-event.
  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('[data-br-event]') : null;
    if (el) window.brionic('event', el.getAttribute('data-br-event'));
  }, true);

  // Track SPA navigations (pushState / popstate).
  var lastPath = location.pathname;
  function maybePageview() {
    if (location.pathname !== lastPath) {
      lastPath = location.pathname;
      pageview();
    }
  }
  var push = history.pushState;
  if (push) {
    history.pushState = function () {
      push.apply(this, arguments);
      maybePageview();
    };
    window.addEventListener('popstate', maybePageview);
  }

  // Initial view.
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    pageview();
  } else {
    window.addEventListener('DOMContentLoaded', pageview);
  }
})();
