# Security Policy

## Reporting Vulnerabilities

Please report security issues privately to:

`info@psilocybin-research.com`

Do not open a public GitHub issue for suspected vulnerabilities, leaked secrets, private runtime files, alert subscription data, push subscription data, or server configuration issues.

## Sensitive Runtime Data

The public repository must not contain:

- `data/publications.sqlite`
- SQLite backups
- logs, locks, heartbeat state, or runtime diagnostics
- `data/admin_token.php`
- `data/push_vapid.php`
- alert subscription data
- push subscription data
- Android upload keystores or signing properties
- local `.env` files

Use `.env.example` and `schema.sql` for reproducible setup without private data.

## Scope

Supported code paths include the PHP publication tracker, public API/export endpoints, PWA service worker, alert and push subscription handling, and the Android Trusted Web Activity wrapper.

