<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$data = ResearchFilm::data();
$snapshotYear = (int)substr($data['snapshot_date'], 0, 4);
$media = ResearchFilm::url(ResearchFilm::ASSETS);
$share = ResearchFilm::url(ResearchFilm::SHARES);
$assetVersion = '20261012-android-safe-area-v255';
$robots = $_GET ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
if ($_GET) header('X-Robots-Tag: noindex, follow');
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<base href="<?= h(ResearchFilm::url('')) ?>">
<title><?= h(ResearchFilm::TITLE) ?></title>
<?= detail_page_seo(ResearchFilm::TITLE, ResearchFilm::description(), ResearchFilm::PATH, ['robots' => $robots, 'type' => 'video.other', 'image' => $media . 'research-in-motion-social.jpg', 'image_width' => 1200, 'image_height' => 630, 'image_alt' => ResearchFilm::description()]) ?>
<meta property="og:video" content="<?= h($share) ?>psilocybin-research-preview-desktop.mp4">
<meta property="og:video:secure_url" content="<?= h($share) ?>psilocybin-research-preview-desktop.mp4">
<meta property="og:video:type" content="video/mp4"><meta property="og:video:width" content="1280"><meta property="og:video:height" content="720">
<?= json_ld_script(LinkedData::researchFilm()) ?>
<link rel="icon" href="<?= h(ResearchFilm::url('assets/pwa/icon-192.png')) ?>">
<link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
<link rel="modulepreload" href="<?= h(str_replace(FrontendAssets::VERSION, FrontendAssets::VERSION . '-film-clickplay-20261114', FrontendAssets::url('assets/features/research-film.min.js'))) ?>">
<script src="/assets/vendor/gsap-3.15.0.min.js" defer></script>
<script src="<?= h(str_replace(FrontendAssets::VERSION, FrontendAssets::VERSION . '-film-clickplay-20261114', FrontendAssets::url('assets/app.min.js'))) ?>" defer></script>
</head><body class="detail-page research-film-page">
<?= detail_page_header('research-in-motion') ?>
<main class="detail-shell" id="main">
<section class="panel detail-hero research-film-intro">
<span class="eyebrow">Data visualisation · <?= (int)$data['film_duration_seconds'] ?> seconds</span>
<h1>Psilocybin Research in <em>Motion</em></h1>
<p>Follow <strong><?= h(number_format($data['total'])) ?> research records</strong> across <?= count($data['sources']) ?> sources, from annual counts and research topics to clinical trial registrations.</p>
<?= ResearchFilm::player() ?>
</section>


<section class="panel detail-card">
<h2>Snapshot data</h2>
<p>Snapshot: <time datetime="<?= h($data['snapshot_date']) ?>"><?= h(ResearchFilm::snapshotLabel()) ?></time> · <a href="<?= h($media) ?>aggregates.json" download>Aggregate counts (JSON)</a></p>
<details class="research-film-data"><summary>Counts by stored source</summary><div class="research-table-wrap" tabindex="0" role="region" aria-label="Source counts"><table class="research-table"><caption>Retained source labels · <?= h(number_format($data['total'])) ?> public records</caption><thead><tr><th scope="col">Source</th><th scope="col">Records</th></tr></thead><tbody>
<?php foreach ($data['sources'] as $row): ?><tr><th scope="row"><a href="<?= h(ResearchFilm::url(source_filter_url($row['source_name']))) ?>"><?= h($row['source_name']) ?></a></th><td><a href="<?= h(ResearchFilm::url(source_filter_url($row['source_name']))) ?>" aria-label="<?= h('Browse ' . $row['source_name'] . ' records') ?>"><?= h(number_format($row['count'])) ?></a></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><th scope="row"><a href="<?= h(ResearchFilm::url('#papers')) ?>">Total</a></th><td><a href="<?= h(ResearchFilm::url('#papers')) ?>" aria-label="Browse the current catalogue"><?= h(number_format($data['total'])) ?></a></td></tr></tfoot></table></div></details>
<details class="research-film-data"><summary>Counts for all <?= count($data['years']) ?> recorded years</summary><div class="research-table-wrap" tabindex="0" role="region" aria-label="Annual counts"><table class="research-table"><caption>Annual record counts · <?= $snapshotYear ?> incomplete</caption><thead><tr><th scope="col">Recorded year</th><th scope="col">Records</th></tr></thead><tbody>
<?php foreach ($data['years'] as $row): ?><tr><th scope="row"><a href="<?= h(ResearchFilm::url('?year=' . (int)$row['year'] . '&range=all&page=1#papers')) ?>"><?= (int)$row['year'] ?><?= $row['year'] === $snapshotYear ? ' (incomplete year)' : '' ?></a></th><td><a href="<?= h(ResearchFilm::url('?year=' . (int)$row['year'] . '&range=all&page=1#papers')) ?>" aria-label="<?= h('Browse records for ' . (int)$row['year']) ?>"><?= h(number_format($row['count'])) ?></a></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><th scope="row"><a href="<?= h(ResearchFilm::url('#papers')) ?>">Total</a></th><td><a href="<?= h(ResearchFilm::url('#papers')) ?>" aria-label="Browse the current catalogue"><?= h(number_format($data['total'])) ?></a></td></tr></tfoot></table></div></details>
<p class="film-data-provenance">Live source endpoints: <a href="<?= h($data['source_urls'][0]) ?>">analytics</a> · <a href="<?= h($data['source_urls'][1]) ?>">source counts</a>. Current totals may differ from the snapshot.</p>
</section>

<details class="panel detail-card film-context">
<summary>How to read these data <i data-icon="chevron-down" aria-hidden="true"></i></summary>
<div class="film-context-content">
<p>This frozen snapshot contains <strong><?= h(number_format($data['statuses']['published'] ?? 0)) ?> published records, <?= h(number_format($data['statuses']['preprint'] ?? 0)) ?> preprints, <?= h(number_format($data['trial_registrations'])) ?> trial registrations and <?= h(number_format($data['statuses']['protocol'] ?? 0)) ?> protocols</strong>. Catalogue records are not independently verified studies. “Published” is the database’s classification, rather than an independent peer-review audit; registrations are not trial results.</p>
<p>Source counts reflect retained labels after deduplication and enrichment, not the complete coverage of each database. Use labelled counts to compare source clouds; annual and topic bars share a zero baseline. Recorded years use stored publication or registry dates, which do not necessarily indicate study completion.</p>
<p><strong><?= $snapshotYear ?> is incomplete.</strong> Its records include <?= ($data['future_dated_records'] === 4 ? 'four' : h(number_format($data['future_dated_records']))) ?> dates after the <?= h((new DateTimeImmutable($data['snapshot_date']))->format('j F')) ?> snapshot. Those entries are preserved in the film. Indexing delays, backfills and changing source coverage also affect comparisons. Record counts do not measure treatment efficacy.</p>
<p>Methods: <a href="<?= h(ResearchFilm::url('editorial-policy.php#author')) ?>">Dr. Christopher B. Germann</a>. For current counts, visit <a href="<?= h(ResearchFilm::url('research-trends.php')) ?>">research trends</a>.</p>
</div></details>

<section class="panel detail-card film-msca-publication" aria-labelledby="film-msca-title">
<span class="eyebrow">MCAA Policy Studies</span>
<h2 id="film-msca-title">Impact of the Marie Skłodowska-Curie Actions (MSCA): Driving Knowledge, Innovation, and EU Competitiveness (2025).</h2>
<a class="film-msca-cover" href="https://www.mariecuriealumni.eu/publications/books/mcaa-policy-studies/impact-marie-sklodowska-curie-actions-msca" target="_blank" rel="noopener" aria-label="View the MCAA publication (opens in a new tab)"><img src="/assets/msca-impact/mcaa-msca-impact-report-2025-cover-pdf-480.webp" srcset="/assets/msca-impact/mcaa-msca-impact-report-2025-cover-pdf-480.webp 480w, /assets/msca-impact/mcaa-msca-impact-report-2025-cover-pdf-960.webp 960w, /assets/msca-impact/mcaa-msca-impact-report-2025-cover-pdf-1440.webp 1440w, /assets/msca-impact/mcaa-msca-impact-report-2025-cover-pdf-full.webp 2481w" sizes="(min-width: 1181px) calc(100vw - 380px), calc(100vw - 72px)" width="2481" height="3508" loading="lazy" decoding="async" alt="Cover of the MCAA Policy Studies report: Impact of the Marie Skłodowska-Curie Actions (MSCA), 2025"></a>
<p class="film-msca-credit">© Marie Curie Alumni Association. Licensed under <a href="https://creativecommons.org/licenses/by/4.0/" rel="license">CC BY 4.0</a>.</p>
<div class="film-msca-links"><a href="https://www.mariecuriealumni.eu/publications/books/mcaa-policy-studies/impact-marie-sklodowska-curie-actions-msca" target="_blank" rel="noopener">View the publication <span class="sr-only">(opens in a new tab)</span><i data-icon="external-link" aria-hidden="true"></i></a><a href="https://www.mariecuriealumni.eu/system/files/2026-05/MCAA_MSCA_Impact_Report_2025_ebook.pdf" target="_blank" rel="noopener">PDF preview <span class="sr-only">(opens in a new tab)</span><i data-icon="external-link" aria-hidden="true"></i></a></div>
</section>

</main><?= detail_footer() ?><?= detail_scroll_top() ?></body></html>
