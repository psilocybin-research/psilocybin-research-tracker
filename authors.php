<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$query = (string)request_value('q', '');
$selectedAuthor = (string)request_value('author', '');
$authors = $repo->authors($query, 120);
$profile = $selectedAuthor !== '' ? $repo->authorProfile($selectedAuthor) : null;
$topics = $repo->topics();
$assetVersion = '20261011-server-evidence-v199';
$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$pageTitle = $profile ? (string)$profile['name'] . ' | Psilocybin Research Author Profile' : 'Psilocybin Research Authors | Researcher Index';
$description = $profile
    ? 'Explore ' . (string)$profile['name'] . "'s indexed psilocybin and psilocin publications, recurring topics, journals, identifiers, and author watch options."
    : 'Search authors in the psilocybin and psilocin literature, inspect publication histories, recurring topics, journals, and researcher identifiers.';
$canonicalPath = $profile ? 'authors.php?author=' . rawurlencode((string)$profile['name']) : 'authors.php';
$robots = ($query !== '' || ($selectedAuthor !== '' && !$profile)) ? 'noindex, follow, noarchive' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
$authorSchema = $profile
    ? LinkedData::document([['@type' => 'ProfilePage', '@id' => $baseUrl . $canonicalPath . '#page', 'name' => $pageTitle, 'description' => $description, 'url' => $baseUrl . $canonicalPath, 'mainEntity' => ['@id' => LinkedData::person($profile['name'])['@id']]], LinkedData::person($profile['name'], $profile['orcid'], $profile['openalex_id'])])
    : LinkedData::document([LinkedData::website(), ['@type' => 'CollectionPage', '@id' => $baseUrl . 'authors.php#page', 'name' => $pageTitle, 'description' => $description, 'url' => $baseUrl . 'authors.php', 'isPartOf' => ['@id' => $baseUrl . '#website']]]);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <?= detail_page_seo($pageTitle, $description, $canonicalPath, ['robots' => $robots, 'type' => $profile ? 'profile' : 'website']) ?>
  <?= json_ld_script($authorSchema) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head>
<body class="detail-page">
<?= detail_page_header('authors') ?>
<main class="detail-shell">
  <section class="panel detail-hero">
    <div class="detail-hero-intro">
      <div><span class="eyebrow">Researcher index</span><h1>Authors in the psilocybin and psilocin literature</h1><p>Search researcher names, inspect publication history, find recurring topics, and create author watch alerts without collecting unverified contact lists.</p></div>
    </div>
    <form class="inline-search" method="get" toolname="search_authors" tooldescription="Find indexed research authors by name." toolautosubmit>
      <input type="search" name="q" value="<?= h($query) ?>" placeholder="Search authors, e.g. Carhart-Harris">
      <button class="primary iconed" type="submit"><i data-icon="search" aria-hidden="true"></i><span>Search authors</span></button>
    </form>
  </section>

  <?php if ($profile): ?>
    <section class="detail-grid author-profile">
      <div class="panel detail-card">
        <h2 class="icon-heading"><i data-icon="users" aria-hidden="true"></i><span><?= h((string)$profile['name']) ?></span></h2>
        <p><?= h(number_format((int)$profile['count'])) ?> indexed publication<?= (int)$profile['count'] === 1 ? '' : 's' ?> match this author name.</p>
        <p><a class="secondary iconed" href="citation-network.php?<?= h(http_build_query(['author' => $profile['name'], 'limit' => 12])) ?>"><i data-icon="network" aria-hidden="true"></i><span>Explore author in citation network</span></a></p>
        <dl class="detail-list">
          <div><dt>ORCID</dt><dd><?= $profile['orcid'] ? '<a href="https://orcid.org/' . h((string)$profile['orcid']) . '" target="_blank" rel="noopener">' . h((string)$profile['orcid']) . '</a>' : 'Not found in stored metadata' ?></dd></div>
          <div><dt>OpenAlex</dt><dd><?= $profile['openalex_id'] ? '<a href="' . h((string)$profile['openalex_id']) . '" target="_blank" rel="noopener">' . h((string)$profile['openalex_id']) . '</a>' : 'Not found in stored metadata' ?></dd></div>
        </dl>
      </div>
      <div class="panel detail-card" id="watch">
        <h2 class="icon-heading"><i data-icon="bell-plus" aria-hidden="true"></i><span>Author watchlist</span></h2>
        <p>Create a confirmed email alert for new publications matching this author.</p>
        <form class="inline-watch-form" method="post" action="./#papers">
          <input type="hidden" name="action" value="subscribe">
          <input type="hidden" name="alert_scope" value="targeted">
          <input type="hidden" name="alert_author" value="<?= h((string)$profile['name']) ?>">
          <input type="hidden" name="frequency" value="daily">
          <input type="hidden" name="alert_substances[]" value="psilocybin">
          <input type="hidden" name="alert_substances[]" value="psilocin">
          <label class="field"><span>Email address</span><input type="email" name="email" required placeholder="you@university.edu"></label>
          <label class="consent-check compact"><input type="checkbox" name="privacy_consent" value="1" required> Store my email and this author filter to send confirmed alerts.</label>
          <button class="primary iconed" type="submit"><i data-icon="bell-plus" aria-hidden="true"></i><span>Watch author</span></button>
        </form>
      </div>
      <div class="panel detail-card">
        <h2 class="icon-heading"><i data-icon="list-filter" aria-hidden="true"></i><span>Topics</span></h2>
        <div class="chip-cloud"><?php foreach ($profile['topics'] as $topic => $count): ?><a class="tag-link soft" href="<?= h(tracker_query_url(['author' => $profile['name'], 'topic' => $topic])) ?>"><?= h((string)$topic) ?> <small><?= h((string)$count) ?></small></a><?php endforeach; ?></div>
      </div>
      <div class="panel detail-card">
        <h2 class="icon-heading"><i data-icon="library" aria-hidden="true"></i><span>Journals</span></h2>
        <div class="chip-cloud"><?php foreach ($profile['journals'] as $journal => $count): ?><a class="tag-link soft" href="<?= h(tracker_query_url(['author' => $profile['name'], 'journal' => $journal])) ?>"><?= h((string)$journal) ?> <small><?= h((string)$count) ?></small></a><?php endforeach; ?></div>
      </div>
    </section>
    <section class="panel detail-card">
      <h2 class="icon-heading"><i data-icon="book-open" aria-hidden="true"></i><span>Publication history</span></h2>
      <div class="compact-paper-list"><?php foreach ($profile['papers'] as $paper): ?><a href="publication.php?id=<?= h((string)$paper['id']) ?>"><strong><?= h((string)$paper['title']) ?></strong><span><?= h((string)PublicationMetadata::venue($paper)['label']) ?> · <?= h((string)($paper['publication_date'] ?: 'Unknown date')) ?></span></a><?php endforeach; ?></div>
    </section>
  <?php endif; ?>

  <section class="panel detail-card">
    <h2 class="icon-heading"><i data-icon="users" aria-hidden="true"></i><span>Author directory</span></h2>
    <div class="author-directory">
      <?php foreach ($authors as $author): ?><a href="authors.php?author=<?= h(urlencode((string)$author['name'])) ?>"><strong><?= h((string)$author['name']) ?></strong><span><?= h((string)$author['count']) ?> publications</span></a><?php endforeach; ?>
    </div>
  </section>
</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
</body>
</html>
