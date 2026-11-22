<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$db = new Database(); $db->initialize();
$guide = ResearchEvidence::collection($db, 'alcohol-use-disorder');
$studies = $guide['studies'];
$ids = array_values(array_filter(array_map(static fn(array $s): ?int => $s['paper'] ? (int)$s['paper']['id'] : null, $studies)));
$path = 'psilocybin-alcohol-use-disorder-research/'; $url = LinkedData::base() . $path;
$title = 'Psilocybin and Alcohol Use Disorder: Research Evidence';
$description = 'Compare psilocybin alcohol use disorder research: the 2022 drinking-outcome trial, 2025 relapse-prevention findings, an early pilot, and study limitations.';
$robots = $_GET ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
if ($_GET) header('X-Robots-Tag: noindex, follow');
$schema = LinkedData::document([
    ['@type' => 'WebPage', '@id' => $url . '#page', 'url' => $url, 'name' => $title, 'description' => $description, 'mainEntity' => ['@id' => $url . '#guide'], 'isPartOf' => ['@id' => LinkedData::base() . '#website']],
    ['@type' => 'Article', '@id' => $url . '#guide', 'url' => $url, 'headline' => $title, 'description' => $description, 'datePublished' => '2026-10-07', 'dateModified' => $guide['checked'], 'inLanguage' => 'en', 'author' => LinkedData::maintainer(), 'publisher' => LinkedData::maintainer(), 'mainEntityOfPage' => ['@id' => $url . '#page'], 'about' => [LinkedData::term('topic', 'Addiction'), LinkedData::term('substance', 'psilocybin')], 'citation' => array_map(static fn(array $s): array => ['@id' => $s['work_id']], $studies)],
    LinkedData::website(), ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Research database', 'item' => LinkedData::base()],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Research guide', 'item' => LinkedData::base() . 'psilocybin-research-guide/'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Alcohol use disorder evidence', 'item' => $url],
    ]],
]);
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base href="/">
<title><?= h($title) ?></title><?= detail_page_seo($title, $description, $path, ['robots' => $robots, 'type' => 'article']) ?>
<?= json_ld_script($schema) ?>
<link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>"><script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head><body class="detail-page evidence-guide-page">
<?= detail_page_header('alcohol-evidence') ?><main class="detail-shell" id="main"><article>
<nav class="recruiting-breadcrumbs" aria-label="Breadcrumb"><a href="./">Research database</a><span aria-hidden="true">/</span><a href="psilocybin-research-guide/">Research guide</a><span aria-hidden="true">/</span><span aria-current="page">Alcohol use disorder evidence</span></nav>
<header class="panel detail-hero evidence-guide-hero"><span class="eyebrow">Research evidence · Alcohol use disorder</span>
<h1>Psilocybin and alcohol use disorder: what the studies show</h1>
<p class="evidence-guide-lead">A randomized study reported fewer heavy drinking days with psilocybin-assisted psychotherapy in 2022. A smaller 2025 trial after withdrawal treatment did not find significant differences in its primary outcomes. These studies address different clinical questions; reading them together helps avoid turning an encouraging result into a universal treatment claim.</p>
<p class="evidence-guide-meta">Maintained by <a href="editorial-policy.php#author">Dr. Christopher B. Germann</a> · Sources checked <time datetime="2026-10-07">7 October 2026</time> · <a href="<?= h($path) ?>#scope">Selection and sources</a></p>
<div class="research-shortcuts"><a href="<?= h($path) ?>#comparison">Compare three reports</a><a href="psilocybin-clinical-trials-alcohol-use-disorder/">Explore registered trials</a><a href="<?= h($path) ?>#interpretation">Understand the differences</a></div>
<p class="evidence-guide-note">Selected research interpretation, not a systematic review or personal treatment recommendation. No independent clinical review is claimed. Do not change alcohol treatment or prescribed medication based on this page.</p></header>
<section class="panel detail-card" id="comparison"><h2>Alcohol studies compared by design and endpoint</h2>
<p>A reduction in heavy drinking days and prevention of relapse after withdrawal are different endpoints. The table keeps the study population, comparator and follow-up attached to each result.</p>
<div class="research-table-wrap" role="region" aria-label="Scrollable alcohol study comparison" tabindex="0"><table class="research-table evidence-comparison"><caption>Three selected primary reports; results are not pooled.</caption>
<thead><tr><th scope="col">Report and population</th><th scope="col">Design and sample</th><th scope="col">Endpoint and finding</th><th scope="col">Interpretation limit</th></tr></thead><tbody>
<?php foreach ($studies as $s): ?><tr id="<?= h($s['key']) ?>"><th scope="row"><a href="<?= h($s['work_id']) ?>"><?= h($s['label']) ?></a><p><?= h($s['population']) ?></p><a href="https://pubmed.ncbi.nlm.nih.gov/<?= h($s['pmid']) ?>/">PubMed <?= h($s['pmid']) ?></a><?php if ($s['paper']): ?><br><a href="publication.php?id=<?= (int)$s['paper']['id'] ?>">Database record and citations</a><?php endif; ?><?php if ($s['nct']): ?><br><a href="https://clinicaltrials.gov/study/<?= h($s['nct']) ?>"><?= h($s['nct']) ?></a><?php endif; ?></th><td><?= h($s['design']) ?><p><strong><?= h($s['sample']) ?></strong></p></td><td><span class="evidence-outcome"><?= h($s['outcome']) ?></span><p><?= h($s['endpoint']) ?></p><p><?= h($s['result']) ?></p></td><td><?= h($s['limitation']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($ids): ?><p class="research-shortcuts"><a href="export.php?<?= h(http_build_query(['format' => 'bibtex', 'ids' => $ids])) ?>">Export <?= count($ids) ?> indexed references as BibTeX</a><a href="export.php?<?= h(http_build_query(['format' => 'ris', 'ids' => $ids])) ?>">RIS for citation managers</a></p><?php endif; ?>
<p>Exports include visible indexed records only. DOI and PubMed links remain available when a record is absent or withheld.</p></section>
<section class="panel detail-card" id="interpretation"><h2>Why the trial results should not be treated as interchangeable</h2>
<p>The 2022 study assessed drinking over a prolonged follow-up in a community-recruited sample. The 2025 study examined relapse following withdrawal treatment. Differences in initial drinking status, treatment intensity, session number and outcomes prevent a simple direct comparison.</p>
<p>A statistically significant result does not show that every participant benefited. A nonsignificant result does not establish that two interventions are equivalent. Read the effect estimate, confidence interval and analysis population before deciding how much weight to give either finding.</p>
<p>Both randomized programmes included psychotherapy. Their comparisons evaluate interventions within those programmes; they do not isolate an effect of psilocybin without support. The uncontrolled pilot is useful historical context but cannot resolve this causal question.</p>
<p>See the <a href="psilocybin-research-guide/#reading-results">research-reading checklist</a> for endpoints, confidence intervals, blinding and study status.</p></section>
<section class="panel detail-card" id="safety"><h2>Safety findings and eligibility need their own assessment</h2>
<p>Trial participants are screened, monitored and followed under a defined protocol. A small study with few serious events cannot establish safety for rare harms, excluded populations or unsupervised use. Safety reporting also needs denominators, follow-up duration and a distinction between an event occurring during a study and one attributed to the intervention.</p>
<p>Alcohol withdrawal and medication decisions require appropriate clinical assessment. Use the original reports and <a href="https://www.nccih.nih.gov/health/psilocybin-for-mental-health-and-addiction-what-you-need-to-know">NCCIH's research and safety overview</a> to understand context. Participation is governed by each study's eligibility rules and contacts, not this website.</p></section>
<section class="panel detail-card" id="questions"><h2>Common research questions</h2>
<details class="research-faq"><summary>Does this establish that psilocybin treats alcohol use disorder?</summary><p>These selected studies provide mixed evidence for defined supervised interventions. They do not establish effectiveness across all populations or care settings. The table identifies what each study tested.</p></details>
<details class="research-faq"><summary>Is abstinence the same as fewer heavy drinking days?</summary><p>No. Complete abstinence, time to relapse, average consumption and heavy drinking days describe different aspects of drinking. A favourable result on one cannot be substituted for another.</p></details>
<details class="research-faq"><summary>Where can I find studies accepting participants?</summary><p>Start with the <a href="psilocybin-clinical-trials-alcohol-use-disorder/">alcohol use disorder trial collection</a> and <a href="recruiting-psilocybin-clinical-trials/">recruiting trial finder</a>. Confirm status, location and eligibility directly with the registry and study team; a registration does not guarantee a place.</p></details></section>
<section class="panel detail-card" id="scope"><h2>Selection, corrections and sources</h2>
<p>This guide selects an early pilot and two randomized reports to illustrate distinct alcohol research questions. It is not an exhaustive search, formal risk-of-bias assessment, meta-analysis or guideline. Original annotations were checked against primary bibliographic records on 7 October 2026; no source abstracts are redistributed.</p>
<p>The 2022 article has a <a href="https://pmc.ncbi.nlm.nih.gov/articles/PMC9475431/">published correction concerning race and ethnicity counts</a>. Consult the corrected original when examining its participant characteristics.</p>
<p>These reports may not represent every subsequent publication. Check the <a href="./?search=alcohol">live alcohol literature search</a> for newer records and the <a href="editorial-policy.php">editorial policy</a> for source selection and corrections. Related resources: <a href="psilocybin-depression-research/">depression evidence</a>, <a href="research-dataset.php">dataset methods and reuse</a>, <a href="evidence.php">evidence map</a>.</p>
</section></article></main><?= detail_footer() ?><?= detail_scroll_top() ?></body></html>
