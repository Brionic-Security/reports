# Security Policy

## Reporting a vulnerability

If you discover a security vulnerability in Brionic Reports, please report it
privately so it can be fixed before public disclosure.

**Email:** security@brionicsecurity.com

Please include:

- A description of the issue and its impact.
- Steps to reproduce (proof-of-concept if possible).
- Affected version / commit.

We aim to acknowledge reports within a few business days and will keep you
updated on the fix. Please give us a reasonable window to address the issue
before any public disclosure.

## Scope & design notes

- The tracking endpoint (`/collect`) is intentionally public and cross-origin.
  It never stores raw IP addresses; IPs are used only transiently for geo
  lookups and the daily visitor hash.
- The dashboard is protected by a single operator credential (bcrypt hash in the
  environment) and CSRF tokens on all state-changing requests.
- Output is HTML-escaped and all SQL uses prepared statements.

Thank you for helping keep Brionic Reports and its users safe.
