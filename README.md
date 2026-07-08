# Brionic Reports

**Open-source, privacy-first, self-hosted web analytics for many sites — with automatic client report emails.**

Brionic Reports is a lightweight analytics platform you host yourself. Connect any
number of websites, watch all of them from one dashboard, and (optionally) send
each client a branded weekly traffic report by email. No cookies, no cookie
banner, no raw IP addresses stored.

> Built by [Brionic Security](https://brionicsecurity.com). Contributions welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).
>
> **Official hosted instance:** <https://reports.brionicsecurity.com>

## Why

- **Multi-site by design** — one instance, unlimited websites, one dashboard.
- **Plug-and-play** — add a site, paste a one-line `<script>`, done.
- **Privacy-first** — no cookies and **no raw IPs stored**. Unique visitors are
  counted with a daily-rotating hash (so visitors can't be tracked across days),
  and only a coarse country/city is derived from a cached geo lookup.
- **Client reports** — send customers a weekly "here's how your site did" email.
- **Tiny & dependency-free** — plain PHP 8.1+ and PDO. Runs on cheap shared
  hosting (SQLite for dev, MySQL for production). No Node, no build step.

## Metrics

Unique visitors · page views · top pages · referrers/sources · countries ·
devices · browsers · operating systems · humans vs bots · custom events ·
per-day traffic chart · recent activity.

## Quick start (local)

```bash
git clone https://github.com/Brionic-Security/reports.git
cd reports
composer install                 # or: composer dump-autoload (no deps required)
cp .env.example .env
# set APP_KEY, ADMIN_EMAIL and ADMIN_PASSWORD_HASH in .env:
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"                    # APP_KEY
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"  # ADMIN_PASSWORD_HASH

php scripts/migrate.php          # create the schema (SQLite by default)
php -S 127.0.0.1:8790 -t public public/index.php
```

Open <http://127.0.0.1:8790>, sign in, add a site, and paste the snippet it gives
you into that site's `<head>`:

```html
<script defer data-site="site_xxxxxxxx" src="https://reports.example.com/b.js"></script>
```

## Custom events

```html
<!-- declarative -->
<button data-br-event="signup_click">Sign up</button>
```

```js
// programmatic
window.brionic('event', 'checkout_started');
```

## Client reports

Each site can have a **client report email**. Brionic Reports generates a branded
traffic summary (unique visitors, page views, top pages, referrers, countries,
devices) and emails it to that client — the automated "weekly visit report".

From a site's **Settings** page you can *Preview* the report, *Send a test to
yourself*, or *Send to the client now*. To send automatically, add a cron:

```bash
# Weekly, Mondays at 07:00 — reports the previous 7 days.
0 7 * * 1  php /path/to/reports/scripts/send_reports.php >> storage/logs/reports.log 2>&1
```

```bash
php scripts/send_reports.php               # all sites with a client email
php scripts/send_reports.php --days=30     # 30-day period
php scripts/send_reports.php --site=3      # a single site
php scripts/send_reports.php --test=you@example.com --site=3   # preview-send to an address
```

Reports use the mail driver in `.env` (`log` for dev, `smtp` for production). A
site is only sent one report per period (the runner de-duplicates).

## Traffic alerts

Get emailed when a site's traffic spikes or drops. `check_alerts.php` compares
yesterday's human page views to the average of the prior 7 days and emails the
operator (thresholds in `config/alerts.php`). Add a daily cron:

```bash
# Daily at 08:00
0 8 * * *  php /path/to/reports/scripts/check_alerts.php >> storage/logs/alerts.log 2>&1
```

## Production (shared hosting / SiteGround, etc.)

1. Create a subdomain (e.g. `reports.example.com`) and point its **document root
   at the `public/` directory**.
2. Create a MySQL database and set `DB_DRIVER=mysql` + credentials in `.env`.
3. `php scripts/migrate.php`
4. Add a daily/weekly cron for report emails (Phase 2):
   `php scripts/send_reports.php`

## How it works

```
website + b.js  ──beacon──►  /collect  ──►  events (MySQL)  ──►  dashboard
                                                          └────►  weekly report emails
```

The tracker sends a "simple" `text/plain` beacon (no CORS preflight). The
collector classifies the user agent (bot vs human, browser/OS/device), derives a
country from a cached geo lookup, computes a daily visitor hash, and stores the
event — never the IP.

## Tech

Plain PHP 8.1+, PDO (SQLite/MySQL), a small custom router/kernel. No framework,
no front-end build. MIT licensed.

## Roadmap

- [x] Multi-site collector + dashboard
- [x] Bot filtering, geo, devices, referrers, custom events
- [x] Weekly client report emails (SMTP)
- [x] Traffic alerts (spikes/drops)
- [ ] Plugin modules (uptime, Trustpilot, etc.)
- [ ] Multi-user / team accounts

## License

[MIT](LICENSE) © Brionic Security LLC
