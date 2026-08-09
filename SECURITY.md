# Security Policy

## Supported Versions

Security fixes are applied to the current release line and deployed live application. Older tags and fixed research-dataset snapshots remain immutable references and should not be assumed to receive patches.

| Version | Supported |
| --- | --- |
| Latest release | Yes |
| `main` | Best effort before the next release |
| Older releases | No |

## Reporting a Vulnerability

Email `info@psilocybin-research.com`. Do not open a public issue for suspected vulnerabilities, leaked secrets, private runtime files, alert or push subscription data, or server-configuration concerns.

Please include the affected URL or component, reproduction steps, likely impact, and any public-safe evidence. Do not access, alter, or retain other people's data while investigating.

Reports are normally acknowledged within three working days. The maintainer will provide a status update within seven working days and coordinate disclosure after a fix is available. Complex reports may take longer, but reporters will receive progress updates.

## Scope

Supported surfaces include the PHP tracker, API and export endpoints, sanitized SQLite download, alert and push subscription handling, service worker, public JavaScript, and Android Trusted Web Activity wrapper.

Third-party source APIs, browser vendors, email providers, push services, hosting infrastructure, and Zenodo are outside this repository's direct control. Vulnerabilities in those services should also be reported to their operators.

## Sensitive Runtime Data

The public repository must not contain live databases or backups, logs, locks, heartbeat state, admin tokens, VAPID private keys, alert or push subscription data, local environment files, Android signing material, manuscript working files, or private notes.

Use `.env.example` and `schema.sql` for reproducible local setup. The machine-readable disclosure policy is available at `https://psilocybin-research.com/.well-known/security.txt`.
