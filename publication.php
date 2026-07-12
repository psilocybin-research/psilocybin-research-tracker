<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$id = (int)request_value('id', '0');
$paper = $id > 0 ? $repo->publicById($id) : null;
if (!$paper) {
    http_response_code(404);
}
$related = $paper ? $repo->relatedPapers($paper, 8) : [];
$references = $paper ? $repo->citedReferences($paper, 40) : [];
$citing = $paper && !empty($paper['doi']) ? $repo->citingPapers((string)$paper['doi'], 20) : [];
$assetVersion = '20260712-funding-footer-v88';
$title = $paper ? (string)$paper['title'] : 'Publication not found';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> | Psilocybin Research</title>
  <link rel="stylesheet" href="assets/styles.min.css?v=<?= h($assetVersion) ?>">
  <script src="assets/app.min.js?v=<?= h($assetVersion) ?>" defer></script>
</head>
<body class="detail-page">
<?= detail_page_header('publications') ?>
<main class="detail-shell">
  <?php if (!$paper): ?>
    <section class="panel detail-hero"><h1>Publication not found</h1><p>The requested record is not public or does not exist.</p></section>
  <?php else: ?>
    <article class="panel detail-hero">
      <span class="eyebrow"><?= h(publication_status_label($paper['publication_status'] ?? null)) ?></span>
      <h1><?= h((string)$paper['title']) ?></h1>
      <p>
        <?php if (trim((string)($paper['abstract'] ?? '')) !== ''): ?>
          An abstract is available from the original source but is not redistributed by this tracker.
          <?php if (!empty($paper['source_url'])): ?><a href="<?= h((string)$paper['source_url']) ?>" target="_blank" rel="noopener">Read the source record</a>.<?php endif; ?>
        <?php else: ?>No abstract availability was recorded for this entry.<?php endif; ?>
      </p>
      <div class="detail-actions">
        <?php if ($paper['source_url']): ?><a class="primary iconed" href="<?= h((string)$paper['source_url']) ?>" target="_blank" rel="noopener"><i data-icon="external-link" aria-hidden="true"></i><span>Open source</span></a><?php endif; ?>
        <a class="secondary iconed" href="export.php?format=bibtex&ids[]=<?= h((string)$paper['id']) ?>"><i data-icon="braces" aria-hidden="true"></i><span>BibTeX</span></a>
        <a class="secondary iconed" href="export.php?format=ris&ids[]=<?= h((string)$paper['id']) ?>"><i data-icon="copy" aria-hidden="true"></i><span>RIS</span></a>
        <button class="secondary iconed copy-citation" type="button" data-citation="<?= h(ExportService::citationText($paper)) ?>"><i data-icon="copy" aria-hidden="true"></i><span>Copy citation</span></button>
      </div>
    </article>

    <section class="detail-grid">
      <div class="panel detail-card">
        <h2>Bibliographic context</h2>
        <dl class="detail-list">
          <div><dt>Journal</dt><dd><?= $paper['journal'] ? chip_link((string)$paper['journal'], ['journal' => (string)$paper['journal']], 'plain-link') : 'Unknown' ?></dd></div>
          <div><dt>Date</dt><dd><?= h((string)($paper['publication_date'] ?: 'Unknown')) ?></dd></div>
          <div><dt>Source</dt><dd><?= $paper['source_name'] ? chip_link((string)$paper['source_name'], ['sources' => [(string)$paper['source_name']]], 'source-badge') : 'Unknown' ?></dd></div>
          <div><dt>DOI</dt><dd><?= $paper['doi'] ? '<a href="https://doi.org/' . h((string)$paper['doi']) . '" target="_blank" rel="noopener">' . h((string)$paper['doi']) . '</a>' : 'Unavailable' ?></dd></div>
          <div><dt>PubMed</dt><dd><?= $paper['pubmed_id'] ? '<a href="https://pubmed.ncbi.nlm.nih.gov/' . h((string)$paper['pubmed_id']) . '/" target="_blank" rel="noopener">' . h((string)$paper['pubmed_id']) . '</a>' : 'Unavailable' ?></dd></div>
        </dl>
      </div>
      <div class="panel detail-card">
        <h2>Authors</h2>
        <div class="chip-cloud">
          <?php foreach (split_tag_values((string)$paper['authors']) as $author): ?><a class="tag-link soft" href="authors.php?author=<?= h(urlencode($author)) ?>"><?= h($author) ?></a><?php endforeach; ?>
        </div>
        <a class="secondary iconed watch-author" href="authors.php?author=<?= h(urlencode((string)(split_tag_values((string)$paper['authors'])[0] ?? ''))) ?>#watch"><i data-icon="bell-plus" aria-hidden="true"></i><span>Watch author</span></a>
      </div>
      <div class="panel detail-card">
        <h2>Derived topics and classifications</h2>
        <div class="chip-cloud">
          <?php foreach (split_tag_values((string)$paper['substance_tags']) as $tag): ?><?= chip_link($tag, ['substances' => [$tag]], '') ?><?php endforeach; ?>
          <?php foreach (split_tag_values((string)$paper['topic_tags']) as $tag): ?><?= chip_link($tag, ['topic' => $tag], 'soft') ?><?php endforeach; ?>
          <?php if ($paper['study_type']): ?><?= chip_link((string)$paper['study_type'], ['study_type' => (string)$paper['study_type']], 'soft') ?><?php endif; ?>
        </div>
      </div>
      <div class="panel detail-card">
        <h2>Citation graph</h2>
        <p><?= h((string)count($references)) ?> referenced DOI<?= count($references) === 1 ? '' : 's' ?> found in stored source metadata. <?= h((string)count($citing)) ?> indexed paper<?= count($citing) === 1 ? '' : 's' ?> cite this DOI.</p>
        <p><a class="secondary iconed" href="citation-network.php?paper=<?= h((string)$paper['id']) ?>"><i data-icon="network" aria-hidden="true"></i><span>Open citation network</span></a></p>
        <?php if ($references): ?><div class="doi-list"><?php foreach (array_slice($references, 0, 12) as $doi): ?><a href="<?= h(tracker_query_url(['cited_doi' => $doi])) ?>"><?= h($doi) ?></a><?php endforeach; ?></div><?php endif; ?>
      </div>
    </section>

    <?php if ($citing): ?>
      <section class="panel detail-card"><h2>Indexed papers citing this DOI</h2><div class="compact-paper-list"><?php foreach ($citing as $row): ?><a href="publication.php?id=<?= h((string)$row['id']) ?>"><strong><?= h((string)$row['title']) ?></strong><span><?= h((string)($row['publication_date'] ?: 'Unknown date')) ?></span></a><?php endforeach; ?></div></section>
    <?php endif; ?>
    <section class="panel detail-card"><h2>Related papers</h2><?php if ($related): ?><div class="compact-paper-list"><?php foreach ($related as $row): ?><a href="publication.php?id=<?= h((string)$row['id']) ?>"><strong><?= h((string)$row['title']) ?></strong><span><?= h((string)($row['journal'] ?: 'Unknown journal')) ?> · <?= h((string)($row['publication_date'] ?: 'Unknown date')) ?></span></a><?php endforeach; ?></div><?php else: ?><p>No close related records were found yet.</p><?php endif; ?></section>
  <?php endif; ?>
</main>
<?= detail_scroll_top() ?>
</body>
</html>
