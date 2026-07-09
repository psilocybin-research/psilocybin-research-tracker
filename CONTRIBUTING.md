# Contributing

This project is a plain PHP + SQLite publication tracker for psilocybin and psilocin research records.

## Local Checks

Run these before opening a pull request:

```bash
bash bin/build-assets.sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
```

For rendered frontend changes, run a local PHP server and verify desktop and mobile layouts:

```bash
php -S 127.0.0.1:8073 -t .
```

## Editing Rules

- Edit `assets/styles.css` and `assets/app.js`, then rebuild minified assets.
- Do not hand-edit `assets/styles.min.css` or `assets/app.min.js`.
- Do not commit runtime data, logs, databases, keys, tokens, or Android signing files.
- Preserve visible labels for preprints and clinical trial records.
- Keep source/status distinctions intact across UI, API, exports, and analytics.

## Data Sources

New fetchers should implement `FetcherInterface` and be registered in `PublicationService::create()`. Source-specific metadata should preserve source names and publication status rather than merging everything into a generic feed.

