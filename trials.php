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
$assetVersion = '20261011-server-evidence-v199';
$pageTitle = 'Psilocybin Clinical Trials | Registry and Literature Tracker';
$description = 'Find psilocybin and psilocin clinical trials by condition, sponsor, intervention, recruitment status, registry identifier, and linked literature.';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$indexAffectingQueryKeys = array_diff(array_keys($_GET), ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid']);
$robots = $indexAffectingQueryKeys !== [] ? 'noindex, follow, noarchive' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
if ($indexAffectingQueryKeys !== []) header('X-Robots-Tag: noindex, follow, noarchive');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <?= detail_page_seo($pageTitle, $description, 'trials.php', ['robots' => $robots]) ?>
  <?= json_ld_script(['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $pageTitle, 'description' => $description, 'url' => $baseUrl . 'trials.php', 'numberOfItems' => (int)$trials['total'], 'about' => LinkedData::term('topic', 'Clinical Trial'), 'isPartOf' => ['@type' => 'WebSite', 'name' => 'Psilocybin Research', 'url' => $baseUrl]]) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head>
<body class="detail-page trials-index-page">
<?= detail_page_header('trials') ?>
<main class="detail-shell">
  <section class="panel detail-hero">
    <div class="detail-hero-intro">
      <div><span class="eyebrow">Trial tracker</span><h1>Psilocybin and psilocin clinical trials</h1><p>ClinicalTrials.gov records and trial-like literature are separated from published articles and preprints so study status, sponsors, indications, and linked publications can be reviewed quickly.</p></div>
    </div>
    <form class="inline-search" method="get" action="trials.php">
      <input type="search" name="search" value="<?= h((string)$filters['q']) ?>" placeholder="Condition, sponsor, intervention, NCT ID">
      <button class="primary iconed" type="submit"><i data-icon="search" aria-hidden="true"></i><span>Search trials</span></button>
    </form>
    <div class="detail-actions"><a class="secondary iconed" href="recruiting-psilocybin-clinical-trials/"><i data-icon="map-pin" aria-hidden="true"></i><span>Find recruiting psilocybin clinical trials</span></a></div>
  </section>
  <section class="panel detail-card">
    <div class="section-head-row">
      <h2 class="icon-heading"><i data-icon="clipboard-list" aria-hidden="true"></i><span><?= h(number_format((int)$trials['total'])) ?> trial records</span></h2>
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
  <section class="panel trial-discovery-section" aria-labelledby="trial-discovery-heading">
    <div class="section-head-row"><div><span class="eyebrow">Research pathways</span><h2 id="trial-discovery-heading">Explore curated psilocybin trial collections</h2><p>Browse live registry records by recruiting status, condition, country, or development phase. Each collection includes current counts, source links, and a prefiltered map.</p></div></div>
    <?= trial_discovery_links() ?>
  </section>
</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
</body>
</html>
