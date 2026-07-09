<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$rows = $repo->evidenceMap();
$assetVersion = '20260709-sidebar-r-v83';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Evidence map | Psilocybin Research</title>
  <link rel="stylesheet" href="assets/styles.min.css?v=<?= h($assetVersion) ?>">
  <script src="assets/app.min.js?v=<?= h($assetVersion) ?>" defer></script>
</head>
<body class="detail-page">
<?= detail_page_header('evidence') ?>
<main class="detail-shell">
  <section class="panel detail-hero">
    <span class="eyebrow">Evidence map</span>
    <h1>Indication, study type, substance, and year matrix</h1>
    <p>Scan where the indexed psilocybin and psilocin literature is dense, sparse, clinical, preclinical, review-driven, or emerging.</p>
  </section>
  <section class="panel detail-card">
    <div class="section-head-row">
      <h2><?= h(number_format(count($rows))) ?> evidence cells</h2>
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
</main>
<?= detail_scroll_top() ?>
</body>
</html>
