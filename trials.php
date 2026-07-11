<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$filters = RequestFilters::fromGlobals();
if ((string)($filters['q'] ?? '') === '' && request_value('search') !== null) {
    $filters['q'] = request_value('search');
}
$trials = $repo->trials($filters, 150);
$assetVersion = '20260711-rights-safe-v87';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Clinical trial tracker | Psilocybin Research</title>
  <link rel="stylesheet" href="assets/styles.min.css?v=<?= h($assetVersion) ?>">
  <script src="assets/app.min.js?v=<?= h($assetVersion) ?>" defer></script>
</head>
<body class="detail-page">
<?= detail_page_header('trials') ?>
<main class="detail-shell">
  <section class="panel detail-hero">
    <span class="eyebrow">Trial tracker</span>
    <h1>Psilocybin and psilocin clinical trials</h1>
    <p>ClinicalTrials.gov records and trial-like literature are separated from published articles and preprints so study status, sponsors, indications, and linked publications can be reviewed quickly.</p>
    <form class="inline-search" method="get" action="trials.php">
      <input type="search" name="search" value="<?= h((string)$filters['q']) ?>" placeholder="Condition, sponsor, intervention, NCT ID">
      <button class="primary iconed" type="submit"><i data-icon="search" aria-hidden="true"></i><span>Search trials</span></button>
    </form>
  </section>
  <section class="panel detail-card">
    <div class="section-head-row">
      <h2><?= h(number_format((int)$trials['total'])) ?> trial records</h2>
      <a class="secondary iconed" href="export.php?format=csv&publication_statuses[]=clinical+trial"><i data-icon="table" aria-hidden="true"></i><span>Export trials</span></a>
    </div>
    <div class="compact-paper-list">
      <?php foreach ($trials['rows'] as $paper): ?>
        <?php
          $trialIdentifier = (string)($paper['doi'] ?? '');
          if (preg_match('/\bNCT\d{8}\b/i', (string)($paper['source_url'] ?? ''), $trialMatch)) {
              $trialIdentifier = strtoupper($trialMatch[0]);
          }
        ?>
        <a href="publication.php?id=<?= h((string)$paper['id']) ?>">
          <strong><?= h((string)$paper['title']) ?></strong>
          <span><?= h((string)($paper['authors'] ?: 'Sponsor unavailable')) ?> · <?= h((string)($paper['publication_date'] ?: 'Date unavailable')) ?> · <?= h($trialIdentifier) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<?= detail_scroll_top() ?>
</body>
</html>
