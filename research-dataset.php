<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$db = new Database();
$db->initialize();
$overview = ResearchOverview::summarize($db);
$totals = $overview['totals'];
$verifiedDataset = DatasetArtifacts::current($db);
$assetVersion = '20261011-server-evidence-v199';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$title = 'Psilocybin Research Dataset | Download, API and Citation';
$description = 'Download psilocybin and psilocin research metadata as SQLite, compressed SQLite, RDS, CSV, JSON, BibTeX or RIS. Compare the living database with the citable Zenodo snapshot.';
$robots = $_GET ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
if ($_GET) header('X-Robots-Tag: noindex, follow');
$datasetSchema = LinkedData::dataset();
if ($verifiedDataset !== null) {
    foreach ($verifiedDataset['artifacts'] as $file) {
        $datasetSchema['distribution'][] = ['@type' => 'DataDownload', 'name' => $file['label'],
            'encodingFormat' => $file['mime_type'], 'contentUrl' => $file['url'],
            'contentSize' => (string)$file['bytes'] . ' bytes', 'sha256' => $file['sha256']];
    }
}
$schema = ['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'WebPage', '@id' => $baseUrl . 'research-dataset.php', 'url' => $baseUrl . 'research-dataset.php',
        'name' => $title, 'description' => $description, 'mainEntity' => ['@id' => $baseUrl . '#live-dataset']],
    $datasetSchema,
    ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Research database', 'item' => $baseUrl],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Research dataset', 'item' => $baseUrl . 'research-dataset.php']]],
]];
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <?= detail_page_seo($title, $description, 'research-dataset.php', ['robots' => $robots]) ?>
  <?= json_ld_script($schema) ?>
  <link rel="alternate" type="application/ld+json" href="<?= h($baseUrl . 'linked-data.php?resource=dataset') ?>">
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head><body class="detail-page">
<?= detail_page_header('research-dataset') ?>
<main class="detail-shell" id="main">
  <section class="panel detail-hero">
    <span class="eyebrow">Research data and reuse</span>
    <h1>Psilocybin and psilocin research dataset</h1>
    <p>A source-linked bibliographic core for literature screening, citation management and reproducible bibliometrics. The living index currently contains <strong><?= h(number_format($totals['total'])) ?> visible records</strong><?php if ($totals['first_year'] !== null): ?> with recorded years spanning <?= h((string)$totals['first_year']) ?>–<?= h((string)$totals['last_year']) ?><?php endif; ?>. It includes literature, preprints, protocols, reviews and trial registrations; these are different kinds of records, not interchangeable evidence.</p>
    <p>Maintained by <a href="editorial-policy.php#author">Dr. Christopher B. Germann</a> · <a href="editorial-policy.php">Selection, validation and corrections</a>.</p>
    <nav class="research-shortcuts" aria-label="Dataset downloads"><a href="#download-for-r">Download for R / RStudio (.rds)</a><a href="#verified-downloads">All verified downloads</a><a href="#worked-examples">Reproducible examples</a></nav>
    <p>See a worked example in the <a href="psilocybin-research-brief-2026-10/">October 2026 research brief</a>: frozen analytical input, downloadable tables and an executable Python reproduction script.</p>
  </section>
  <section class="panel detail-card">
    <h2>Choose the right dataset for your work</h2>
    <div class="research-table-wrap" role="region" aria-label="Scrollable research table" tabindex="0"><table class="research-table">
      <caption>Living access and fixed reproducible citation</caption>
      <thead><tr><th scope="col">Resource</th><th scope="col">Use it for</th><th scope="col">Version and scope</th></tr></thead>
      <tbody>
        <tr><th scope="row"><a href="database.php">Live SQLite database</a></th><td>Current screening, joining identifiers and repeatable SQL analysis</td><td><?= h(number_format($totals['total'])) ?> visible records at page generation; changes with ingestion and curation</td></tr>
        <tr><th scope="row"><a href="https://doi.org/10.5281/zenodo.24051198">Zenodo snapshot v1.1.0</a></th><td>Reproducing the fixed deposited dataset and citing a stable version</td><td>5,154 records spanning 1958–2026; separate from the moving live index</td></tr>
        <tr><th scope="row"><a href="open-datasets.php">Paper-linked open datasets</a></th><td>Finding external research data associated with individual studies</td><td>Repository records linked to papers; separate from this bibliographic compilation</td></tr>
      </tbody>
    </table></div>
    <p>The earlier <a href="https://doi.org/10.5281/zenodo.21333043">v1.0.6 snapshot (4,818 records)</a> remains available for reproducing historical analyses. The <a href="https://github.com/psilocybin-research/psilocybin-research-tracker">GitHub repository</a> provides source code, documentation and software citation metadata. It is separate from the live catalogue and the archived Zenodo dataset.</p>
    <p>For a new reproducible analysis, retain the downloaded file, its SHA-256 checksum, your retrieval date and your query. A later live download may contain different records. The <a href="https://doi.org/10.5281/zenodo.21293525">all-versions Zenodo DOI</a> leads to the version history; cite a specific version when reproducibility matters.</p>
  </section>
  <section class="panel detail-card">
    <h2 id="verified-downloads">Verified dataset downloads</h2>
    <?php if ($verifiedDataset !== null): ?>
    <p>This completed snapshot represents <strong><?= h(number_format($verifiedDataset['record_count'])) ?> public records</strong>, generated <time datetime="<?= h($verifiedDataset['generated_at_utc']) ?>"><?= h($verifiedDataset['generated_at_utc']) ?></time>. Each link identifies an exact file whose size and SHA-256 checksum are listed in <a href="<?= h($verifiedDataset['manifest_url']) ?>">this snapshot’s manifest</a>. The <a href="dataset-manifest.json">latest dataset manifest</a> follows new snapshots as they are published. Preserve the downloaded files with your analysis; the latest three snapshots are retained.</p>
    <?php if (!$verifiedDataset['is_current']): ?><p class="notice">The live catalogue has changed since this snapshot. Use the live exports below for the latest records.</p><?php endif; ?>
    <div class="research-table-wrap" tabindex="0" role="region" aria-label="Verified public dataset files"><table class="research-table">
      <caption>Exact files from one catalogue snapshot. Sizes describe the downloaded files after HTTP transfer decoding; XZ and RDS sizes include their file compression; statistics files summarize the represented records.</caption>
      <thead><tr><th scope="col">Download</th><th scope="col">File size</th><th scope="col">Records represented</th></tr></thead>
      <tbody><?php foreach ($verifiedDataset['artifacts'] as $format => $file): ?><tr><th scope="row"><a href="<?= h($file['url']) ?>" download="<?= h($file['filename']) ?>"><?= h($file['label']) ?></a></th><td><?= h(number_format($file['bytes'] / 1000000, 2)) ?> MB (<?= h(number_format($file['bytes'])) ?> bytes)</td><td><?php if ($format === 'study-links'): ?><?= h(number_format($file['linked_record_count'] ?? 0)) ?> linked records · <?= h(number_format($file['family_count'] ?? 0)) ?> reviewed families<?php else: ?><?= h(number_format($file['record_count'])) ?><?php endif; ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <p><a href="<?= h($verifiedDataset['manifest_url']) ?>">Keep this snapshot’s manifest</a> for its URLs, counts and checksums. The compact directory and TXT are smaller projections; SQLite, full CSV and full JSON provide broader public metadata.</p>
    <?php else: ?><p>Verified snapshots are being prepared. The live metadata and citation downloads below remain available.</p><?php endif; ?>
    <h3 id="download-for-r" style="scroll-margin-top: 92px">Download for R / RStudio (.rds)</h3>
    <?php if (isset($verifiedDataset['artifacts']['rds'])): $rdsFile = $verifiedDataset['artifacts']['rds']; ?>
    <p><a class="secondary" href="<?= h($rdsFile['url']) ?>" download="<?= h($rdsFile['filename']) ?>">Download for R / RStudio (.rds)</a></p>
    <p><?= h(number_format($rdsFile['bytes'])) ?> bytes · <?= h(number_format($rdsFile['record_count'])) ?> public records · XZ compression. This is the same RDS file listed in the verified table above; its exact checksum is in <a href="<?= h($verifiedDataset['manifest_url']) ?>">this snapshot’s manifest</a>.</p>
    <?php else: ?><p>The RDS download is being prepared. Use the SQLite or CSV downloads in the meantime.</p><?php endif; ?>
    <p><strong>RDS · XZ</strong> opens directly with base R: no extraction or additional R package is needed. It contains a named list with <code>publications</code> and <code>metadata</code> data frames, preserving all public SQLite table values. SQL missing values become <code>NA</code>; dates remain the recorded strings. It contains table data rather than SQLite indexes.</p>
    <pre class="research-code"><code>research &lt;- readRDS("publications.rds")
papers &lt;- research$publications
metadata &lt;- research$metadata</code></pre>
    <p>For checksum verification and a complete analysis, use the <a href="#worked-examples">R reproducibility script below</a>. Opening the RDS requires only base R; the analysis script additionally uses <code>jsonlite</code> and <code>digest</code>.</p>
    <h3 id="download-parquet">Parquet and Feather</h3>
    <?php if (isset($verifiedDataset['artifacts']['parquet'])): $parquetFile = $verifiedDataset['artifacts']['parquet']; ?>
    <p><a class="secondary" href="<?= h($parquetFile['url']) ?>" download="<?= h($parquetFile['filename']) ?>">Parquet</a></p>
    <p><?= h(number_format($parquetFile['bytes'])) ?> bytes · <?= h(number_format($parquetFile['record_count'])) ?> public records · Zstandard compression. Verify its SHA-256 against <a href="<?= h($verifiedDataset['manifest_url']) ?>">this snapshot’s manifest</a>.</p>
    <?php endif; ?>
    <?php if (isset($verifiedDataset['artifacts']['feather'])): $featherFile = $verifiedDataset['artifacts']['feather']; ?>
    <p><a class="secondary" href="<?= h($featherFile['url']) ?>" download="<?= h($featherFile['filename']) ?>">Feather</a> · <?= h(number_format($featherFile['bytes'])) ?> bytes · <?= h(number_format($featherFile['record_count'])) ?> public records. Its checksum is in <a href="<?= h($verifiedDataset['manifest_url']) ?>">this snapshot’s manifest</a>.</p>
    <?php endif; ?>
    <p>Parquet and Feather contain all columns from the public SQLite publications table, with snapshot provenance and the metadata table embedded in their schema metadata. Missing values remain null; dates remain recorded strings. Preserve the manifest with your analysis. Both use Zstandard compression. Parquet supports column-oriented analytical storage; Feather v2 uses the Arrow IPC file format. SQLite remains the website database.</p>
    <pre class="research-code"><code># R (install the arrow package first)
papers &lt;- arrow::read_parquet("publications.parquet")

# Python (install pyarrow first)
import pyarrow.parquet as pq
papers = pq.read_table("publications.parquet")
provenance = papers.schema.metadata[b"psilocybin_research_provenance"]

# Feather: R
papers &lt;- arrow::read_feather("publications.feather")

# Feather: Python
import pyarrow.feather as feather
papers = feather.read_table("publications.feather")</code></pre>
    <h3>Compressed SQLite</h3>
    <p><strong>SQLite · XZ</strong> decompresses to the exact SQLite file in the same snapshot, including its indexes. Verify the compressed file against its own manifest entry before extraction; the entry also gives the extracted SQLite size and checksum.</p>
    <p>Both compressed formats are built with the verified snapshot, not during your download. RDS uses serialization version 2; SQLite, CSV and JSON remain available for other software. The table and RDS button use one completed snapshot, while the <a href="dataset-manifest.json">latest manifest</a> may advance after this page was opened.</p>
    <h2>Live metadata and citations</h2>
    <ul>
      <li><a href="catalogue.php?format=txt" download="psilocybin_year_title.txt">All titles and years · TXT</a>: one record per line, including preprints and trial registrations; missing years are marked <code>n.d.</code>.</li>
      <li><a href="catalogue.php">Complete compact catalogue · JSON</a>: a smaller field set of titles, years, IDs, record status and source links for every visible record, with a verified total, completeness flag and UTC generation time. Use SQLite or the full metadata exports for broader fields. No pagination is needed. See the <a href="for-ai.html#retrieve-the-complete-catalogue">download and verification guide</a>.</li>
      <li><a href="database.php">SQLite</a>: the complete public metadata core, with publications and metadata tables.</li>
      <li><a href="export.php?format=csv&amp;range=all&amp;limit=all">CSV</a> and <a href="export.php?format=json&amp;range=all&amp;limit=all">JSON</a>: tabular and programmatic metadata reuse (all matching records by default; SQLite contains the full core).</li>
      <li><a href="export.php?format=bibtex&amp;range=all&amp;limit=all">BibTeX</a>, <a href="export.php?format=ris&amp;range=all&amp;limit=all">RIS</a> and <a href="export.php?format=latex&amp;range=all&amp;limit=all">LaTeX</a>: citation workflows. Verify each original work before citing it.</li>
      <li><a href="./#publication-results">Search and filter publications</a> before exporting a smaller evidence set.</li>
      <li><a href="linked-data.php?resource=records">JSON-LD research entities</a>: paginated linked data with stable identifiers, source relationships and record-status distinctions. The <a href="linked-data.php?resource=vocabulary">local discovery vocabulary</a> provides Schema.org/SKOS terms; its classifications are screening aids, not verified medical-ontology equivalences.</li>
      <li><a href="api.php?resource=papers&amp;per_page=20&amp;page=1">JSON API example</a>: retrieve a paginated result set; increment <code>page</code> to continue. See the <a href="about.php">application overview</a> for access routes.</li>
    </ul>
    <h3>Keep an exact, verifiable research input</h3>
    <p><a class="secondary" href="export.php?format=bundle&amp;range=all&amp;limit=all">Download reproducible research bundle (ZIP)</a></p>
    <p>The bundle contains the same records as CSV, JSON and BibTeX, plus a dated manifest, exported record IDs, query filters and SHA-256 checksums. Preserve the archive with your analysis. A download from the live catalogue is separate from the fixed Zenodo snapshot and does not create a new DOI.</p>
    <p>For a filtered bundle, use the normal <code>export.php</code> search parameters with <code>format=bundle</code>; <code>ids[]</code> selects particular public records. The manifest flags an export that reaches its requested record limit.</p>
    <h3>Analyse this dataset</h3><p><a href="/r-analysis/#r-package">Install the psilocybinresearch R package</a> to download and verify snapshots in R. For a guided browser interface, use the <a href="/shinyapp-live">Shinylive research explorer</a> to filter a dated snapshot, view publication trends and export matching records. For custom R code and report exports, open the <a href="/webr">webR workspace</a>. All three routes are freely accessible.</p>
    <h3>Reproduce a publication-year table</h3>
    <p>Run this SQL on the public SQLite download. It separates record status and excludes rows without a recorded year, rather than counting every registration as a published study:</p>
    <pre class="research-code"><code>SELECT publication_year, publication_status, COUNT(*) AS records
FROM publications
WHERE publication_year IS NOT NULL
GROUP BY publication_year, publication_status
ORDER BY publication_year DESC, publication_status;</code></pre>
    <p>The <a href="tools/psilocybin_bibliometrics_visnetwork.R" download>downloadable R bibliometrics toolkit</a> produces summary tables, figures and a citation network from the public database. Compare your outputs with the <a href="research-trends.php">live research-trends tables</a> and explore relationships in the <a href="citation-network.php">citation network</a>.</p>
  </section>
  <section class="panel detail-card" aria-labelledby="worked-examples">
    <h2 id="worked-examples">Reproduce the same table in R, Python and SQL</h2>
    <p>These examples calculate annual publication-status counts from one verified snapshot and compare them with its frozen statistics. Both workflows preserve inputs, the manifest and analysis provenance in a new folder. Share the same saved manifest to compare languages.</p>
    <ul>
      <li><a href="tools/reproducible/annual_counts.ipynb" download>Python notebook</a>: self-contained, using only Python’s standard library.</li>
      <li><a href="tools/reproducible/annual_counts.py" download>Python script</a> and <a href="tools/reproducible/annual-counts.sql.txt" download>SQL query</a>: save both together; Python downloads the verified SQLite file and executes this SQL.</li>
      <li><a href="tools/reproducible/annual_counts.R" download>R script</a>: opens the verified RDS and calculates the same table. Requires <code>jsonlite</code> and <code>digest</code>.</li>
    </ul>
    <pre class="research-code"><code>python3 annual_counts.py --output analysis-python
Rscript annual_counts.R analysis-r analysis-python/dataset-manifest.json</code></pre>
    <p>Each workflow produces <code>annual-counts.csv</code> with year, total, published, preprints, trial registrations and other-status columns. Missing years have a separate blank-year row, including when zero. Existing folders are never overwritten. SHA-256, byte sizes and record counts are verified before success is reported.</p>
    <p>Counts describe indexed records, not independent studies or participants; the current year is partial. Published is the stored status, not independent peer-review verification. Preserve downloaded inputs beyond the website’s three-snapshot retention if you need to repeat an old analysis.</p>
    <p><a href="docs/research-task-benchmark.json">Dated AI research-task benchmark</a>: actual fresh connected conversations, checked answers and explicit network/download limitations.</p>
    <h3>Keep reports from the same study in context</h3>
    <p><a href="api/v1/study-links">Reviewed study relationships</a> link selected registry records, protocols/preprints, primary reports and follow-ups using explicit source evidence. The <code>study-links</code> manifest artifact freezes these links with the same snapshot. Initial coverage is three checked families, not an exhaustive count of independent cohorts. Do not infer independence from a missing link.</p>
  </section>
  <section class="panel detail-card">
    <h2>Source coverage and public fields</h2>
    <p>The acquisition workflow combines PubMed, Crossref, Europe PMC, OpenAlex, medRxiv, bioRxiv, PsyArXiv and ClinicalTrials.gov. A record can be enriched from several databases; the retained source label is not a count of every database that contains that work.</p>
    <p>The public core retains titles, authors or sponsors, venues, dates, DOI and PubMed identifiers, source links, status, derived topic and study-type tags, text-availability indicators and allowlisted factual source provenance. <strong>It does not redistribute unverified abstracts, registry descriptions, source-derived keywords or unrestricted importer payloads.</strong> An abstract-availability flag reports that text was observed during processing; it grants no reuse permission.</p>
    <p>CC BY 4.0 applies to compiler-held rights in selection and arrangement, normalization, original annotations, validation outputs and documentation. It does not relicense third-party titles, bibliographic material or source text. Check the original source and its licence for any further reuse.</p>
    <h3>How to interpret coverage</h3>
    <p>Coverage is broad but not exhaustive. Search terminology, source indexing, metadata completeness and update delays affect capture. Preprints have not been peer reviewed. Trial registrations describe planned research and do not establish efficacy or safety. Derived classifications support discovery and require verification for systematic-review inclusion.</p>
    <p>See the <a href="evidence.php">evidence map</a>, <a href="trials.php">trial tracker</a>, <a href="recruiting-psilocybin-clinical-trials/">recruiting psilocybin trials</a> and <a href="editorial-policy.php">editorial policy</a> for context and matching limitations.</p>
  </section>
  <section class="panel detail-card">
    <h2>Cite the compilation and the original studies</h2>
    <p>Germann, C. B. <cite>An integrated living dataset of psilocybin and psilocin publications, preprints, and trial records</cite> (version 1.1.0). Zenodo. <a href="https://doi.org/10.5281/zenodo.24051198">https://doi.org/10.5281/zenodo.24051198</a>.</p>
    <p>Citing the compilation does not replace citations to the studies you analyze. When using the moving live index, identify it as the Psilocybin Research Database, state the retrieval date and archive the exact download used in your analysis.</p>
  </section>
  <section class="panel detail-card" id="api">
    <h2>Free research API and network exports</h2>
    <p><a href="for-ai.html#connect-using-mcp">Connect an AI research client using MCP</a> to search records, retrieve source-linked metadata, query annual statistics and find these verified downloads. Use <code>https://psilocybin-research.com/mcp</code> with Streamable HTTP and no authentication. See the <a href="research-api.php">API and AI access instructions</a> for examples.</p>
    <p>Public research endpoints require no API key or subscription. Normal workload limits apply. Keep a dated local copy for reproducibility and verify identifiers at their original source.</p>
    <ul>
      <li><a href="api.php?resource=research_network&amp;full=1">Full co-authorship network</a>: <code>nodes</code> have numeric graph IDs and shared name-based <code>entity_id</code> URIs. Full-export <code>edges</code> are compact triples <code>[source_node_id, target_node_id, shared_record_ids]</code>; the length of the third element gives the shared-work weight. Graph IDs are local to that export. Use <code>q</code> or <code>topic</code> to narrow the network; <code>limit</code> bounds a visual view, while <code>full=1</code> exports all matching names and their neighbourhood.</li>
      <li><a href="api.php?resource=research_trials">Trial research facts</a>: filter with exact <code>country</code>, <code>sponsor</code>, <code>phase</code>, <code>status</code> or <code>condition</code>; <code>q</code> searches title, sponsor, identifier and conditions. The response includes matching records, descriptive counts and available facets.</li>
      <li><a href="api.php?resource=research_results">Selected findings</a>: original educational annotations from the evidence guides, their original source-check dates and only visible indexed record links. Use <code>topic=depression</code> or <code>topic=alcohol-use-disorder</code>.</li>
      <li><code>api.php?resource=research_comparison&amp;ids[]=123&amp;ids[]=456</code>: retrieve up to 50 visible records and their allowlisted trial facts in one request.</li>
    </ul>
    <p>The <a href="research-hub.php">research tools</a> provide human-readable exploration and CSV exports. Author-name labels are not verified person identities; co-authorship does not establish influence or research quality.</p>
  </section>
</main>
<?= detail_footer() ?><?= detail_scroll_top() ?>
</body></html>
