# Getting Started

## Use the hosted research service

You can use the [live research database](https://psilocybin-research.com/), [API and AI access page](https://psilocybin-research.com/research-api.php), and [verified public dataset downloads](https://psilocybin-research.com/research-dataset.php#verified-downloads) without installing the application.

To connect an MCP-compatible AI client, add **`https://psilocybin-research.com/mcp`** as a remote **Streamable HTTP** server with **no authentication**. The connector supplies public read-only searches, source-linked records, trial facts, citations, annual statistics and dataset download links. Follow the [connection guide and tool reference](https://psilocybin-research.com/for-ai.html#connect-using-mcp), or use the [raw guide fallback](https://raw.githubusercontent.com/psilocybin-research/psilocybin-research-tracker/main/docs/research-agent-guide.md) if your tool cannot read the website guide. A direct browser GET to `/mcp` returns 405 by design; connect through your MCP client. Client/workspace permissions still apply.

The hosted endpoint describes the deployed service; an older repository checkout may expose a different endpoint set. To run your own application instance, continue with the installation instructions below. GitHub provides software and documentation; Zenodo archives fixed datasets; the website hosts the changing research catalogue.

## Requirements

- PHP 8.2 or newer with PDO SQLite, SQLite3, JSON, and mbstring
- Node.js 20 or newer with npm
- Write access to the configured runtime data directory
- Optional: R for the bibliometrics toolkit and Android Studio/Gradle for the TWA wrapper

## Install

```bash
git clone https://github.com/psilocybin-research/psilocybin-research-tracker.git
cd psilocybin-research-tracker
npm ci
npm run build
```

Copy `.env.example` to `.env` if environment-file loading is part of your local runtime, or export the variables through your shell or web server. Never commit a populated `.env`.

Start the development server:

```bash
php -S 127.0.0.1:8073 -t .
```

The first application request or command initializes `data/publications.sqlite` from `schema.sql`. Apache deployments must retain `data/.htaccess`; other servers must independently deny direct web access to the runtime data directory.

## Configuration

| Variable | Purpose |
| --- | --- |
| `PUBLICATION_TRACKER_PUBLIC_URL` | Canonical public URL used for links and metadata |
| `PUBLICATION_TRACKER_DATA_DIR` | Writable runtime directory; defaults to `data` |
| `PUBLICATION_TRACKER_DSN` | PDO DSN; defaults to local SQLite |
| `PUBLICATION_TRACKER_ADMIN_TOKEN` | Optional externally managed admin token |
| `PUBLICATION_TRACKER_NCBI_EMAIL` | Contact email recommended for NCBI E-utilities |
| `PUBLICATION_TRACKER_NCBI_API_KEY` | Optional NCBI API key |
| `PUBLICATION_TRACKER_ALERT_FROM` | Alert sender address |
| `PUBLICATION_TRACKER_CONTACT_EMAIL` | Public project contact address |
| `PUBLICATION_TRACKER_VAPID_SUBJECT` | Web Push VAPID contact URI |

Additional mail, alert-encryption, VAPID, backup, and operational settings are deployment concerns and must remain outside source control.

## Populate the Database

Use a bounded daily import first:

```bash
php bin/update.php --daily
```

Target a date window or one source when testing:

```bash
php bin/update.php --from=2024-01-01 --to=2024-01-31
php bin/update.php --from=2024-01-01 --to=2024-01-31 --source=OpenAlex
```

`php bin/update.php --backfill` is intentionally uncapped and may make many paginated requests. Run it only when a complete local historical database is required.

## Verify Changes

```bash
npm run check:generated
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
Rscript -e "invisible(parse(file='tools/psilocybin_bibliometrics_visnetwork.R'))"
```

See [CONTRIBUTING.md](../CONTRIBUTING.md) before opening a pull request.
