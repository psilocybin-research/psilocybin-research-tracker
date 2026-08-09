# API, Exports, and Reuse

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
