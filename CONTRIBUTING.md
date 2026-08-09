# Contributing

Contributions that improve publication coverage, scientific context, accessibility, reliability, documentation, or researcher workflows are welcome.

## Before Starting

- Use the appropriate issue form for bugs, features, or data/source corrections.
- Discuss substantial architectural changes in an issue before implementation.
- Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).
- Never attach runtime databases, subscriptions, logs, credentials, unpublished datasets, or private source payloads to an issue or pull request.

## Development Setup

Follow [docs/getting-started.md](docs/getting-started.md). The supported PHP range is 8.2 and newer; CI currently exercises PHP 8.2, 8.4, and 8.5.

Create a focused branch and keep unrelated work out of the same pull request. Source JavaScript and CSS live in `assets/app.js` and `assets/styles.css`; generated minified assets must be rebuilt rather than edited manually.

## Required Checks

```bash
npm ci
npm run check:generated
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
Rscript -e "invisible(parse(file='tools/psilocybin_bibliometrics_visnetwork.R'))"
```

For rendered changes, run `php -S 127.0.0.1:8073 -t .` and verify keyboard navigation, narrow mobile layouts, and desktop layouts.

## Scientific and Data Requirements

- Preserve visible source and status distinctions for publications, preprints, protocols, reviews, and trial registrations.
- Keep DOI, PubMed, OpenAlex, registry, and source identifiers attached to their factual provenance.
- Do not fill missing bibliographic fields by inference when an authoritative source cannot verify them.
- Keep the public rights-safe projection intact. Public surfaces must not expose source-derived abstracts, descriptions, keywords, or unrestricted payloads without record-level permission.
- Add regression coverage when modifying public record projections, deduplication, classification, importers, or exports.

New fetchers implement `FetcherInterface` and are registered in `PublicationService::create()`. See [docs/data-sources.md](docs/data-sources.md).

## Pull Requests

Explain what changed, why it matters, how it was tested, and whether it affects public data, deployment, or backward compatibility. Small, reviewable pull requests are preferred. There is currently no contributor license agreement or developer certificate of origin requirement.

Contributors must follow the [Code of Conduct](CODE_OF_CONDUCT.md). Project decisions and maintainer responsibilities are described in [GOVERNANCE.md](GOVERNANCE.md).
