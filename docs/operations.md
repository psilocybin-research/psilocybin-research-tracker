# Operations

## Scheduled Updates

Production normally runs the bounded daily importer once per day:

```cron
20 3 * * * cd /path/to/document-root && php bin/update.php --daily >> data/update.log 2>&1
```

Use an absolute deployment-specific path outside public documentation and ensure overlapping jobs are prevented. Custom date windows and historical backfills are maintainer operations rather than public refresh actions.

## Metadata Repair and Classification

```bash
php bin/repair-metadata.php --dry-run
php bin/repair-metadata.php
php bin/classify-existing.php
```

Metadata repair performs exact PubMed and Crossref lookups only for incomplete identified records and preserves existing nonblank values. Records that cannot be verified should remain incomplete or be quarantined rather than filled by inference.

## Backups

Use `php bin/backup-sqlite.php` or an equivalent SQLite-safe backup procedure. Do not copy a live database file while a write transaction is in progress. Backups must remain outside the public web and Git repositories and should have documented retention and restore testing.

## Alerts and Push

`php bin/alerts.php` supports daily, weekly, and monthly digest generation. Preview before a live send. Sender configuration, encryption keys, VAPID private material, delivery state, and subscription records must remain private.

## Monitoring

- `/status.php` reports lightweight update freshness.
- `/health.php` returns public-safe operational checks; `?format=html` provides a human-readable view.
- heartbeat JSON, application logs, fetch errors, and backup checks provide private operational context.

Investigate stale updates, failed heartbeats, recent error logs, database integrity, writable paths, disk space, and inode availability before rerunning large jobs. Preserve append-only logs and runtime records unless an explicit retention action has been approved.
