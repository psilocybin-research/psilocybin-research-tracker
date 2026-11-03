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

## Read-only MCP connector

The live service exposes **`https://psilocybin-research.com/mcp`** using **Streamable HTTP** with **no authentication**. This is a remote connector hosted by the research website; optional browser WebMCP forms and GitHub documentation mirrors are separate interfaces.

Eight tools: `search`, `fetch`, `search_publications`, `search_trials`, `get_citation`, `get_citation_relationships`, `get_annual_statistics`, and `get_dataset_downloads`. Search returns up to twenty `{id,title,url}` records. Fetch resolves a numeric string ID to a citable public metadata document with `id,title,text,url,metadata`; it does not supply the publisher abstract/full text. Filtered publication/trial pages are capped at fifty records and explicitly report totals/pages; they are live rather than pinned multi-request snapshots. Use the verified download manifest for whole-catalogue analysis. DOI relationships are bounded source-derived links, not evidence of scientific support or trial/result identity.

A compatible client initializes the server, sends `notifications/initialized`, then lists/calls tools or lists/reads the three fixed resources (guide, manifest, annual statistics). Tool schemas are available through `tools/list`; supported protocol versions are `2025-11-25`, `2025-06-18` and `2025-03-26`. POST one JSON-RPC message with `Content-Type: application/json`, `Accept: application/json, text/event-stream`, and the negotiated `MCP-Protocol-Version`. JSON responses avoid long-lived workers; notifications receive empty HTTP202. No session ID/cookie is created. GET and DELETE return405, so opening the endpoint as a webpage is not a connector test. Requests are bounded to32KiB, inputs are strictly typed and all tools are read-only. Respect429/503 and Retry-After. Server-side clients normally omit Origin; browser origins are explicitly validated.

See the [full connection instructions](https://psilocybin-research.com/for-ai.html#connect-using-mcp), including ChatGPT's current custom-MCP workflow and client limitations. The server returns metadata and downloadable URLs; attaching a local file still depends on the client's execution environment. It does not automatically install itself into an AI account or guarantee availability in every workspace.

Run `python3 bin/check-mcp-access.py` to exercise the live protocol lifecycle, every tool, privacy exclusions, manifest agreement and listed resources. The daily/manual public research access workflow runs it alongside complete download verification. The implementation was also tested with the official TypeScript MCP client SDK1.32.2. The documented endpoint is the deployed service; older repository backend checkouts are not a separate hosted connector.

## Verified files and annual statistical queries

Use [`/dataset-manifest.json`](https://psilocybin-research.com/dataset-manifest.json) for reproducible downloads. Check `complete=true` and `is_current=true`, retain `snapshot_id`, `catalogue_revision` and `generated_at_utc`, then download the exact `artifacts[format].url`. Check the resulting file's uncompressed byte length, SHA-256 and represented-record count. These checksums describe those existing files, not a later live export. The versioned `manifest_url` accompanies the files; the latest three completed snapshots are retained, so preserve files with your analysis. Builds run after ingestion and during scheduled cache prewarming; visitors only read completed files.

Eleven formats are provided: `sqlite`, `csv`, `json`, `bibtex`, `ris`, `latex`, `bundle`, `compact-json`, `txt`, `statistics-json`, and `statistics-csv`. Statistics files summarize the indexed records rather than listing one row per record. `HEAD` on the exact `/dataset-download.php?snapshot=...&format=...` URL returns that file's size and checksum. `HEAD /database.php` intentionally does not generate a database or report a newly built file size. Public SQLite excludes private runtime data and is smaller than the private operational database.

[`/api/v1/statistics/publications-by-year`](https://psilocybin-research.com/api/v1/statistics/publications-by-year) returns cached catalogue-wide JSON; append `?format=csv` for a table. It rejects search and pagination parameters. JSON includes `record_count`, `dated_records`, `missing_year_records`, `status_totals`, `missing_year`, `years`, generation time and an opaque `catalogue_revision`. Status groups are `published`, `preprints`, `trial_registrations` and `other`. Rows reconcile with the public SQLite core; missing years are not inferred. CSV includes a blank-year `missing_year` row even when its count is zero. The current year's rows are marked partial. Site-normalized publication status is not independent verification of peer review, and registrations are not clinical results.

For an exact comparison, use the manifest's frozen statistics and SQLite from the same snapshot. Live statistics can change with ingestion; compare `catalogue_revision` before comparing them with a previous download.

Run `python3 bin/check-research-access.py` to test public HTTP access, exact-file integrity, privacy exclusions, count and identifier consistency, annual statistics and documentation MIME types. The daily/manual [workflow](../.github/workflows/research-access.yml) runs the same check from an external GitHub runner. It does not test every AI browser or remove provider DNS/download restrictions.

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
