<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$rows = $repo->evidenceMap();
$assetVersion = '20261011-server-evidence-v199';
$pageTitle = 'Psilocybin Evidence Map | Research by Topic and Study Type';
$description = 'Explore psilocybin and psilocin evidence by indication, study type, substance, and year in a source-linked bibliographic matrix.';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <?= detail_page_seo($pageTitle, $description, 'evidence.php') ?>
  <?= json_ld_script(['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $pageTitle, 'description' => $description, 'url' => $baseUrl . 'evidence.php', 'numberOfItems' => count($rows), 'isPartOf' => ['@type' => 'WebSite', 'name' => 'Psilocybin Research', 'url' => $baseUrl]]) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head>
<body class="detail-page">
<?= detail_page_header('evidence') ?>
<main class="detail-shell">
  <section class="panel detail-hero">
    <div class="detail-hero-intro">
      <div><span class="eyebrow">Evidence map</span><h1>Indication, study type, substance, and year matrix</h1><p>Scan where the indexed psilocybin and psilocin literature is dense, sparse, clinical, preclinical, review-driven, or emerging.</p></div>
    </div>
  </section>
  <section class="panel detail-card">
    <span class="eyebrow">Read the research in context</span><h2>Research findings and interpretation</h2>
    <p>The <a href="psilocybin-depression-research/">psilocybin depression evidence guide</a> compares eight selected randomized reports by population, comparator, sample, primary endpoint and follow-up, including positive and inconclusive 2026 findings. It separates published results from sponsor announcements.</p>
    <p>Compare the <a href="psilocybin-alcohol-use-disorder-research/">alcohol use disorder evidence</a> or use the <a href="psilocybin-research-guide/">research-reading checklist</a> to interpret study designs, endpoints and uncertainty.</p>
    <p>This map counts classified records. The guide interprets selected reports; neither is a complete systematic review. For planned and ongoing studies use the <a href="psilocybin-clinical-trials-depression/">depression trial collection</a>.</p>
  </section>
  <section class="panel detail-card">
    <div class="section-head-row">
      <h2 class="icon-heading"><i data-icon="table" aria-hidden="true"></i><span><?= h(number_format(count($rows))) ?> evidence cells</span></h2>
      <a class="secondary iconed" href="api.php?resource=analytics" target="_blank" rel="noopener"><i data-icon="braces" aria-hidden="true"></i><span>Open analytics JSON</span></a>
    </div>
    <div class="evidence-map-table" role="table" aria-label="Evidence map" data-evidence-map>
      <div class="evidence-map-head" role="row">
        <button type="button" data-evidence-sort="topic" aria-sort="none">Topic <i data-icon="chevron-down" aria-hidden="true"></i></button>
        <button type="button" data-evidence-sort="study" aria-sort="none">Study type <i data-icon="chevron-down" aria-hidden="true"></i></button>
        <button type="button" data-evidence-sort="substance" aria-sort="none">Substance <i data-icon="chevron-down" aria-hidden="true"></i></button>
        <button type="button" data-evidence-sort="year" aria-sort="descending">Year <i data-icon="chevron-down" aria-hidden="true"></i></button>
        <button type="button" data-evidence-sort="count" aria-sort="none">Records <i data-icon="chevron-down" aria-hidden="true"></i></button>
      </div>
      <?php foreach (array_slice($rows, 0, 500) as $row): ?>
        <a class="evidence-map-row" role="row" href="<?= h(tracker_query_url(['topic' => $row['topic'], 'study_type' => $row['study_type'], 'substances' => [$row['substance']], 'year' => (string)$row['year']])) ?>" data-topic="<?= h(mb_strtolower((string)$row['topic'], 'UTF-8')) ?>" data-study="<?= h(mb_strtolower((string)$row['study_type'], 'UTF-8')) ?>" data-substance="<?= h(mb_strtolower((string)$row['substance'], 'UTF-8')) ?>" data-year="<?= h((string)$row['year']) ?>" data-count="<?= h((string)$row['count']) ?>">
          <span><?= h((string)$row['topic']) ?></span>
          <span><?= h((string)$row['study_type']) ?></span>
          <span><?= h((string)$row['substance']) ?></span>
          <span><?= h((string)$row['year']) ?></span>
          <strong><?= h((string)$row['count']) ?></strong>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="panel detail-card">
    <h2>Interpret and reuse the evidence map</h2>
    <p>Cell counts describe indexed records, not independent trials, effect sizes or certainty of evidence. A topic can include preclinical work, protocols, reviews and multiple reports from related studies. Verify the source, publication status and study design before using a cell for evidence synthesis.</p>
    <p>Inspect the <a href="research-trends.php">research-year and study-type tables</a>, reproduce your selection with the <a href="research-dataset.php">dataset download and citation guide</a>, and review the <a href="editorial-policy.php">classification and correction methods</a>. For registry-specific discovery use the <a href="trials.php">clinical-trial tracker</a> and <a href="psilocybin-clinical-trials-depression/">depression trial collection</a>.</p>
  </section>
  <section class="panel detail-card" aria-labelledby="selected-evidence-heading">
    <h2 id="selected-evidence-heading">Compare selected primary findings</h2>
    <p>Move from record counts to study findings with source-linked comparisons of populations, primary endpoints and limitations.</p>
    <ul><?php foreach (ResearchEvidence::guides() as $guide): ?><li><a href="<?= h($guide['path']) ?>"><?= h($guide['label']) ?>: findings and limitations</a></li><?php endforeach; ?></ul>
    <p>These are selected educational comparisons, not exhaustive systematic reviews. Use the <a href="psilocybin-research-guide/">research reading checklist</a> to evaluate study design and source status.</p>
  </section>
</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
</body>
</html>
