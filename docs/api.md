# API, Exports, and Reuse

## Current live database and agent access

For the deployed service, start with the [HTML access guide](https://psilocybin-research.com/for-ai.html), [plain-text edition](https://psilocybin-research.com/for-ai.txt), [versioned guide](research-agent-guide.md) and [OpenAPI specification](https://psilocybin-research.com/docs/research-api.openapi.json). The guide includes a task-to-interface table, data dictionary, research tools, provenance and completeness checks.

| Task | Live route |
| --- | --- |
| Full public SQLite metadata for local SQL | [database.php](https://psilocybin-research.com/database.php) |
| Full metadata CSV, explicit bound | [api/v1/papers?format=csv&limit=50000](https://psilocybin-research.com/api/v1/papers?format=csv&limit=50000) |
| Full public record objects | [export.php?format=json&limit=50000](https://psilocybin-research.com/export.php?format=json&limit=50000) |
| Search/filter JSON in batches | [api/v1/papers?per_page=100&page=1](https://psilocybin-research.com/api/v1/papers?per_page=100&page=1) |
| Every record, compact subset of fields | [catalogue.php](https://psilocybin-research.com/catalogue.php) |
| One year/title line per record | [catalogue.php?format=txt](https://psilocybin-research.com/catalogue.php?format=txt) |
| Files, query, IDs and checksums | [export.php?format=bundle&limit=50000](https://psilocybin-research.com/export.php?format=bundle&limit=50000) |

The compact catalogue returns `complete=true`, `record_count`, `total_records`, `generated_at_utc` and `records`. Verify `record_count == total_records == len(records)`; response headers expose count, total, completeness, generation time and SHA-256 of the uncompressed UTF-8 body. It supports JSON and TXT, does not accept filtering/pagination, and explicitly fails above its 50,000-record capacity rather than silently truncating. Missing compact years are null; TXT uses n.d. Preserve distinct IDs with identical titles.

Full metadata exports have a separate default limit of 5,000, so use an explicit bound and inspect counts. The full SQLite download is available for larger data. Paginated live API results are not pinned to one immutable snapshot across requests: preserve a downloaded database or actual bundle for reproducible analysis. Trials in the bibliographic catalogue should not be blindly counted again when joining structured trial facts.

Both `/api/v1/papers` and `/api.php?resource=papers` are supported live routes. For individual records use `/api.php?resource=paper&id=RECORD_ID`; for a citation use `/api.php?resource=citation&id=RECORD_ID`, substituting a returned numeric ID. Existing examples below remain useful; an older repository version may not implement every endpoint of the current deployed service.

These are public read-only endpoints. No account or key is required. Respect Retry-After and avoid parallel bulk requests. Source-derived abstracts, full texts, raw importer payloads and private subscriber/admin data are not redistributed. Code, live metadata and fixed Zenodo archives are different resources.


All public machine-readable surfaces use the rights-safe record projection described in [data-rights.md](data-rights.md). They do not redistribute unverified abstracts, registry descriptions, source-derived keywords, or raw importer payloads.

## JSON API

Common routes:

```text
/api.php
/api.php?resource=latest&limit=5&offset=0
/api.php?resource=analytics
/api.php?resource=authors&q=Carhart
/api.php?resource=topics
/api.php?resource=study_types
/api.php?resource=journals
/api.php?resource=paper&id=123
/api.php?resource=citation&id=123
```

Search and filter parameters mirror the public application where applicable. Consumers should tolerate additive fields and use stable identifiers rather than array position.

## Citation and Data Exports

```text
/export.php?format=bibtex
/export.php?format=ris
/export.php?format=csv
/export.php?format=json
/export.php?format=latex
```

The active search query can be passed to the export endpoint. Explicit limits should be used for interactive integrations; larger downloads are better served by the SQLite core.

## Sanitized SQLite Core

`/database.php` generates a fresh downloadable SQLite database containing only public metadata and dataset metadata. It is not a copy of the private runtime database.

## Widgets

`/widget.php` returns a compact latest-record HTML view. External pages can use:

```html
<div id="publication-widget"></div>
<script src="https://psilocybin-research.com/widget.js.php" data-target="publication-widget"></script>
```

## R Toolkit

`tools/psilocybin_bibliometrics_visnetwork.R` downloads the live sanitized SQLite core by default and generates bibliometric tables, figures, an interactive network, and an HTML report. Its input URL and output directory can be overridden through environment variables documented in the script.

The API, exports, widget, and SQLite endpoint read the local database. They do not trigger upstream source imports.
