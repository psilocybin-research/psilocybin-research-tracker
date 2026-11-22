<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$brief = json_decode((string)file_get_contents(__DIR__ . '/assets/research/2026-10-07/summary.json'), true, 512, JSON_THROW_ON_ERROR);
$path = 'psilocybin-research-brief-2026-10/';
$url = LinkedData::base() . $path;
$files = 'assets/research/2026-10-07/';
$assetVersion = '20261011-evidence-performance-v198';
$title = 'Psilocybin Research Brief: October 2026 Dataset Analysis';
$description = 'A reproducible October 2026 inventory of psilocybin and psilocin research records by year, status and source, with frozen CSV data, methods and checksums.';
$robots = $_GET ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
if ($_GET) header('X-Robots-Tag: noindex, follow');
$years = [];
foreach ($brief['years'] as $row) {
    $year = $row['year'] === null ? 'Unknown' : (string)$row['year'];
    $years[$year] ??= ['total' => 0, 'published' => 0, 'preprint' => 0, 'clinical trial' => 0, 'other' => 0];
    $key = in_array($row['status'], ['published', 'preprint', 'clinical trial'], true) ? $row['status'] : 'other';
    $years[$year][$key] += (int)$row['records'];
    $years[$year]['total'] += (int)$row['records'];
}
$percent = static fn(int $n): string => number_format(100 * $n / max(1, $brief['total']), 1) . '%';
$schema = LinkedData::document([
    ['@type' => 'WebPage', '@id' => $url . '#page', 'url' => $url, 'name' => $title, 'description' => $description, 'mainEntity' => ['@id' => $url . '#brief'], 'isPartOf' => ['@id' => LinkedData::base() . '#website']],
    ['@type' => 'Article', '@id' => $url . '#brief', 'url' => $url, 'headline' => $title, 'description' => $description,
        'datePublished' => '2026-10-07', 'dateModified' => '2026-10-07', 'inLanguage' => 'en',
        'author' => LinkedData::maintainer(), 'publisher' => LinkedData::maintainer(),
        'mainEntityOfPage' => ['@id' => $url . '#page'], 'about' => ['@id' => $url . '#analytical-data']],
    ['@type' => 'Dataset', '@id' => $url . '#analytical-data', 'url' => $url . '#downloads',
        'name' => 'Psilocybin Research Database analytical metadata, 7 October 2026',
        'description' => 'Frozen analytical metadata for 5,076 visible bibliographic and registry records. Includes record identifiers, year, status, source and study type; excludes source text. Descriptive inventory, not an efficacy dataset.',
        'creator' => LinkedData::maintainer(), 'datePublished' => '2026-10-07', 'isAccessibleForFree' => true,
        'license' => LinkedData::base() . 'data-protection.php#public-data-rights',
        'variableMeasured' => ['record ID', 'recorded year', 'publication status', 'primary source label', 'study type', 'DOI', 'PubMed ID'],
        'prov:wasDerivedFrom' => ['@id' => LinkedData::base() . '#live-dataset'],
        'distribution' => [
            ['@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => LinkedData::base() . $files . 'records.csv'],
            ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => LinkedData::base() . $files . 'summary.json'],
        ]],
    LinkedData::website(),
    ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Research database', 'item' => LinkedData::base()],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Research trends', 'item' => LinkedData::base() . 'research-trends.php'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'October 2026 brief', 'item' => $url],
    ]],
]);
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base href="/">
  <title><?= h($title) ?></title>
  <?= detail_page_seo($title, $description, $path, ['robots' => $robots, 'type' => 'article', 'image' => LinkedData::base() . $files . 'records-by-year.png', 'image_width' => 1200, 'image_height' => 700, 'image_alt' => 'Recorded-year cohorts in the October 2026 index, separated by publication status']) ?>
  <?= json_ld_script($schema) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head><body class="detail-page evidence-guide-page">
<?= detail_page_header('research-trends') ?>
<main class="detail-shell" id="main">
  <nav class="recruiting-breadcrumbs" aria-label="Breadcrumb"><a href="./">Research database</a><span aria-hidden="true">/</span><a href="research-trends.php">Research trends</a><span aria-hidden="true">/</span><span aria-current="page">October 2026 brief</span></nav>
  <article>
    <header class="panel detail-hero evidence-guide-hero">
      <span class="eyebrow">Original corpus analysis · Frozen tables</span>
      <h1>Psilocybin research in October 2026: a reproducible inventory</h1>
      <p class="evidence-guide-lead">The public metadata core downloaded on 7 October 2026 contained <strong><?= h(number_format($brief['total'])) ?> visible records</strong>: 4,315 labeled published, 450 preprints, 308 clinical-trial registrations and three protocols. This brief documents that compilation, with reusable analytical data and an executable reproduction script.</p>
      <p class="evidence-guide-meta">Analysis maintained by <a href="editorial-policy.php#author">Dr. Christopher B. Germann</a> · Input generated <time datetime="2026-10-07T11:29:04Z">7 October 2026, 11:29:04 UTC</time></p>
      <p class="evidence-guide-note">These are bibliographic records, not independent experiments or participants. Counts describe this index, not the entire field, scientific consensus or treatment effectiveness.</p>
      <div class="research-shortcuts"><a href="<?= h($path) ?>#downloads">Download data and reproduce</a><a href="<?= h($path) ?>#methods">Read the methods</a><a href="research-trends.php">See changing live counts</a></div>
    </header>

    <section class="panel detail-card">
      <h2>What kinds of records are in the compilation?</h2>
      <div class="research-table-wrap" role="region" aria-label="Scrollable record-status table" tabindex="0"><table class="research-table"><caption>Publication status in the frozen 5,076-record input</caption><thead><tr><th scope="col">Stored status</th><th scope="col">Records</th><th scope="col">Share</th></tr></thead><tbody>
      <?php foreach ($brief['statuses'] as $row): ?><tr><th scope="row"><?= h(ucfirst($row['label'])) ?></th><td><?= h(number_format($row['records'])) ?></td><td><?= h($percent($row['records'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <p>“Published” is a metadata status, not an independent audit of peer review. Preprints and registrations are distinct categories. A protocol and a later report can describe the same study; deduplication of bibliographic manifestations does not make every row an independent experiment.</p>
      <p>For example, the study-type label “Clinical Trial” appears on 977 records, while the publication-status category “clinical trial” contains 308 registrations. The fields answer different questions: design classification versus type of record. The two counts must not be treated as competing estimates of the number of trials.</p>
    </section>

    <section class="panel detail-card" id="years">
      <h2>Recorded years, with publication status kept separate</h2>
      <figure class="research-brief-figure"><img src="<?= h($files) ?>records-by-year.svg" width="1200" height="700" alt="Stacked record counts for 2020 through 2025 in the frozen index, separated into published records, preprints, trial registrations and other statuses. Exact counts follow in the table." loading="lazy"><figcaption>Completed calendar years 2020–2025 in the October 2026 input. The partial 2026 year is shown separately in the table; calendar completeness does not imply complete indexing. <a href="<?= h($files) ?>records-by-year.svg" download>Download SVG figure</a>.</figcaption></figure>
      <div class="research-table-wrap" role="region" aria-label="Scrollable year and status counts" tabindex="0"><table class="research-table"><caption>Recent recorded years; 2026 was incomplete at retrieval. Unknown year: <?= (int)$brief['unknown_year'] ?> records. All years are available in the CSV.</caption><thead><tr><th scope="col">Year</th><th scope="col">All records</th><th scope="col">Published</th><th scope="col">Preprints</th><th scope="col">Registrations</th><th scope="col">Other</th></tr></thead><tbody>
      <?php foreach (array_reverse($years, true) as $year => $row): if ((int)$year < 2020) continue; ?><tr><th scope="row"><?= h((string)$year) ?><?= (int)$year === 2026 ? ' (partial)' : '' ?></th><?php foreach (['total', 'published', 'preprint', 'clinical trial', 'other'] as $key): ?><td><?= h(number_format($row[$key])) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      </tbody></table></div>
      <p>The 2025 recorded-year cohort contains 888 records, including 736 labeled published; the 2020 cohort contains 170, including 149 labeled published. These observed differences are features of this compilation. Search coverage, later backfills, source indexing and curation affect the counts, so this brief does not estimate a field-wide growth rate.</p>
    </section>

    <section class="panel detail-card">
      <h2>Source labels and identifier coverage</h2>
      <p><?= h(number_format($brief['identifiers']['doi'])) ?> records (<?= h($percent($brief['identifiers']['doi'])) ?>) have a DOI field and <?= h(number_format($brief['identifiers']['pubmed'])) ?> (<?= h($percent($brief['identifiers']['pubmed'])) ?>) have a PubMed identifier. The groups overlap. Presence is not an independent resolution or identity audit; missing DOI fields are expected for many registrations.</p>
      <div class="research-table-wrap" role="region" aria-label="Scrollable primary source table" tabindex="0"><table class="research-table"><caption>Retained primary source labels; one per visible record</caption><thead><tr><th scope="col">Source</th><th scope="col">Records</th><th scope="col">Share</th></tr></thead><tbody><?php foreach ($brief['sources'] as $row): ?><tr><th scope="row"><?= h($row['label']) ?></th><td><?= h(number_format($row['records'])) ?></td><td><?= h($percent($row['records'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
      <p>A work may occur in several upstream databases and receive enrichment from another source. The retained source label does not measure exclusive coverage, acquisition yield or the reliability of a study. <a href="research-dataset.php">Read the source and dataset guide</a>.</p>
    </section>

    <section class="panel detail-card" id="downloads">
      <span class="eyebrow">Reusable analytical data</span><h2>Download the frozen input and reproduce every table</h2>
      <ul class="research-downloads">
        <li><a href="<?= h($files) ?>records.csv" download>Record-level analytical CSV</a>: all <?= h(number_format($brief['total'])) ?> rows, with record ID, year, status, source, study type, DOI and PubMed ID. It contains no abstracts or registry descriptions.</li>
        <li><a href="<?= h($files) ?>years.csv" download>All-year status counts</a>, <a href="<?= h($files) ?>statuses.csv" download>status totals</a>, <a href="<?= h($files) ?>sources.csv" download>source totals</a> and <a href="<?= h($files) ?>study_types.csv" download>study-type totals</a>.</li>
        <li><a href="<?= h($files) ?>summary.json" download>JSON summary and input checksums</a>.</li>
        <li><a href="tools/research_brief.py" download>Python reproduction script</a>: standard library only; no package installation needed.</li>
        <li><a href="tools/research_brief_figure.py" download>Figure reproduction script</a>: uses Matplotlib to create the SVG and PNG from <code>summary.json</code>.</li>
      </ul>
      <p>Save the CSV and script in the same directory, then run:</p>
      <pre class="research-code"><code>python3 research_brief.py --records records.csv --output reproduced</code></pre>
      <p>The resulting CSV summaries reproduce the frozen tables. To analyze a newer public SQLite download instead, run <code>python3 research_brief.py --database downloaded.sqlite --output current</code>. New live input can yield different counts.</p>
      <dl class="research-integrity"><dt>Published analytical CSV SHA-256</dt><dd><code><?= h($brief['record_metadata_sha256']) ?></code></dd><dt>Original public SQLite download SHA-256</dt><dd><code><?= h($brief['snapshot_sha256']) ?></code></dd></dl>
      <p>The CSV is a reduced analytical input, not a replacement for the full bibliographic database or an immutable Zenodo deposit. Compiler-held selection, arrangement and original analysis follow the <a href="data-protection.php#public-data-rights">scoped rights notice</a>; third-party material is not relicensed.</p>
    </section>

    <section class="panel detail-card" id="methods">
      <h2>Methods, limitations and citation</h2>
      <p>The input is the rights-sanitized SQLite core served by <a href="database.php">database.php</a>, retrieved on 7 October 2026. That public core already excludes hidden and false-positive records. Every remaining row contributes once to its stored year and status. Empty classification fields are grouped as “Unclassified”; missing years are counted separately. Study labels are automatic discovery aids and do not establish independently verified design or certainty of evidence.</p>
      <p>The reproduction script reads an explicit allowlist of analytical fields and calculates counts without opening abstracts, keywords, registry descriptions or raw importer payloads. The input hash identifies the exact downloaded bytes. The separately hashed analytical CSV allows readers to reproduce the reported aggregates even after the live index changes.</p>
      <p>Related papers, protocols and registrations can remain separate legitimate records. Recent publication years are affected by future issue dates and indexing delays. No patient-level observations, treatment-effect estimates, causal conclusions or comprehensive-coverage claims follow from these counts.</p>
      <p><strong>Suggested citation:</strong> Germann, C. B. <cite>Psilocybin research in October 2026: a reproducible inventory</cite>. Psilocybin Research Database, 7 October 2026. <?= h($url) ?>. Cite the analytical CSV checksum when reproducing these tables. This web research brief is not a peer-reviewed journal article.</p>
      <div class="research-shortcuts"><a href="research-trends.php">Live research trends</a><a href="research-dataset.php">Dataset citation and reuse</a><a href="psilocybin-depression-research/">Interpret depression evidence</a><a href="editorial-policy.php#corrections-heading">Methods and corrections</a></div>
    </section>
  </article>
</main>
<?= detail_footer() ?><?= detail_scroll_top() ?>
</body></html>
