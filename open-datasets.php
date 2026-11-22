<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repository = new OpenDatasetRepository($db);
$filters = [
    'q' => trim((string)request_value('q', '')),
    'topic' => trim((string)request_value('topic', '')),
    'repository' => trim((string)request_value('repository', '')),
    'access' => trim((string)request_value('access', '')),
    'sort' => trim((string)request_value('sort', 'newest')),
    'per_page' => trim((string)request_value('per_page', '24')),
    'page' => max(1, (int)request_value('page', '1')),
];
$results = $repository->search($filters);
$stats = $repository->stats();
$repositories = $repository->repositories();
$topics = array_slice($repository->topics(), 0, 40);
$lastVerifiedAt = $repository->lastVerifiedAt();
$assetVersion = '20261011-evidence-performance-v198';
$pageTitle = 'Open Psilocybin Research Datasets | Repository Index';
$description = 'Find verified open datasets linked to psilocybin and psilocin research papers. Filter repository records by topic, access, licence, and publication.';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$hasFilters = array_filter([$filters['q'], $filters['topic'], $filters['repository'], $filters['access']]) || $filters['page'] > 1 || $filters['sort'] !== 'newest';
$indexAffectingQueryKeys = array_diff(array_keys($_GET), ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid']);
$robots = $indexAffectingQueryKeys !== [] ? 'noindex, follow, max-image-preview:large' : 'index, follow, max-image-preview:large, max-snippet:-1';
if ($indexAffectingQueryKeys !== []) header('X-Robots-Tag: noindex, follow');
$queryUrl = static function (array $changes) use ($filters): string {
    $query = array_merge($filters, $changes);
    $query = array_filter($query, static fn(mixed $value, string $key): bool => $value !== '' && !($key === 'page' && (int)$value === 1), ARRAY_FILTER_USE_BOTH);
    return 'open-datasets.php' . ($query ? '?' . http_build_query($query) : '');
};
$items = [];
$paperRepository = new PublicationRepository($db);
$trialModel = new TrialMapService($db);
foreach ($results['rows'] as $position => $row) {
    $paper = $paperRepository->publicById((int)$row['publication_id']);
    $relatedWork = $paper ? LinkedData::record($paper, [], [], $paper['publication_status'] === 'clinical trial' ? $trialModel->forPublication((int)$paper['id']) : null) : null;
    $datasetSchema = array_filter([
        '@type' => 'Dataset',
        '@id' => LinkedData::uri((string)$row['dataset_url']),
        'name' => (string)($row['dataset_title'] ?: $row['repository'] . ' dataset linked to ' . $row['paper_title']),
        'url' => (string)$row['dataset_url'],
        'identifier' => !empty($row['dataset_doi']) ? ['@type' => 'PropertyValue', 'propertyID' => 'DOI', 'value' => (string)$row['dataset_doi']] : null,
        'license' => (string)($row['license_url'] ?: $row['license_name']),
        'dct:relation' => $relatedWork ? array_intersect_key($relatedWork, array_flip(['@type', '@id', 'name', 'url'])) : null,
        'includedInDataCatalog' => ['@type' => 'DataCatalog', 'name' => 'Psilocybin Research Open Datasets'],
    ]);
    $items[] = ['@type' => 'ListItem', 'position' => (($results['page'] - 1) * (is_numeric($results['per_page']) ? (int)$results['per_page'] : 0)) + $position + 1, 'item' => $datasetSchema];
}
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => 'Open psilocybin research datasets',
    'description' => $description,
    'url' => $baseUrl . 'open-datasets.php',
    'mainEntity' => [
        '@type' => 'DataCatalog',
        'name' => 'Psilocybin Research Open Datasets',
        'description' => 'A validated, paper-linked index of openly reachable datasets associated with psilocybin and psilocin research.',
        'url' => $baseUrl . 'open-datasets.php',
        'dataset' => array_column($items, 'item'),
    ],
    'hasPart' => ['@type' => 'ItemList', 'numberOfItems' => $results['total'], 'itemListElement' => $items],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <?= detail_page_seo($pageTitle, $description, 'open-datasets.php', ['robots' => $robots]) ?>
  <?= json_ld_script($schema) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head>
<body class="detail-page datasets-page">
<?= detail_preloader('Opening dataset index…', 'Loading open datasets') ?>
<?= detail_page_header('open-datasets') ?>
<main class="detail-shell dataset-shell">
  <section class="panel dataset-hero">
    <div class="dataset-hero-copy">
      <span class="eyebrow">Reusable research data</span>
      <h1>Open psilocybin research datasets</h1>
      <p>Move from a paper to its reusable data without searching repositories one by one. Every visible link has passed resource-type and access checks; uncertain matches stay quarantined for review.</p>
      <div class="dataset-hero-actions">
        <a class="primary iconed" href="#dataset-results"><i data-icon="database" aria-hidden="true"></i><span>Browse datasets</span></a>
        <a class="secondary iconed" href="api.php?resource=datasets" target="_blank" rel="noopener"><i data-icon="braces" aria-hidden="true"></i><span>Use datasets API</span></a>
      </div>
    </div>
    <dl class="dataset-stat-grid" aria-label="Dataset index summary">
      <div><dt><?= h(number_format($stats['datasets'])) ?></dt><dd>verified datasets</dd></div>
      <div><dt><?= h(number_format($stats['papers'])) ?></dt><dd>linked papers</dd></div>
      <div><dt><?= h(number_format($stats['repositories'])) ?></dt><dd>repositories</dd></div>
      <div><dt><?= h(number_format($stats['licensed_open'])) ?></dt><dd>licence-confirmed</dd></div>
    </dl>
  </section>

  <section class="panel dataset-workbench" aria-labelledby="dataset-filter-title">
    <div class="section-head-row">
      <div><span class="eyebrow">Research workbench</span><h2 id="dataset-filter-title">Find data by question, topic, or repository</h2></div>
      <?php if ($hasFilters): ?><a class="secondary" href="open-datasets.php">Clear filters</a><?php endif; ?>
    </div>
    <form class="dataset-filter-grid" action="open-datasets.php" method="get">
      <label class="dataset-search-field"><span>Search datasets and linked papers</span><input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Search title, author, DOI…"></label>
      <label><span>Topic</span><select name="topic"><option value="">All topics</option><?php foreach ($topics as $topic): ?><option value="<?= h((string)$topic['name']) ?>" <?= $filters['topic'] === $topic['name'] ? 'selected' : '' ?>><?= h((string)$topic['name']) ?> (<?= h((string)$topic['count']) ?>)</option><?php endforeach; ?></select></label>
      <label><span>Repository</span><select name="repository"><option value="">All repositories</option><?php foreach ($repositories as $source): ?><option value="<?= h((string)$source['name']) ?>" <?= $filters['repository'] === $source['name'] ? 'selected' : '' ?>><?= h((string)$source['name']) ?> (<?= h((string)$source['count']) ?>)</option><?php endforeach; ?></select></label>
      <label><span>Access evidence</span><select name="access"><option value="">All public data</option><option value="open" <?= $filters['access'] === 'open' ? 'selected' : '' ?>>Open licence confirmed</option><option value="public" <?= $filters['access'] === 'public' ? 'selected' : '' ?>>Publicly reachable</option></select></label>
      <label><span>Order</span><select name="sort"><option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Newest paper first</option><option value="topic" <?= $filters['sort'] === 'topic' ? 'selected' : '' ?>>Topic A–Z</option><option value="repository" <?= $filters['sort'] === 'repository' ? 'selected' : '' ?>>Repository A–Z</option><option value="confidence" <?= $filters['sort'] === 'confidence' ? 'selected' : '' ?>>Strongest link first</option></select></label>
      <input type="hidden" name="per_page" value="<?= h($filters['per_page']) ?>">
      <button class="primary iconed" type="submit"><i data-icon="search" aria-hidden="true"></i><span>Apply filters</span></button>
    </form>
    <?php if ($topics): ?><div class="dataset-topic-shortcuts" aria-label="Popular dataset topics"><?php foreach (array_slice($topics, 0, 8) as $topic): ?><a href="<?= h($queryUrl(['topic' => $topic['name'], 'page' => 1])) ?>"><?= h((string)$topic['name']) ?><span><?= h((string)$topic['count']) ?></span></a><?php endforeach; ?></div><?php endif; ?>
  </section>

  <section id="dataset-results" class="dataset-results" aria-labelledby="dataset-results-title">
    <div class="dataset-results-head">
      <div><span class="eyebrow">Verified index</span><h2 id="dataset-results-title"><?= h(number_format($results['total'])) ?> open dataset<?= $results['total'] === 1 ? '' : 's' ?></h2></div>
      <p><?= $lastVerifiedAt ? 'Repository metadata last verified ' . h(gmdate('M j, Y', strtotime($lastVerifiedAt))) . '.' : 'The index is awaiting its first verified dataset.' ?></p>
    </div>
    <?php if (!$results['rows']): ?>
      <div class="panel dataset-empty"><h3>No verified datasets match these filters</h3><p>Try a broader topic or clear the filters. Candidate links are not exposed until their resource type and access have been confirmed.</p></div>
    <?php else: ?>
      <div class="dataset-grid">
      <?php foreach ($results['rows'] as $row): $datasetTitle = (string)($row['dataset_title'] ?: $row['repository'] . ' dataset'); ?>
        <article class="panel dataset-card">
          <div class="dataset-card-top"><span class="dataset-repository"><?= h((string)$row['repository']) ?></span><span class="dataset-access <?= h((string)$row['access_status']) ?>"><?= $row['access_status'] === 'open' ? 'Open licence' : 'Public access' ?></span></div>
          <h3><a href="<?= h((string)$row['dataset_url']) ?>" target="_blank" rel="noopener"><?= highlight_search_terms($datasetTitle, $filters['q']) ?></a></h3>
          <div class="dataset-facts">
            <?php if ($row['dataset_doi']): ?><span><strong>DOI</strong> <?= h((string)$row['dataset_doi']) ?></span><?php endif; ?>
            <?php if ($row['license_name']): ?><span><strong>Licence</strong> <?= $row['license_url'] ? '<a href="' . h((string)$row['license_url']) . '" target="_blank" rel="noopener">' . h((string)$row['license_name']) . '</a>' : h((string)$row['license_name']) ?></span><?php endif; ?>
            <span><strong>Link</strong> <?= h(ucfirst((string)$row['relation_type'])) ?></span>
          </div>
          <div class="dataset-paper-link"><span>Linked publication</span><a href="publication.php?id=<?= h((string)$row['publication_id']) ?>"><?= highlight_search_terms((string)$row['paper_title'], $filters['q']) ?></a><small><?= h((string)($row['paper_authors'] ?: 'Unknown authors')) ?><?= $row['publication_date'] ? ' · ' . h(substr((string)$row['publication_date'], 0, 4)) : '' ?></small></div>
          <?php $rowTopics = split_tag_values((string)$row['topic_tags'], 5); if ($rowTopics): ?><div class="dataset-tags"><?php foreach ($rowTopics as $topic): ?><a href="<?= h($queryUrl(['topic' => $topic, 'page' => 1])) ?>"><?= h($topic) ?></a><?php endforeach; ?></div><?php endif; ?>
          <a class="dataset-open-link iconed" href="<?= h((string)$row['dataset_url']) ?>" target="_blank" rel="noopener"><span>Open in <?= h((string)$row['repository']) ?></span><i data-icon="external-link" aria-hidden="true"></i></a>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($results['pages'] > 1): ?><nav class="dataset-pagination" aria-label="Dataset result pages"><?php if ($results['page'] > 1): ?><a href="<?= h($queryUrl(['page' => $results['page'] - 1])) ?>">Previous</a><?php endif; ?><span>Page <?= h((string)$results['page']) ?> of <?= h((string)$results['pages']) ?></span><?php if ($results['page'] < $results['pages']): ?><a href="<?= h($queryUrl(['page' => $results['page'] + 1])) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
  </section>

  <section class="panel dataset-method">
    <div><span class="eyebrow">Trust and provenance</span><h2>Useful because uncertain links stay out</h2></div>
    <div class="dataset-method-grid"><p><strong>Verified dataset</strong><span>The repository identifies the object as a dataset or collection. Known non-data records are rejected.</span></p><p><strong>Access is explicit</strong><span>“Open licence” means licence metadata was found. “Public access” means reachable data without a confirmed licence; reuse terms still require checking.</span></p><p><strong>Paper-linked and current</strong><span>Links are discovered from stored source metadata and data-availability statements, checked against repository metadata, and rescanned after publication updates.</span></p></div>
    <?php if ($stats['candidates'] > 0): ?><p class="dataset-quarantine-note"><?= h(number_format($stats['candidates'])) ?> ambiguous candidate<?= $stats['candidates'] === 1 ? ' is' : 's are' ?> currently quarantined and excluded from this public index.</p><?php endif; ?>
  </section>
</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
</body>
</html>
