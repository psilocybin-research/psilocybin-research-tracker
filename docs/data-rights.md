# Code and Data Rights

## Software

The source code in this repository is available under the [MIT License](../LICENSE). The MIT licence applies to the software and does not relicense third-party bibliographic, registry, or textual content processed by the application.

## Public Dataset

The public dataset is a rights-safe bibliographic core. It may include titles, authors or sponsors, venues, dates, DOI, PubMed, OpenAlex and registry identifiers, source links, source and status labels, derived topics and study types, abstract-availability indicators, text-rights fields, and allowlisted factual provenance.

It excludes unverified source-derived abstracts, registry descriptions, keywords, and unrestricted payload text. `abstract_available=true` means only that text was observed during processing; it does not grant redistribution permission. `abstract_redistributed` remains false unless record-level permission or a permissive licence has been verified and documented.

## Compiler and Third-Party Rights

The fixed Zenodo dataset is identified as CC BY 4.0, but that licence applies only to compiler-held rights in selection and arrangement, original normalization and annotations, validation outputs, and documentation. Third-party bibliographic metadata remain subject to the licences and terms of their respective source providers.

Source URLs and provenance fields are retained so users can consult authoritative records and applicable terms. No public licence statement should imply that this project owns or relicenses third-party source text.

## Runtime Processing

The private runtime database may retain source-derived text, keywords, registry descriptions, and importer payloads for ingestion, matching, relevance screening, deduplication, classification, citation linking, and curation. That internal processing layer is not publicly downloadable and must not be exposed through HTML, API, export, analytics, widgets, or SQLite-download routes.

## Dataset Citation

- Latest dataset concept DOI: [10.5281/zenodo.21293525](https://doi.org/10.5281/zenodo.21293525)
- Fixed dataset v1.0.6 DOI: [10.5281/zenodo.21333043](https://doi.org/10.5281/zenodo.21333043)
- Dataset v1.0.6 corresponds to software release [v2.1.2](https://github.com/psilocybin-research/psilocybin-research-tracker/releases/tag/v2.1.2)

Software releases after v2.1.2 improve the living application but do not alter the immutable v1.0.6 dataset snapshot.
