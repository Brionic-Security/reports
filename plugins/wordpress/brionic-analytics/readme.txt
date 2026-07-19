=== Brionic Reports ===
Contributors: brionicsecurity
Tags: analytics, privacy, statistics, stats
Requires at least: 5.0
Requires PHP: 7.2
Stable tag: 1.5.1
License: MIT

Privacy-first website analytics by Brionic Reports. No cookies, no personal data.

== Description ==

Adds the Brionic Reports tracker to your WordPress site so you can see page
views, unique visitors, top pages, countries, devices and more from your
Brionic Reports dashboard — without cookies or collecting personal data.

When you download this plugin from your dashboard, your site key is already
filled in. Just install and activate.

== Installation ==

1. In WordPress, go to Plugins → Add New → Upload Plugin.
2. Choose the brionic-reports.zip file you downloaded and click Install Now.
3. Click Activate.
4. (Optional) Verify the site key under Settings → Brionic Reports.

That's it — analytics start flowing to your dashboard immediately.

== Changelog ==

= 1.2.0 =
* Added Automatic updates management (core minor/major, plugins, themes) with an email summary after the updater runs.
* Added a branded Login page (Brionic logo + full-page background; ships with the Brionic logo and a geometric red/blue wallpaper by default).

= 1.1.0 =
* Added Email settings: From name, From email, Reply-To, and forward a BCC copy of every outgoing email, plus a test-email tool. Settings page renamed to "Brionic Reports Config".

= 1.0.3 =
* The built-in (downloaded) site key now always applies, so the plugin works with zero setup even on hosts whose option storage is unreliable.

= 1.0.2 =
* Added a "Test connection" button that checks connectivity to Brionic Reports from your server.

= 1.0.1 =
* More reliable settings save (self-contained, no longer depends on the Settings API).
* Emits an HTML marker comment and loads earlier in the page head for easier validation.

= 1.0.0 =
* Initial release.
