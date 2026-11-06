# Transcriptomics results through MCP

Connect to **https://psilocybin-research.com/mcp** using Streamable HTTP, without authentication. The same server provides publication tools and five separate transcriptomics tools. The transcriptomics tools query the fixed [Zenodo v1.2.0 archive](https://doi.org/10.5281/zenodo.23283595), not the live literature database.

| Tool | Parameters and scope |
| --- | --- |
| `search_transcriptomic_genes` | Required `query`: literal substring of symbol or Ensembl ID. `limit` 1–50 (default 20); `offset` for pagination. Exact matches rank first. |
| `get_transcriptomic_gene` | Required `gene`: exact symbol, versioned or stable Ensembl ID. Optional `contrast`: `day1_vs_control`, `day3_vs_control`, `day3_vs_day1`; default returns all three. Multiple source mappings are retained. No automatic alias substitution. |
| `get_transcriptomic_pathways` | Optional `query`: literal substring of the pathway ID; spaces become underscores. Optional `contrast`, `limit` 1–5 (default 5), and `offset`. Returns all FGSEA variants and both CAMERA settings wherever tested. An empty CAMERA list means not tested in that analysis. |
| `get_transcriptomic_robustness` | Optional `topic`: `all` (default), `oxphos`, `dna`, or `redox`. Complete imported rows for the selected families: CAMERA, Bayesian summaries/diagnostics, alias sensitivity, exploratory DNA maintenance and targeted redox. Includes nonsignificant results. |
| `get_transcriptomic_provenance` | No arguments. Archive DOI/checksums, source commit, table checksums, field definitions, sample design and licence/attribution notices. |

Each result contains `data`, `provenance`, `field_definitions`, `limitations` and `atlas_links`. Gene matches/results have an exact-Ensembl-ID `atlas_url`; the gene explorer shows all three contrasts. Pathways provide `atlas_links_by_contrast` for the matching pathway and robustness views. Robustness responses include related section links; provenance links to Methods & sources. These links open the maintained atlas, while the numerical MCP results remain pinned to the archived release. Some imported Bayesian and sensitivity tables are available through MCP/source reports without a dedicated atlas visualisation. Source rows identify `source_table`; provenance lists its checksum, pinned GitHub URL and rights. Missing values are JSON `null`, never zero. Numbers retain the source CSV precision available to JSON; there is no model refitting or statistical recalculation.

The public [snapshot manifest](https://psilocybin-research.com/transcriptomics-manifest.php) is also an MCP resource. Queries use a locally indexed derivative of the verified archive, so they do not depend on a live Zenodo or GitHub request. The downloadable scientific archive remains on Zenodo. The [interactive atlas](https://psilocybin-research.github.io/psilocin-human-neuron-transcriptomics/) can evolve independently.

## Interpretation and citation

This is Christopher B. Germann's independent secondary analysis of Schmidt et al.'s experiment in human iPSC-derived cortical neurons. Cite both the [source article](https://doi.org/10.7554/eLife.104006.3) and the [reanalysis release](https://doi.org/10.5281/zenodo.23283595). The source repository's MIT notice, project CC BY 4.0 terms, and component-specific MSigDB/Reactome/HGNC attribution remain available through the provenance tool.

Three differentiation blocks and two cell-line backgrounds limit generalisation. The common controls have unresolved harvest age. FGSEA, CAMERA and Bayesian analyses reuse the same experiment; they are not independent replications. Report both CAMERA settings and the Bayesian prior sensitivity. Gene-expression and gene-set results do not demonstrate mitochondrial respiration, redox flux or telomere protection.

## Updating the snapshot

A GitHub commit or a new Zenodo publication does **not** silently replace the served results. The daily research-access workflow checks whether the pinned Zenodo record is still the latest and fails that freshness step when a newer record requires review; normal GitHub Actions notification settings apply. A failed or unreachable check means freshness is unknown, not that the data are current.

```sh
python3 bin/build-transcriptomics-snapshot.py --check-latest
```

To promote another release, review its licences and table/schema compatibility, then update `config/transcriptomics-source.json` with the fixed record, version, commit, archive URL, byte size and both checksums. Download that archive and build locally:

```sh
mkdir -p data
curl -fL --retry 2 -o data/transcriptomics-source.zip 'FIXED_ZENODO_ARCHIVE_URL'
python3 bin/build-transcriptomics-snapshot.py --archive data/transcriptomics-source.zip
python3 -m unittest discover -s tests -p test_transcriptomics_snapshot.py -v
php tests/mcp.php
```

The builder verifies the outer archive and the imported files against its internal SHA-256 inventory. It reads an explicit set of members without extracting arbitrary ZIP paths. It generates an immutable, content-addressed SQLite derivative under `data/transcriptomics/` and a deployment manifest at `config/transcriptomics-snapshot.json`. The database is opened read-only and verified against the manifest on access. No requester can select files, SQL or remote URLs.

Deploy the new database before activating its matching manifest, retain the previous database/manifest for rollback, and verify `bin/check-mcp-access.py` against production. Do not overwrite other runtime data. Never change the historical Zenodo ZIP, DOI or Git tag to update this connector. Source-anchored regression expectations must be reviewed for a new release rather than automatically rewritten to accept its results.
