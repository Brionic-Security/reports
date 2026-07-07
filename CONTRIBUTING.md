# Contributing to Brionic Reports

Thanks for your interest in improving Brionic Reports! Contributions of all
kinds are welcome — bug reports, features, docs, and code.

## Getting set up

See the [Quick start](README.md#quick-start-local) in the README. You need
PHP 8.1+ and Composer. SQLite is the default for local development, so there is
no database server to install.

## Ground rules

- **Keep it dependency-light.** This project intentionally avoids heavy
  frameworks and a front-end build step. New runtime dependencies need a good
  reason.
- **Privacy first.** Never store raw IP addresses on events, and don't add
  cookies or cross-site tracking. Uniqueness must stay based on the
  daily-rotating visitor hash.
- **Match the style.** Plain PHP with `declare(strict_types=1)`, small classes
  under `app/`, and PHP templates under `resources/views`.
- **Security matters.** Escape all output with `e()`, use prepared statements
  (via `App\Support\Database`), and keep CSRF protection on state-changing
  routes.

## Workflow

1. Fork the repo and create a branch: `git checkout -b feature/my-change`.
2. Make your change with a clear, focused commit history.
3. Test locally (`php scripts/migrate.php --fresh` on SQLite, then run the
   server and exercise the change).
4. Open a pull request describing **what** and **why**.

## Reporting bugs

Open an issue with steps to reproduce, expected vs. actual behavior, and your
PHP version / database driver.

## Security issues

Please do **not** open a public issue for security vulnerabilities — see
[SECURITY.md](SECURITY.md).
