# Changelog

Notable software changes are documented here. Dataset versions are released separately on Zenodo and retain their own version, DOI, checksums, and release notes.

## Dataset v1.1.0 — 2026-11-08

- Published a new fixed [Zenodo dataset](https://doi.org/10.5281/zenodo.24051198) containing 5,154 records, with SQLite/XZ, RDS, tabular and citation exports, a data dictionary, reproducibility scripts, technical validation and checksums.
- Preserved v1.0.6 and its historical validation materials. The net increase is 336 records; technical checks do not constitute a new external coverage audit. No manuscript files or private user data are included.
- The hosted webR workspace provides open R execution and PDF/LaTeX exports; accounts are optional for online saving. This dataset release does not change the software version.

## [2.1.6] - 2026-08-09

### Added

- Reproducible npm-based frontend builds with a pinned esbuild version.
- Broader CI coverage, documentation validation, dependency review, Dependabot configuration, security.txt, community guidance, and explicit code/data rights documentation.
- Structured public documentation for setup, architecture, data sources, API use, deployment, and operations.

### Changed

- Moved the citation graph above most network controls and literature-refinement controls so the visualization is visible earlier and easier to explore.
- Synchronized repository citation metadata with software v2.1.6 and current Zenodo dataset identifiers.
- Added explicit PHP 8.2, 8.4, and 8.5 CI coverage and removed PHP 8.5 CSV deprecation warnings.

## [2.1.5] - 2026-07-13

- Corrected Web Push VAPID signature conversion and added real-signature regression coverage.
- Enlarged historical funding marks and refreshed frontend cache versions.

## [2.1.4] - 2026-07-12

- Aligned public dataset titles and historical funding acknowledgement metadata.

## [2.1.3] - 2026-07-12

- Added the historical CogNovo and EU FP7 Marie Curie funding acknowledgement.

## [2.1.2] - 2026-07-11

- Corrected duplicate reconciliation and substance detection used for the fixed dataset v1.0.6 snapshot.

[2.1.6]: https://github.com/psilocybin-research/psilocybin-research-tracker/releases/tag/v2.1.6
[2.1.5]: https://github.com/psilocybin-research/psilocybin-research-tracker/releases/tag/v2.1.5
[2.1.4]: https://github.com/psilocybin-research/psilocybin-research-tracker/releases/tag/v2.1.4
[2.1.3]: https://github.com/psilocybin-research/psilocybin-research-tracker/releases/tag/v2.1.3
[2.1.2]: https://github.com/psilocybin-research/psilocybin-research-tracker/releases/tag/v2.1.2
