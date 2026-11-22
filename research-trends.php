<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$db = new Database();
$db->initialize();
$overview = ResearchOverview::summarize($db);
$totals = $overview['totals'];
$currentYear = (int)gmdate('Y');
$assetVersion = '20261011-particles-v231';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$title = 'Psilocybin Research Trends | Publication Counts by Year';
$description = 'Explore psilocybin and psilocin research counts by year, record status, source and study type, with reproducible downloads and bibliometric limitations.';
$robots = $_GET ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
if ($_GET) header('X-Robots-Tag: noindex, follow');
$percent = static fn(int $n): string => $totals['total'] > 0 ? number_format(100 * $n / $totals['total'], 1) . '%' : 'n/a';
$trendChartData = [
    'current_year' => $currentYear,
    'years' => array_map(static fn(array $row): array => [
        'year' => (int)$row['year'], 'total' => (int)$row['total'], 'published' => (int)$row['published'],
        'preprints' => (int)$row['preprints'], 'trials' => (int)$row['trials'], 'other' => (int)$row['other'],
    ], $overview['years']),
];
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <?= detail_page_seo($title, $description, 'research-trends.php', ['robots' => $robots]) ?>
  <?= json_ld_script(['@context' => 'https://schema.org', '@graph' => [
      ['@type' => 'CollectionPage', 'name' => $title, 'description' => $description, 'url' => $baseUrl . 'research-trends.php',
          'about' => ['@id' => $baseUrl . '#live-dataset'],
          'author' => LinkedData::maintainer(),
          'isPartOf' => ['@type' => 'WebSite', 'url' => $baseUrl, 'name' => 'Psilocybin Research']],
      ['@type' => 'BreadcrumbList', 'itemListElement' => [
          ['@type' => 'ListItem', 'position' => 1, 'name' => 'Research database', 'item' => $baseUrl],
          ['@type' => 'ListItem', 'position' => 2, 'name' => 'Research trends', 'item' => $baseUrl . 'research-trends.php']]],
  ]]) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head><body class="detail-page">
<?= detail_page_header('research-trends') ?>
<main class="detail-shell" id="main">
  <section class="panel detail-hero">
    <span class="eyebrow">Living bibliometrics</span>
    <h1>Psilocybin and psilocin research trends</h1>
    <p>Explore how the <strong><?= h(number_format($totals['total'])) ?> visible records</strong> in this index are distributed by recorded year, publication status, retained source and study type. These tables measure this bibliographic corpus, not the entire research field or treatment effectiveness.</p>
    <p>Analysis reflects the current stored index and is refreshed when its bibliographic records change. The current calendar year (<?= h((string)$currentYear) ?>) is incomplete, and indexing delays also affect recent years. Records with future dates are shown explicitly where present.</p>
    <p>Methods and interpretation: <a href="editorial-policy.php#author">Dr. Christopher B. Germann</a> · <a href="research-dataset.php">Dataset, download and reproduction guide</a>.</p>
    <p>Watch <a href="research-in-motion/">Research in motion</a>: a 48-second looping visual introduction to sources, recorded years, topics, trial registrations and venues, frozen on 11 October 2026.</p>
    <p>For a frozen, independently reproducible analysis, read the <a href="psilocybin-research-brief-2026-10/">October 2026 research brief</a> with record-level analytical data, tables and checksums.</p>
  </section>
  <section class="panel detail-card" aria-labelledby="yearly-chart-heading">
    <span class="eyebrow">Interactive view</span>
    <h2 id="yearly-chart-heading">Research records over time</h2>
    <p>Choose a series, inspect values by year, or open a year’s underlying records from the accessible data table. Counts describe indexed records, not independent studies.</p>
    <label class="field"><span>Series</span><select data-research-trends-series aria-describedby="research-trends-chart-note">
      <option value="total">All indexed records</option><option value="published">Published records</option><option value="preprints">Preprints</option><option value="trials">Trial registrations</option><option value="other">Other records</option>
    </select></label>
    <p id="research-trends-chart-note" data-research-trends-note role="status" aria-live="polite">Preparing the chart…</p>
    <div id="research-trends-chart" class="research-trends-chart" aria-live="polite"><p>Preparing the chart…</p></div>
    <script id="research-trends-chart-data" type="application/json"><?= json_encode($trendChartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
  </section>
  <section class="panel detail-card">
    <h2>Research records by year and evidence status</h2>
    <p>Download these counts as <a href="api/v1/statistics/publications-by-year">JSON</a> or <a href="api/v1/statistics/publications-by-year?format=csv" download>CSV</a>, including the missing-year category. For a fixed input, use the annual statistics files in the <a href="research-dataset.php#verified-downloads">verified dataset snapshot</a>.</p>
    <p>Year comes from the stored publication or registry date. For a trial registration it is not necessarily the study start, completion or results-publication year. “Published” is the database's formal-publication label; it is not an independent peer-review audit. “Other” includes reviews, protocols and any uncategorized status.</p>
    <div class="research-table-wrap" role="region" aria-label="Scrollable research table" tabindex="0"><table class="research-table">
      <caption>Visible records with a recorded year; <?= h(number_format($totals['undated'])) ?> records without a year are excluded</caption>
      <thead><tr><th scope="col">Year</th><th scope="col">All records</th><th scope="col">Published</th><th scope="col">Preprints</th><th scope="col">Trial registrations</th><th scope="col">Other</th></tr></thead>
      <tbody><?php foreach ($overview['years'] as $row): ?>
        <tr><th scope="row"><a href="<?= h(tracker_query_url(['year' => (string)$row['year']])) ?>"><?= h((string)$row['year']) ?></a><?= (int)$row['year'] >= $currentYear ? ((int)$row['year'] === $currentYear ? ' (partial year)' : ' (future date)') : '' ?></th>
          <?php foreach (['total', 'published', 'preprints', 'trials', 'other'] as $key): ?><td><?= h(number_format((int)$row[$key])) ?></td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <p>Use the year links to inspect and export the underlying records. Compare completed calendar years, separate registrations and preprints, and account for backfills or curation before interpreting a change as research growth.</p>
  </section>
  <section class="panel detail-card">
    <h2>Source composition and identifier completeness</h2>
    <p><?= h(number_format($totals['with_doi'])) ?> records (<?= h($percent($totals['with_doi'])) ?>) have a DOI; <?= h(number_format($totals['with_pmid'])) ?> (<?= h($percent($totals['with_pmid'])) ?>) have a PubMed identifier. These groups overlap and must not be added. A missing DOI does not imply poor study quality; many registry records use an NCT identifier instead.</p>
    <div class="research-table-wrap" role="region" aria-label="Scrollable research table" tabindex="0"><table class="research-table">
      <caption>Retained primary source labels after deduplication and enrichment</caption>
      <thead><tr><th scope="col">Source</th><th scope="col">Records</th><th scope="col">Share of visible corpus</th></tr></thead>
      <tbody><?php foreach ($overview['sources'] as $row): ?><tr><th scope="row"><a href="<?= h(tracker_query_url(['sources' => [$row['source_name']]])) ?>"><?= h($row['source_name']) ?></a></th><td><?= h(number_format((int)$row['count'])) ?></td><td><?= h($percent((int)$row['count'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <p>Source shares describe the retained primary label, not acquisition yield or exclusive database coverage. The same work may exist in several upstream databases. OpenAlex enrichment can add identifiers or citation context while preserving a different primary source.</p>
  </section>
  <section class="panel detail-card">
    <h2>Study designs in the indexed literature</h2>
    <p>Study-type classifications are discovery aids derived from source metadata and screening rules. They do not evaluate bias, sample size, replication or certainty of evidence. Unclassified records are absent from this table.</p>
    <div class="research-table-wrap" role="region" aria-label="Scrollable research table" tabindex="0"><table class="research-table">
      <caption>Records by assigned study type</caption>
      <thead><tr><th scope="col">Study type</th><th scope="col">Records</th></tr></thead>
      <tbody><?php foreach ($overview['study_types'] as $row): ?><tr><th scope="row"><a href="<?= h(tracker_query_url(['study_type' => $row['study_type']])) ?>"><?= h($row['study_type']) ?></a></th><td><?= h(number_format((int)$row['count'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <p>Use the <a href="evidence.php">topic and study-type evidence map</a> for indication-specific discovery. Use the <a href="trials.php">clinical-trial tracker</a> and <a href="recruiting-psilocybin-clinical-trials/">recruiting-trial collection</a> for registry status rather than inferring recruitment from a publication year.</p>
  </section>
  <section class="panel detail-card">
    <h2>Reproduce and interpret this analysis</h2>
    <p>Tables use visible records only: hidden and false-positive records are excluded. Each record contributes once to its stored year and status. Unknown years are counted separately. Deduplication reduces duplicate manifestations but does not guarantee that every row represents an independent study; protocols, preprints and reports can describe related work.</p>
    <p>Download the <a href="database.php">public SQLite core</a> and run the SQL in the <a href="research-dataset.php">dataset guide</a>, or use the <a href="tools/psilocybin_bibliometrics_visnetwork.R" download>R bibliometrics toolkit</a>. Retain the download date and file checksum. Counts can change retrospectively when older records are added or duplicates are reconciled. The fixed <a href="https://doi.org/10.5281/zenodo.24051198">Zenodo v1.1.0 snapshot</a> has a different, immutable record set.</p>
    <p>For source selection and correction procedures see the <a href="editorial-policy.php">editorial policy</a>. For record relationships explore the <a href="citation-network.php">citation network</a> and <a href="authors.php">author directory</a>. These descriptive counts cannot establish clinical efficacy, safety or scientific consensus.</p>
    <p>For a source-linked example of a research trajectory, read the <a href="msca-impact/">psilocybin–telomere hypothesis and its MSCA research context</a>, available in 20 languages. It separates a hypothesis, independent preclinical experiments and public discussion; citation counts alone cannot establish scientific validity.</p>
  </section>
  <section class="panel detail-card" aria-labelledby="selected-evidence-heading">
    <h2 id="selected-evidence-heading">From research counts to study findings</h2>
    <p>Publication counts describe this corpus. To examine outcomes and limitations, read the selected primary-report comparisons:</p>
    <ul><?php foreach (ResearchEvidence::guides() as $guide): ?><li><a href="<?= h($guide['path']) ?>"><?= h($guide['label']) ?>: findings and limitations</a></li><?php endforeach; ?></ul>
    <p>These are selected educational comparisons, not exhaustive systematic reviews. Use the <a href="psilocybin-research-guide/">research reading checklist</a> to evaluate study design and source status.</p>
  </section>
</main>
<?= detail_footer() ?><?= detail_scroll_top() ?>
<script type="module" src="<?= h(FrontendAssets::url('assets/research-trends.js')) ?>"></script>
</body></html>
