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
$assetVersion = '20260711-rights-safe-v87';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Author index | Psilocybin Research</title>
  <link rel="stylesheet" href="assets/styles.min.css?v=<?= h($assetVersion) ?>">
  <script src="assets/app.min.js?v=<?= h($assetVersion) ?>" defer></script>
</head>
<body class="detail-page">
<?= detail_page_header('authors') ?>
<main class="detail-shell">
  <section class="panel detail-hero">
    <span class="eyebrow">Researcher index</span>
    <h1>Authors in the psilocybin and psilocin literature</h1>
    <p>Search researcher names, inspect publication history, find recurring topics, and create author watch alerts without collecting unverified contact lists.</p>
    <form class="inline-search" method="get">
      <input type="search" name="q" value="<?= h($query) ?>" placeholder="Search authors, e.g. Carhart-Harris">
      <button class="primary iconed" type="submit"><i data-icon="search" aria-hidden="true"></i><span>Search authors</span></button>
    </form>
  </section>

  <?php if ($profile): ?>
    <section class="detail-grid author-profile">
      <div class="panel detail-card">
        <h2><?= h((string)$profile['name']) ?></h2>
        <p><?= h(number_format((int)$profile['count'])) ?> indexed publication<?= (int)$profile['count'] === 1 ? '' : 's' ?> match this author name.</p>
        <dl class="detail-list">
          <div><dt>ORCID</dt><dd><?= $profile['orcid'] ? '<a href="https://orcid.org/' . h((string)$profile['orcid']) . '" target="_blank" rel="noopener">' . h((string)$profile['orcid']) . '</a>' : 'Not found in stored metadata' ?></dd></div>
          <div><dt>OpenAlex</dt><dd><?= $profile['openalex_id'] ? '<a href="' . h((string)$profile['openalex_id']) . '" target="_blank" rel="noopener">' . h((string)$profile['openalex_id']) . '</a>' : 'Not found in stored metadata' ?></dd></div>
        </dl>
      </div>
      <div class="panel detail-card" id="watch">
        <h2>Author watchlist</h2>
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
        <h2>Topics</h2>
        <div class="chip-cloud"><?php foreach ($profile['topics'] as $topic => $count): ?><a class="tag-link soft" href="<?= h(tracker_query_url(['author' => $profile['name'], 'topic' => $topic])) ?>"><?= h((string)$topic) ?> <small><?= h((string)$count) ?></small></a><?php endforeach; ?></div>
      </div>
      <div class="panel detail-card">
        <h2>Journals</h2>
        <div class="chip-cloud"><?php foreach ($profile['journals'] as $journal => $count): ?><a class="tag-link soft" href="<?= h(tracker_query_url(['author' => $profile['name'], 'journal' => $journal])) ?>"><?= h((string)$journal) ?> <small><?= h((string)$count) ?></small></a><?php endforeach; ?></div>
      </div>
    </section>
    <section class="panel detail-card">
      <h2>Publication history</h2>
      <div class="compact-paper-list"><?php foreach ($profile['papers'] as $paper): ?><a href="publication.php?id=<?= h((string)$paper['id']) ?>"><strong><?= h((string)$paper['title']) ?></strong><span><?= h((string)($paper['journal'] ?: 'Unknown journal')) ?> · <?= h((string)($paper['publication_date'] ?: 'Unknown date')) ?></span></a><?php endforeach; ?></div>
    </section>
  <?php endif; ?>

  <section class="panel detail-card">
    <h2>Author directory</h2>
    <div class="author-directory">
      <?php foreach ($authors as $author): ?><a href="authors.php?author=<?= h(urlencode((string)$author['name'])) ?>"><strong><?= h((string)$author['name']) ?></strong><span><?= h((string)$author['count']) ?> publications</span></a><?php endforeach; ?>
    </div>
  </section>
</main>
<?= detail_scroll_top() ?>
</body>
</html>
