# Architecture

The tracker is a dependency-light PHP application backed by SQLite. Server-rendered pages remain functional without client JavaScript; local JavaScript adds search interactions, analytics, PWA features, alerts, and the native SVG citation network.

## Application Layers

1. Fetchers normalize PubMed, Crossref, Europe PMC, OpenAlex, preprint, and clinical-trial records.
2. `PublicationService` orchestrates imports and delegates database writes.
3. `PublicationRepository` provides deduplication, classification, search, analytics, curation, and citation-network data.
4. Public endpoints render HTML, JSON, citation exports, widgets, and a sanitized SQLite projection.
5. Operational services manage alerts, Web Push, logs, heartbeats, backup checks, and health reporting.

`src/Database.php` initializes and migrates the runtime schema. Request handlers use PDO through repository and service classes rather than embedding data-source logic in templates.

## Public Surfaces

- `index.php`: canonical search and results page
- `publication.php`, `authors.php`, and `evidence.php`: research context pages
- `citation-network.php`: dependency-free SVG network explorer
- `api.php` and `export.php`: machine-readable records and citations
- `database.php`: generated rights-safe SQLite core
- `widget.php` and `widget.js.php`: embeddable latest-record views
- `alert.php` and `push.php`: subscription management
- `health.php` and `status.php`: public-safe operational status

## Data Boundary

The runtime schema is deliberately richer than the public schema. Importers may retain source text and payloads for relevance screening, matching, classification, and curation. Every public record surface must use `public_paper()`, `rights_safe_public_payload()`, or the dedicated projection in `database.php`.

The public core may contain titles, authors or sponsors, venue, date, identifiers, source links, source/status labels, derived topics and study types, abstract-availability indicators, text-rights fields, and allowlisted factual provenance. It must not expose unverified source text or unrestricted importer payloads.

## Frontend and PWA

Readable source files are `assets/styles.css` and `assets/app.js`. `npm run build` produces the minified files served publicly. `manifest.webmanifest`, `sw.js`, local icons, local fonts, and `offline.html` provide installable and offline-aware PWA behavior without CDN JavaScript, CSS, or fonts.

## Testing

`tests/run.php` is the primary regression suite. It covers database behavior, import mapping, rights-safe output, alerts, push-signature handling, page wiring, and public-surface sentinels. CI also lints PHP, validates generated assets and project metadata, parses the R toolkit, and reviews dependency changes.
