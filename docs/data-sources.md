# Data Sources and Normalization

## Implemented Sources

- PubMed / NCBI E-utilities
- Crossref
- Europe PMC
- OpenAlex
- medRxiv and bioRxiv
- PsyArXiv / OSF Preprints
- ClinicalTrials.gov API v2

Each normalized record retains its source name, source URL or identifier, and applicable publication status. Preprints remain visibly marked as not peer reviewed; protocols, reviews, and clinical-trial registrations are not presented as ordinary peer-reviewed research articles.

## Import Modes

- `--daily`: bounded recent window suitable for scheduled updates
- `--from` and `--to`: explicit date window
- `--source`: one configured fetcher
- `--backfill`: uncapped historical retrieval
- `bin/import-pubmed-ids.php`: targeted PubMed identifier import
- `bin/repair-metadata.php`: exact-source repair of incomplete identified records

Fetchers are date- or cursor-paged according to source capabilities. Historical preprint coverage is primarily retrieved through Europe PMC because direct medRxiv and bioRxiv APIs are date-window oriented.

## Deduplication and Reconciliation

Persistent identifiers are preferred: DOI, PubMed ID, and OpenAlex/source identifiers. Normalized-title matching remains a controlled fallback for versions or source records that cannot be linked by identifier. Existing nonblank verified fields are preserved when repair sources return less complete metadata.

Automatic topic and study-type labels are rule-based and derived from retained processing data. They support discovery but do not replace expert evidence appraisal.

## Adding a Source

New fetchers implement `FetcherInterface`, return the normalized record structure used by existing fetchers, and are registered in `PublicationService::create()`. A contribution must document:

- query scope and paging behavior
- persistent identifiers and source URLs
- publication-status mapping
- rate limits and contact requirements
- metadata rights and public redistribution constraints
- deduplication interactions
- focused fixtures and regression tests

Do not ingest a new source merely because an endpoint is technically accessible. Its terms, reliability, scientific relevance, and long-term maintenance burden must be evaluated first.
