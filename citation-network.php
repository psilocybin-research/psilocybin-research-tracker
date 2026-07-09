<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);

$q = trim((string)request_value('q', ''));
$author = trim((string)request_value('author', ''));
$topic = trim((string)request_value('topic', ''));
$journal = trim((string)request_value('journal', ''));
$year = trim((string)request_value('year', ''));
$range = (string)request_value('range', 'all');
$from = trim((string)request_value('from', ''));
$to = trim((string)request_value('to', ''));
$selectedSources = request_array('sources');
$selectedStatuses = request_array('publication_statuses');
$selectedSubstances = request_array('substances') ?: ['psilocybin', 'psilocin'];
$paperId = (int)request_value('paper', '0');
$limit = max(1, min((int)request_value('limit', '1'), 48));

if ($range !== 'custom') {
    $from = match ($range) {
        'month' => gmdate('Y-m-d', strtotime('-1 month')),
        'year' => gmdate('Y-m-d', strtotime('-1 year')),
        '5y' => gmdate('Y-m-d', strtotime('-5 years')),
        default => '',
    };
    $to = '';
}

$filters = [
    'q' => $q !== '' ? $q : null,
    'author' => $author !== '' ? $author : null,
    'journal' => $journal !== '' ? $journal : null,
    'topic' => $topic !== '' ? $topic : null,
    'year' => ctype_digit($year) ? $year : null,
    'from' => $from !== '' ? $from : null,
    'to' => $to !== '' ? $to : null,
    'sources' => $selectedSources,
    'publication_statuses' => $selectedStatuses,
    'substances' => $selectedSubstances,
    'sort' => 'newest',
];
$graph = $repo->citationNetwork($filters, $paperId, $limit);
$topics = array_slice($repo->topics(), 0, 80);
$journals = $repo->journals();
$years = $repo->years();
$sources = $repo->sources();
$publicationStatuses = PublicationRepository::publicationStatusOptions();
$nodeTypeCounts = array_count_values(array_map(static fn(array $node): string => (string)($node['type'] ?? 'unknown'), $graph['nodes'] ?? []));
$activeFilterLabels = [];
if ($q !== '') $activeFilterLabels[] = 'Search: ' . $q;
if ($author !== '') $activeFilterLabels[] = 'Author: ' . $author;
if ($journal !== '') $activeFilterLabels[] = 'Journal: ' . $journal;
if ($topic !== '') $activeFilterLabels[] = 'Topic: ' . $topic;
if (ctype_digit($year)) $activeFilterLabels[] = 'Year: ' . $year;
if ($range !== 'all') $activeFilterLabels[] = $range === 'custom' ? 'Custom dates' : 'Range: ' . strtoupper($range);
if ($selectedSources) $activeFilterLabels[] = 'Sources: ' . implode(', ', $selectedSources);
if ($selectedStatuses) $activeFilterLabels[] = 'Status: ' . implode(', ', array_map(static fn(string $status): string => $publicationStatuses[$status] ?? $status, $selectedStatuses));
$hasActiveFilters = (bool)($activeFilterLabels || $paperId > 0 || $selectedSubstances !== ['psilocybin', 'psilocin']);
$assetVersion = '20260709-github-r-v82';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Citation Network | Psilocybin Research</title>
  <meta name="description" content="Explore citation, author, topic, journal, and DOI-reference relationships in the psilocybin and psilocin literature.">
  <link rel="stylesheet" href="assets/styles.min.css?v=<?= h($assetVersion) ?>">
  <script src="assets/app.min.js?v=<?= h($assetVersion) ?>" defer></script>
</head>
<body class="detail-page citation-network-page">
<?= detail_page_header('citation-network') ?>
<main class="detail-shell citation-network-shell">
  <section class="panel detail-hero citation-network-hero">
    <div class="citation-network-hero-copy">
      <span class="eyebrow">Citation network</span>
      <h1>Map the literature by citations, journals, authors, and topics</h1>
      <p>Build a focused graph from indexed psilocybin and psilocin publications. Narrow the network by time window, journal, source database, publication status, author, topic, substance, or a specific paper.</p>
    </div>
    <div class="citation-network-hero-stats" aria-label="Current citation network summary">
      <div><strong><?= h(number_format((int)$graph['stats']['seed_papers'])) ?></strong><span>seed papers</span></div>
      <div><strong><?= h(number_format((int)$graph['stats']['nodes'])) ?></strong><span>nodes</span></div>
      <div><strong><?= h(number_format((int)$graph['stats']['edges'])) ?></strong><span>connections</span></div>
      <div><strong><?= h(number_format((int)($nodeTypeCounts['journal'] ?? 0))) ?></strong><span>journals</span></div>
    </div>
    <form class="citation-network-form" method="get">
      <div class="citation-filter-primary">
        <label class="field citation-search-field">
          <span>Search terms, DOI, or PMID</span>
          <input type="search" name="q" value="<?= h($q) ?>" placeholder="depression, telomere, Carhart-Harris, 10.1038/...">
        </label>
        <label class="field">
          <span>Author</span>
          <input type="search" name="author" value="<?= h($author) ?>" placeholder="Optional author">
        </label>
        <label class="field">
          <span>Journal</span>
          <select name="journal">
            <option value="">All journals</option>
            <?php foreach ($journals as $journalRow): ?>
              <option value="<?= h((string)$journalRow['journal']) ?>" <?= $journal === (string)$journalRow['journal'] ? 'selected' : '' ?>><?= h((string)$journalRow['journal']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Topic</span>
          <select name="topic">
            <option value="">All topics</option>
            <?php foreach ($topics as $topicRow): ?>
              <option value="<?= h((string)$topicRow['name']) ?>" <?= $topic === (string)$topicRow['name'] ? 'selected' : '' ?>><?= h((string)$topicRow['name']) ?> (<?= h((string)$topicRow['count']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <details class="citation-filter-advanced" <?= $hasActiveFilters ? 'open' : '' ?>>
        <summary><span>Advanced options</span><em>Time, year, status, source, substance, and graph size</em></summary>
        <div class="citation-filter-secondary">
          <label class="field">
            <span>Time window</span>
            <select name="range">
              <?php foreach (['all' => 'All years', 'month' => 'Last month', 'year' => 'Last year', '5y' => 'Last 5 years', 'custom' => 'Custom dates'] as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $range === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">
            <span>Year</span>
            <select name="year">
              <option value="">Any year</option>
              <?php foreach ($years as $yearRow): ?>
                <option value="<?= h((string)$yearRow['year']) ?>" <?= $year === (string)$yearRow['year'] ? 'selected' : '' ?>><?= h((string)$yearRow['year']) ?> (<?= h((string)$yearRow['count']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">
            <span>From</span>
            <input type="date" name="from" value="<?= h($range === 'custom' ? $from : '') ?>">
          </label>
          <label class="field">
            <span>To</span>
            <input type="date" name="to" value="<?= h($range === 'custom' ? $to : '') ?>">
          </label>
          <label class="field">
            <span>Graph size</span>
            <select name="limit">
              <?php foreach ([1, 8, 16, 24, 28, 36, 48] as $option): ?>
                <option value="<?= h((string)$option) ?>" <?= $limit === $option ? 'selected' : '' ?>><?= h((string)$option) ?> seed papers</option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="citation-filter-checks">
          <fieldset>
            <legend>Substances</legend>
            <?php foreach (['psilocybin' => 'Psilocybin', 'psilocin' => 'Psilocin'] as $value => $label): ?>
              <label><input type="checkbox" name="substances[]" value="<?= h($value) ?>" <?= in_array($value, $selectedSubstances, true) ? 'checked' : '' ?>><span><?= h($label) ?></span></label>
            <?php endforeach; ?>
          </fieldset>
          <fieldset>
            <legend>Publication status</legend>
            <?php foreach ($publicationStatuses as $value => $label): ?>
              <label><input type="checkbox" name="publication_statuses[]" value="<?= h($value) ?>" <?= in_array($value, $selectedStatuses, true) ? 'checked' : '' ?>><span><?= h($label) ?></span></label>
            <?php endforeach; ?>
          </fieldset>
          <fieldset>
            <legend>Source database</legend>
            <?php foreach (array_slice($sources, 0, 8) as $sourceRow): $sourceName = (string)$sourceRow['source_name']; ?>
              <label><input type="checkbox" name="sources[]" value="<?= h($sourceName) ?>" <?= in_array($sourceName, $selectedSources, true) ? 'checked' : '' ?>><span><?= h($sourceName) ?></span></label>
            <?php endforeach; ?>
          </fieldset>
        </div>
      </details>
      <?php if ($paperId > 0): ?><input type="hidden" name="paper" value="<?= h((string)$paperId) ?>"><?php endif; ?>
      <div class="citation-filter-actions">
        <button class="primary iconed" type="submit"><i data-icon="network" aria-hidden="true"></i><span>Build graph</span></button>
        <?php if ($hasActiveFilters): ?><a class="secondary iconed" href="citation-network.php"><i data-icon="x" aria-hidden="true"></i><span>Reset</span></a><?php endif; ?>
        <a class="secondary iconed" href="./#papers"><i data-icon="book-marked" aria-hidden="true"></i><span>Back to papers</span></a>
      </div>
    </form>
    <?php if ($activeFilterLabels): ?>
      <div class="citation-active-filters" aria-label="Active citation network filters">
        <?php foreach ($activeFilterLabels as $label): ?><span><?= h($label) ?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="citation-network-layout" id="network" data-citation-fullscreen-target>
    <div class="panel citation-network-card">
      <div class="section-head-row citation-network-toolbar">
        <div>
          <h2><?= h(number_format((int)$graph['stats']['nodes'])) ?> nodes · <?= h(number_format((int)$graph['stats']['edges'])) ?> connections</h2>
          <p><?= h(number_format((int)$graph['stats']['seed_papers'])) ?> seed papers, <?= h(number_format((int)($nodeTypeCounts['author'] ?? 0))) ?> authors, <?= h(number_format((int)($nodeTypeCounts['topic'] ?? 0))) ?> topics, <?= h(number_format((int)$graph['stats']['external_references'])) ?> external DOI references.</p>
        </div>
        <div class="citation-network-controls" aria-label="Citation graph controls">
          <button class="secondary" type="button" data-citation-fit><i data-icon="maximize" aria-hidden="true"></i><span>Fit</span></button>
          <button class="secondary" type="button" data-citation-labels aria-pressed="true"><i data-icon="list-filter" aria-hidden="true"></i><span>Labels</span></button>
          <button class="secondary" type="button" data-citation-fullscreen hidden aria-pressed="false"><i data-icon="maximize" aria-hidden="true"></i><span>Fullscreen</span></button>
          <button class="secondary" type="button" data-citation-print><i data-icon="printer" aria-hidden="true"></i><span>Print references</span></button>
        </div>
      </div>
      <div class="citation-network-legend" aria-label="Graph legend">
        <span><i class="legend-paper"></i> Papers</span>
        <span><i class="legend-reference"></i> References</span>
        <span><i class="legend-author"></i> Authors</span>
        <span><i class="legend-topic"></i> Topics</span>
        <span><i class="legend-journal"></i> Journals</span>
      </div>
      <div class="citation-network-workbench" aria-label="Network analysis controls">
        <label class="citation-network-search"><span>Find in graph</span><input type="search" data-citation-search placeholder="Title, DOI, author, journal, topic"></label>
        <div class="citation-seed-control">
          <label><span>Seed papers</span><select data-citation-seed-limit aria-label="Change number of seed papers">
            <?php foreach ([1, 8, 16, 24, 28, 36, 48] as $option): ?>
              <option value="<?= h((string)$option) ?>" <?= $limit === $option ? 'selected' : '' ?>><?= h((string)$option) ?></option>
            <?php endforeach; ?>
          </select></label>
          <div class="citation-seed-presets" aria-label="Quick seed paper counts">
            <?php foreach ([1, 8, 24, 48] as $option): ?>
              <button type="button" data-citation-seed-preset="<?= h((string)$option) ?>" <?= $limit === $option ? 'aria-pressed="true"' : 'aria-pressed="false"' ?>><?= h((string)$option) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <label><span>Topology</span><select data-citation-layout-mode aria-label="Change network topology">
          <option value="force">Organic map</option>
          <option value="radial">Citation rings</option>
          <option value="timeline">Publication timeline</option>
        </select></label>
        <label><span>Labels</span><select data-citation-label-mode><option value="important">Important</option><option value="all">All</option><option value="selected">Selected</option><option value="off">Off</option></select></label>
        <label class="citation-toggle-control"><span>Clustering nodes</span><input type="checkbox" data-citation-clusters checked><em>Authors, topics, journals</em></label>
        <div class="citation-network-utility-actions">
          <button class="secondary" type="button" data-citation-focus-selected>Focus node</button>
          <button class="secondary" type="button" data-citation-share-view>Copy view URL</button>
          <button class="secondary" type="button" data-citation-copy-selected>Copy selected</button>
          <button class="secondary" type="button" data-citation-export-json>Export graph</button>
          <button class="secondary" type="button" data-citation-export-subgraph>Export focus</button>
          <button class="secondary" type="button" data-citation-export-csv>Export CSV</button>
          <button class="secondary" type="button" data-citation-clear-search>Clear focus</button>
        </div>
      </div>
      <div class="citation-network-insight" data-citation-insight aria-live="polite">
        Showing the full graph. Search or filter to focus the network.
      </div>
      <p class="citation-network-note">Edges explain shared citations, authors, topics, and journals for discovery. They are not causal claims or formal bibliometric strength estimates.</p>
      <div class="citation-network-canvas" data-citation-network aria-label="Interactive citation network visualization" role="img">
        <div class="citation-network-empty">Loading network...</div>
      </div>
    </div>

    <aside class="panel citation-network-detail" data-citation-detail>
      <span class="eyebrow">Selected node</span>
      <h2>Choose a node</h2>
      <p>Tap or click a paper, author, topic, journal, or reference to inspect it. Drag nodes to untangle dense areas.</p>
      <dl class="detail-list">
        <div><dt>Interaction</dt><dd>Drag nodes, use Fit to recenter, and open linked records from the detail panel.</dd></div>
      </dl>
      <div class="citation-node-relations" data-citation-relations></div>
    </aside>
  </section>
  <script id="citation-network-data" type="application/json"><?= json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
</main>
<?= detail_scroll_top() ?>
</body>
</html>
