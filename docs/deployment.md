# Deployment

The production application is served from the root of `https://psilocybin-research.com/`. Do not recreate or deploy to the retired `/publication-tracker/` URL.

## Web Root

Deploy tracked application files to the configured document root while preserving production runtime data. Never overwrite the live `data/` directory, environment configuration, credentials, keys, logs, subscriptions, backups, or administrative state.

The web server must:

- serve PHP 8.2 or newer with PDO SQLite
- deny direct access to runtime data and hidden configuration
- serve `/.well-known/assetlinks.json` and `/.well-known/security.txt`
- preserve HTTPS, canonical-host, caching, and security-header configuration
- permit the writable runtime paths required by SQLite, locks, logs, backups, and heartbeat files

## Release Procedure

1. Install pinned dependencies with `npm ci`.
2. Run the complete local checks in [getting-started.md](getting-started.md).
3. Review `git status` and audit tracked paths for private data.
4. Merge a passing pull request and create the corresponding GitHub release.
5. Deploy only the verified tracked application files, excluding Git metadata and local operational documentation not intended for the web root.
6. Verify the live homepage, citation network, API, sanitized SQLite download, health endpoint, service worker, and security.txt.

Use a recoverable remote backup or atomic synchronization strategy where the hosting environment supports it. Runtime-data deletion or replacement requires a separate, explicit decision.
