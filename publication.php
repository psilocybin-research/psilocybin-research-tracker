<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$datasetRepo = new OpenDatasetRepository($db);
$id = (int)request_value('id', '0');
$paper = $id > 0 ? $repo->publicById($id) : null;
if (!$paper) {
    http_response_code(404);
}
$studyRelationships = $paper ? StudyRelationships::forRecord($db, (int)$paper['id']) : null;
$related = $paper ? $repo->relatedPapers($paper, 8) : [];
$references = $paper ? $repo->citedReferences($paper, 40) : [];
$citing = $paper && !empty($paper['doi']) ? $repo->citingPapers((string)$paper['doi'], 20) : [];
$openDatasets = $paper ? $datasetRepo->forPublication((int)$paper['id']) : [];
$assetVersion = '20261101-push-experience-v292';
$title = $paper ? (string)$paper['title'] : 'Publication not found';
$pageTitle = $paper ? PublicationMetadata::searchTitle($title) : 'Publication Not Found | Psilocybin Research';
$canonicalPath = $paper ? 'publication.php?id=' . (int)$paper['id'] : 'publication.php';
$descriptionTitle = $title;
if (mb_strlen($title, 'UTF-8') > 74) {
    $prefix = mb_substr($title, 0, 74, 'UTF-8');
    $descriptionTitle = rtrim((string)(preg_replace('/\s+\S*$/u', '', $prefix) ?: $prefix)) . '…';
}
$description = $paper
    ? 'Bibliographic record for ' . $descriptionTitle . ' — review authors, identifiers, citations, and related research.'
    : 'The requested Psilocybin Research publication record is unavailable.';
$robots = $paper && !request_value('notification') ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' : 'noindex, follow, noarchive';
$trialFacts = $paper && $paper['publication_status'] === 'clinical trial' ? (new TrialMapService($db))->forPublication((int)$paper['id']) : null;
$publicationSchema = $paper ? LinkedData::recordGraph($paper, array_slice($references, 0, 12), $openDatasets, $trialFacts, $citing) : null;
$evidenceGuides = $paper ? ResearchEvidence::guidesForPublication($paper) : [];
$hasMscaContext = $paper && in_array(normalize_doi($paper['doi'] ?? null), ['10.1016/j.mehy.2019.109406', '10.1038/s41514-025-00244-x'], true);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <?= detail_page_seo($pageTitle, $description, $canonicalPath, ['robots' => $robots, 'type' => $paper ? 'article' : 'website']) ?>
  <?php if ($publicationSchema): ?><?= json_ld_script($publicationSchema) ?><?php endif; ?>
  <?php if ($paper): ?><link rel="alternate" type="application/ld+json" href="<?= h(LinkedData::base() . 'linked-data.php?resource=record&id=' . (int)$paper['id']) ?>"><?php endif; ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
  <script src="<?= h(FrontendAssets::url('assets/research-explorer.min.js')) ?>" defer></script>
</head>
<body class="detail-page">
<?= detail_page_header('publications') ?>
<main data-notification-batch="<?= h($paper && preg_match('/^[a-f0-9]{64}$/', (string)request_value('notification')) ? (string)request_value('notification') : '') ?>" class="detail-shell">
  <?php if (!$paper): ?>
    <section class="panel detail-hero"><h1>Publication not found</h1><p>The requested record is not public or does not exist.</p></section>
  <?php else: ?>
    <article class="panel detail-hero">
      <div class="detail-hero-intro">
        <div><span class="eyebrow"><?= h(publication_status_label($paper['publication_status'] ?? null)) ?></span><h1><?= h((string)$paper['title']) ?></h1><p>
        <?php if (trim((string)($paper['abstract'] ?? '')) !== ''): ?>
          An abstract is available from the original source but is not redistributed by this database.
          <?php if (!empty($paper['source_url'])): ?><a href="<?= h((string)$paper['source_url']) ?>" target="_blank" rel="noopener">Read the source record</a>.<?php endif; ?>
        <?php else: ?>No abstract availability was recorded for this entry.<?php endif; ?>
        </p></div>
      </div>
      <div class="detail-actions"><a class="secondary iconed" href="citation-network.php?paper=<?= (int)$paper['id'] ?>"><i data-icon="network" aria-hidden="true"></i><span>View in citation network</span></a><button type="button" class="secondary" data-save-paper="<?= (int)$paper['id'] ?>">Save to reading list</button>
        <?php if ($paper['source_url']): ?><a class="primary iconed" href="<?= h((string)$paper['source_url']) ?>" target="_blank" rel="noopener"><i data-icon="external-link" aria-hidden="true"></i><span>Open source</span></a><?php endif; ?>
        <a class="secondary iconed" href="export.php?format=bibtex&ids[]=<?= h((string)$paper['id']) ?>"><i data-icon="braces" aria-hidden="true"></i><span>BibTeX</span></a>
        <a class="secondary iconed" href="export.php?format=ris&ids[]=<?= h((string)$paper['id']) ?>"><i data-icon="copy" aria-hidden="true"></i><span>RIS</span></a>
        <button class="secondary iconed copy-citation" type="button" data-citation="<?= h(ExportService::citationText($paper)) ?>"><i data-icon="copy" aria-hidden="true"></i><span>Copy citation</span></button>
      </div>
    </article>

    <?php if (!empty($studyRelationships['families'])): ?>
    <section class="panel detail-card" aria-labelledby="study-family-heading">
      <h2 id="study-family-heading">Reports from the same study</h2>
      <p>Selected source-checked relationships. Keep these records separate, but do not count each report as an independent study or add their participant totals.</p>
      <?php foreach ($studyRelationships['families'] as $family): ?>
      <h3><?= h($family['label']) ?></h3><p><?= h($family['note']) ?> Checked <?= h($family['checked_at']) ?>.</p>
      <?php if ($family['registry_url']): ?><p>Registry identity: <a href="<?= h($family['registry_url']) ?>"><?= h($family['nct_id']) ?></a>.</p><?php endif; ?>
      <ul><?php foreach ($family['members'] as $member): ?><li><a href="<?= h($member['record_url']) ?>"<?= $member['id'] === (int)$paper['id'] ? ' aria-current="page"' : '' ?>><?= h($member['title']) ?></a> — <?= h(str_replace('_', ' ', $member['role'])) ?> · <?= h($member['publication_status']) ?>. Evidence: <?php foreach ($member['evidence'] as $i => $source): ?><?= $i ? '; ' : '' ?><a href="<?= h($source['url']) ?>"><?= h($source['basis']) ?></a><?php endforeach; ?></li><?php endforeach; ?></ul>
      <?php endforeach; ?>
      <p>Coverage is incomplete. Missing links do not establish independence. <a href="api/v1/study-links?id=<?= (int)$paper['id'] ?>">Relationship data and source evidence (JSON)</a>.</p>
    </section>
    <?php endif; ?>

    <?php if ($evidenceGuides): ?>
    <section class="panel detail-card" aria-labelledby="study-context-heading">
      <h2 id="study-context-heading">Read this study in context</h2>
      <p>This report is included in a selected evidence comparison. Review its population, primary endpoint, findings and limitations alongside the other selected reports.</p>
      <ul><?php foreach ($evidenceGuides as $guide): ?><li><a href="<?= h($guide['path']) ?>"><?= h($guide['label']) ?>: selected study comparison</a></li><?php endforeach; ?></ul>
      <p>These original educational annotations are not an exhaustive systematic review or a clinical guideline.</p>
    </section>
    <?php endif; ?>

    <?php if ($hasMscaContext): ?>
    <section class="panel detail-card" aria-labelledby="msca-context-heading">
      <h2 id="msca-context-heading">Hypothesis and preclinical research context</h2>
      <p>Read the <a href="msca-impact/">MSCA research story on psilocybin and telomeres</a> for the interdisciplinary background, original hypothesis and independent preclinical follow-up, with source links and limitations. Available in 20 languages.</p>
      <p>This research trajectory is not evidence of an established anti-ageing effect in humans or a treatment recommendation.</p>
    </section>
    <?php endif; ?>

    <section class="detail-grid">
      <div class="panel detail-card">
        <h2 class="icon-heading"><i data-icon="library" aria-hidden="true"></i><span>Bibliographic context</span></h2>
        <dl class="detail-list">
          <div><dt><?= PublicationMetadata::venue($paper)['kind'] === 'journal' ? 'Journal' : 'Repository / source' ?></dt><dd><?= $paper['journal'] ? chip_link((string)$paper['journal'], ['journal' => (string)$paper['journal']], 'plain-link') : h(PublicationMetadata::venue($paper)['label']) ?></dd></div>
          <div><dt>Date</dt><dd><?= h((string)($paper['publication_date'] ?: 'Unknown')) ?></dd></div>
          <?php if ($trialFacts): ?><div><dt>Registry status</dt><dd><?= h($trialFacts['status_label']) ?></dd></div><div><dt>Registered conditions</dt><dd><?= h(implode(', ', $trialFacts['conditions'])) ?></dd></div><div><dt>Study registration</dt><dd><?= h($trialFacts['nct_id']) ?> · <?= h($trialFacts['study_type']) ?></dd></div><?php endif; ?>
          <div><dt>Source</dt><dd><?= $paper['source_name'] ? chip_link((string)$paper['source_name'], ['sources' => [(string)$paper['source_name']]], 'source-badge') : 'Unknown' ?></dd></div>
          <div><dt>DOI</dt><dd><?= $paper['doi'] ? '<a href="https://doi.org/' . h((string)$paper['doi']) . '" target="_blank" rel="noopener">' . h((string)$paper['doi']) . '</a>' : 'Unavailable' ?></dd></div>
          <div><dt>PubMed</dt><dd><?= $paper['pubmed_id'] ? '<a href="https://pubmed.ncbi.nlm.nih.gov/' . h((string)$paper['pubmed_id']) . '/" target="_blank" rel="noopener">' . h((string)$paper['pubmed_id']) . '</a>' : 'Unavailable' ?></dd></div>
        </dl>
      </div>
      <div class="panel detail-card">
        <details id="metadata"><summary>Record provenance and attribution</summary>
          <p>The listed authors receive credit for the original research. This tracker aggregates source metadata and derives discovery labels; inclusion is not scientific validation.</p>
          <dl class="detail-list">
            <div><dt>Source identifier</dt><dd><?= h((string)$paper['source_record_id']) ?></dd></div>
            <?php if (!empty($paper['date_added'])): ?><div><dt>Indexed</dt><dd><?= h((string)$paper['date_added']) ?> UTC</dd></div><?php endif; ?>
            <?php if (!empty($paper['last_checked'])): ?><div><dt>Metadata last checked</dt><dd><?= h((string)$paper['last_checked']) ?> UTC</dd></div><?php endif; ?>
          </dl>
          <p class="field-note">Checking metadata does not mean individual manual review. Cite the original work for its findings and the exact dataset snapshot and software version for analyses.</p>
          <a href="<?= h(LinkedData::base() . 'linked-data.php?resource=record&id=' . (int)$paper['id']) ?>">Machine-readable provenance</a>
        </details>
        <h2 class="icon-heading"><i data-icon="users" aria-hidden="true"></i><span>Authors</span></h2>
        <div class="chip-cloud">
          <?php foreach (split_tag_values((string)$paper['authors']) as $author): ?><a class="tag-link soft" href="authors.php?author=<?= h(urlencode($author)) ?>"><?= h($author) ?></a><?php endforeach; ?>
        </div>
        <a class="secondary iconed watch-author" href="authors.php?author=<?= h(urlencode((string)(split_tag_values((string)$paper['authors'])[0] ?? ''))) ?>#watch"><i data-icon="bell-plus" aria-hidden="true"></i><span>Watch author</span></a>
      </div>
      <div class="panel detail-card">
        <h2 class="icon-heading"><i data-icon="list-filter" aria-hidden="true"></i><span>Derived topics and classifications</span></h2><p class="field-note">These labels are search aids derived from indexed metadata, not an assessment of evidence quality. Verify the study design and findings in the original source.</p>
        <div class="chip-cloud">
          <?php foreach (split_tag_values((string)$paper['substance_tags']) as $tag): ?><?= chip_link($tag, ['substances' => [$tag]], '') ?><?php endforeach; ?>
          <?php foreach (split_tag_values((string)$paper['topic_tags']) as $tag): ?><?= chip_link($tag, ['topic' => $tag], 'soft') ?><?php endforeach; ?>
          <?php if ($paper['study_type']): ?><?= chip_link((string)$paper['study_type'], ['study_type' => (string)$paper['study_type']], 'soft') ?><?php endif; ?>
        </div>
      </div>
      <div class="panel detail-card">
        <h2 class="icon-heading"><i data-icon="network" aria-hidden="true"></i><span>Citation graph</span></h2>
        <p><?= h((string)count($references)) ?> referenced DOI<?= count($references) === 1 ? '' : 's' ?> found in stored source metadata. <?= h((string)count($citing)) ?> indexed paper<?= count($citing) === 1 ? '' : 's' ?> cite this DOI.</p>
        <p><a class="secondary iconed" href="citation-network.php?paper=<?= h((string)$paper['id']) ?>"><i data-icon="network" aria-hidden="true"></i><span>Open citation network</span></a></p>
        <?php if ($references): ?><div class="doi-list"><?php foreach (array_slice($references, 0, 12) as $doi): ?><a href="<?= h(tracker_query_url(['cited_doi' => $doi])) ?>"><?= h($doi) ?></a><?php endforeach; ?></div><?php endif; ?>
      </div>
    </section>

    <?php if ($openDatasets): ?>
      <section class="panel detail-card publication-datasets"><div class="section-head-row"><h2 class="icon-heading"><i data-icon="database" aria-hidden="true"></i><span>Open datasets linked to this paper</span></h2><a class="secondary" href="open-datasets.php?q=<?= h(urlencode((string)$paper['title'])) ?>">Open dataset index</a></div><div class="publication-dataset-list"><?php foreach ($openDatasets as $dataset): ?><a href="<?= h((string)$dataset['dataset_url']) ?>" target="_blank" rel="noopener"><span><strong><?= h((string)($dataset['dataset_title'] ?: $dataset['repository'] . ' dataset')) ?></strong><small><?= h((string)$dataset['repository']) ?> · <?= $dataset['access_status'] === 'open' ? 'Open licence' : 'Public access' ?><?= $dataset['license_name'] ? ' · ' . h((string)$dataset['license_name']) : '' ?></small></span><i data-icon="external-link" aria-hidden="true"></i></a><?php endforeach; ?></div></section>
    <?php endif; ?>

    <?php if ($citing): ?>
      <section class="panel detail-card"><h2 class="icon-heading"><i data-icon="link" aria-hidden="true"></i><span>Indexed papers citing this DOI</span></h2><div class="compact-paper-list"><?php foreach ($citing as $row): ?><a href="publication.php?id=<?= h((string)$row['id']) ?>"><strong><?= h((string)$row['title']) ?></strong><span><?= h((string)($row['publication_date'] ?: 'Unknown date')) ?></span></a><?php endforeach; ?></div></section>
    <?php endif; ?>
    <section class="panel detail-card"><h2 class="icon-heading"><i data-icon="book-marked" aria-hidden="true"></i><span>Related papers</span></h2><?php if ($related): ?><div class="compact-paper-list"><?php foreach ($related as $row): ?><a href="publication.php?id=<?= h((string)$row['id']) ?>"><strong><?= h((string)$row['title']) ?></strong><span><?= h((string)PublicationMetadata::venue($row)['label']) ?> · <?= h((string)($row['publication_date'] ?: 'Unknown date')) ?></span></a><?php endforeach; ?></div><?php else: ?><p>No close related records were found yet.</p><?php endif; ?></section>
  <?php endif; ?>
</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
</body>
</html>
