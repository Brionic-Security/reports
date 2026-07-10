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
  // Config comes from the script tag's data-* attributes, but falls back to a
  // window.__brionic global set by the host (e.g. the WordPress plugin). The
  // fallback keeps analytics working even when a speed/optimiser plugin combines
  // or inlines this external script, which drops the attributes and rewrites the
  // script origin.
  var cfg = window.__brionic || {};
  var site = (script && script.getAttribute('data-site')) || cfg.site;
  if (!site) return;

  // How the tracker was installed (e.g. the WordPress plugin sets "wordpress").
  var via = (script && script.getAttribute('data-via')) || cfg.via || '';

  // Endpoint = the Brionic Reports origin. Prefer the explicit config; otherwise
  // derive it from this script's own URL.
  var origin = cfg.origin || '';
  if (!origin && script && script.src) {
    try { origin = new URL(script.src).origin; } catch (e) {}
  }
  if (!origin) origin = location.origin;
  var endpoint = origin + '/collect';

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
