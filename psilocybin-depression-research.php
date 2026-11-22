<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$db = new Database();
$db->initialize();
$guide = DepressionEvidence::collection($db);
$studies = $guide['studies'];
$ids = array_values(array_filter(array_map(static fn(array $study): ?int => $study['paper'] ? (int)$study['paper']['id'] : null, $studies)));
$assetVersion = '20261011-evidence-performance-v198';
$path = 'psilocybin-depression-research/';
$url = LinkedData::base() . $path;
$title = 'Psilocybin for Depression: Research Evidence and Limitations';
$description = 'Compare psilocybin depression studies, primary outcomes, sample sizes, safety findings and limitations, including 2026 trials and links to original research.';
$robots = $_GET ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
if ($_GET) header('X-Robots-Tag: noindex, follow');
$article = [
    '@type' => 'Article', '@id' => $url . '#guide', 'headline' => $title, 'description' => $description,
    'url' => $url, 'datePublished' => '2026-10-07', 'dateModified' => $guide['checked'], 'inLanguage' => 'en',
    'author' => LinkedData::maintainer(), 'publisher' => LinkedData::maintainer(),
    'mainEntityOfPage' => ['@id' => $url . '#page'],
    'about' => [LinkedData::term('topic', 'Depression'), LinkedData::term('substance', 'psilocybin')],
    'citation' => array_map(static fn(array $study): array => ['@id' => $study['work_id']], $studies),
];
$schema = LinkedData::document([
    ['@type' => 'WebPage', '@id' => $url . '#page', 'url' => $url, 'name' => $title, 'description' => $description, 'mainEntity' => ['@id' => $url . '#guide'], 'isPartOf' => ['@id' => LinkedData::base() . '#website']],
    $article, LinkedData::website(),
    ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Research database', 'item' => LinkedData::base()],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Depression evidence', 'item' => $url],
    ]],
]);
?>
<!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base href="/">
  <title><?= h($title) ?></title>
  <?= detail_page_seo($title, $description, $path, ['robots' => $robots, 'type' => 'article']) ?>
  <?= json_ld_script($schema) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head><body class="detail-page evidence-guide-page">
<?= detail_page_header('depression-evidence') ?>
<main class="detail-shell" id="main">
  <nav class="recruiting-breadcrumbs" aria-label="Breadcrumb"><a href="./">Research database</a><span aria-hidden="true">/</span><span aria-current="page">Depression evidence</span></nav>
  <article>
    <header class="panel detail-hero evidence-guide-hero">
      <span class="eyebrow">Research evidence · Depression</span>
      <h1>Psilocybin for depression: evidence and limitations</h1>
      <p class="evidence-guide-lead">Several randomized trials report short-term improvements in depression symptoms with supervised psilocybin and psychological support. Results differ across populations and comparators, and some trials have not met their primary endpoint. Longer-term benefit, safety and performance against established treatments remain important research questions.</p>
      <p class="evidence-guide-meta">Maintained by <a href="editorial-policy.php#author">Dr. Christopher B. Germann</a> · Sources checked <time datetime="<?= h($guide['checked']) ?>">7 October 2026</time> · <a href="<?= h($path) ?>#scope">Selection and limitations</a></p>
      <div class="research-shortcuts"><a href="<?= h($path) ?>#study-comparison">Compare eight reports</a><a href="psilocybin-clinical-trials-depression/">Explore depression trials</a><a href="<?= h($path) ?>#phase-three">Phase 3 updates</a></div>
      <p class="evidence-guide-note">This is a selected research guide, not a systematic review or individualized treatment advice. No independent clinical review is claimed. Study interventions are supervised research protocols, not evidence about unsupervised mushroom use.</p>
    </header>

    <nav class="panel evidence-guide-contents" aria-label="On this page"><strong>On this page</strong><a href="<?= h($path) ?>#study-comparison">Study comparison</a><a href="<?= h($path) ?>#interpretation">Reading the results</a><a href="<?= h($path) ?>#phase-three">Sponsor updates</a><a href="<?= h($path) ?>#safety">Safety and eligibility</a><a href="<?= h($path) ?>#questions">Common questions</a><a href="<?= h($path) ?>#scope">Methods and sources</a></nav>

    <section class="panel detail-card" id="study-comparison">
      <span class="eyebrow">Primary research reports</span><h2>Depression studies compared by design and outcome</h2>
      <p>Major depressive disorder (MDD) and treatment-resistant depression (TRD) are different study populations. Read each result with its comparator, endpoint and follow-up. A registration describes a study; a publication reports findings.</p>
      <div class="research-table-wrap" role="region" aria-label="Scrollable depression study comparison" tabindex="0"><table class="research-table evidence-comparison">
        <caption>Eight selected randomized reports, including three published in 2026. Numerical estimates are those reported by the cited authors; they are not pooled.</caption>
        <thead><tr><th scope="col">Report and population</th><th scope="col">Design and sample</th><th scope="col">Endpoint and finding</th><th scope="col">Interpretation limit</th></tr></thead>
        <tbody><?php foreach ($studies as $study): ?><tr id="<?= h($study['key']) ?>">
          <th scope="row"><a href="<?= h($study['work_id']) ?>"><?= h($study['label']) ?></a><p><?= h($study['population']) ?></p><a href="https://pubmed.ncbi.nlm.nih.gov/<?= h($study['pmid']) ?>/">PubMed <?= h($study['pmid']) ?></a><?php if ($study['paper']): ?><br><a href="publication.php?id=<?= h((string)$study['paper']['id']) ?>">Database record and citations</a><?php endif; ?><?php if ($study['nct']): ?><br><a href="https://clinicaltrials.gov/study/<?= h($study['nct']) ?>"><?= h($study['nct']) ?></a><?php endif; ?></th>
          <td><?= h($study['design']) ?><p><strong><?= h($study['sample']) ?></strong></p></td>
          <td><span class="evidence-outcome"><?= h($study['outcome']) ?></span><p><?= h($study['endpoint']) ?></p><p><?= h($study['result']) ?></p></td>
          <td><?= h($study['limitation']) ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
      <?php if ($ids): ?><p class="research-shortcuts"><a href="export.php?<?= h(http_build_query(['format' => 'bibtex', 'ids' => $ids])) ?>">Export <?= count($ids) ?> indexed references as BibTeX</a><a href="export.php?<?= h(http_build_query(['format' => 'ris', 'ids' => $ids])) ?>">RIS for citation managers</a></p><?php endif; ?>
      <p>Each source link remains available even if its record has not yet been indexed or has been withheld for curation. The exports contain only the visible indexed references, not every study on this page.</p>
    </section>

    <section class="panel detail-card" id="interpretation">
      <h2>How to read a positive or inconclusive result</h2>
      <div class="evidence-reading-grid">
        <div><h3>Start with the primary endpoint</h3><p>A trial specifies its main test in advance. In EPISODE, the primary response comparison was not significant; secondary symptom-score changes remain exploratory. The escitalopram comparison also did not meet its primary test. Positive secondary numbers should not replace these results. <a href="<?= h($path) ?>#episode-2026">EPISODE</a> · <a href="<?= h($path) ?>#carhart-harris-2021">Escitalopram trial</a>.</p></div>
        <div><h3>Compare like with like</h3><p>MADRS, HAMD and QIDS are different depression scales. A symptom-score difference, a response percentage and remission are different outcomes. Even studies using MADRS can differ in population, comparator and timing. These figures should not be ranked as though they measure the same treatment effect.</p></div>
        <div><h3>Keep the treatment setting in view</h3><p>The selected studies included psychological support or psychotherapy. A waiting-list comparison cannot isolate the pharmacological contribution from the wider intervention. Noticeable psychedelic effects also make masking a challenge; a nominally blinded design alone does not establish successful masking.</p></div>
        <div><h3>Separate short-term change from durability</h3><p>A significant result after days or weeks cannot establish a sustained benefit after a year. In the Stockholm trial, the clinician-rated difference was significant at day 8 but not day 365. The later self-report pattern involved different, secondary assessments. <a href="<?= h($path) ?>#yngwe-2026">Stockholm report</a>.</p></div>
      </div>
    </section>

    <section class="panel detail-card" id="phase-three">
      <span class="eyebrow">Separate evidence category</span><h2>Phase 3 results and sponsor announcements</h2>
      <p>Compass Pathways reported that COMP006 met its six-week primary endpoint, with a −3.8-point MADRS difference for its 25 mg versus 1 mg regimen. This statement appears in the company's <a href="https://www.sec.gov/Archives/edgar/data/1816590/000181659026000007/cmps-20260217.htm">17 February 2026 SEC filing</a>. It is a sponsor-reported topline result and is not treated here as a substitute for a full peer-reviewed report.</p>
      <p>The sponsor also released <a href="https://ir.compasspathways.com/News--Events-/news/news-details/2026/Compass-Pathways-New-52-Week-Topline-Open-Label-Data-from-Phase-3-COMP005-Trial-Demonstrates-Additional-Benefit-from-Another-COMP360-Dose-in-Part-C-Extending-Durability-Out-to-1-Year/default.aspx">COMP005 52-week data on 9 September 2026</a>. Part C was open-label and allowed additional treatment. Its baseline changes should not be interpreted as a blinded one-year comparison or as proof that one administration produces a year of benefit.</p>
      <p>Check the original reports for methods, missing data, adverse events and analysis populations. Development milestones and efficacy evidence are separate questions; an announcement does not establish regulatory approval.</p>
    </section>

    <section class="panel detail-card" id="safety">
      <h2>Safety findings and who the evidence describes</h2>
      <p>Trials select participants and provide monitoring and follow-up. Their results do not automatically apply to people excluded by a protocol. The Raison trial excluded, among other groups, people with a history of psychosis or mania and active suicidal intent; medication users could enter following taper. <a href="<?= h($path) ?>#raison-2023">Read the study context</a>.</p>
      <p>Small studies cannot rule out uncommon harms. The Goodwin trial recorded adverse events and suicidal ideation, behavior or self-injury across groups; EPISODE reported serious adverse reactions; the Stockholm study described severe persistent anxiety in two participants. These reports need their full clinical context and should neither be ignored nor converted into a causal risk estimate this guide cannot support.</p>
      <p><strong>Do not change psychiatric medication to qualify for a study based on this website.</strong> Eligibility and any medication requirements must be discussed with the study team and the prescriber responsible for your care. A registry listing does not determine personal eligibility.</p>
      <p><a href="recruiting-psilocybin-clinical-trials/#participation">Read how to investigate participation</a> or explore the <a href="psilocybin-clinical-trials-depression/">depression registry collection</a>, which includes recruiting and non-recruiting studies.</p>
    </section>

    <section class="panel detail-card evidence-questions" id="questions">
      <h2>Questions about psilocybin depression research</h2>
      <h3>Does psilocybin work for depression?</h3><p>Several selected controlled studies found short-term symptom improvement with supported psilocybin interventions. Others did not meet their primary endpoint. The evidence supports further investigation, with conclusions tied to the population, comparator and time point studied. It does not predict an individual's outcome.</p>
      <h3>Has it been shown to be better than antidepressants?</h3><p>The 2021 escitalopram trial did not demonstrate a significant difference on its primary outcome. Its secondary results do not establish general superiority, and a nonsignificant result is not a demonstration of equivalence. <a href="<?= h($path) ?>#carhart-harris-2021">Compare the primary result</a>.</p>
      <h3>Are treatment-resistant depression results interchangeable with MDD results?</h3><p>No. TRD studies enroll people with inadequate response to prior treatment according to each protocol. Their baseline severity and prior treatment differ from other MDD samples. Keep these populations separate when screening studies or interpreting effect sizes.</p>
      <h3>Where can I find ongoing depression studies?</h3><p>Our <a href="psilocybin-clinical-trials-depression/">depression trial collection</a> groups registry records by condition. For recruitment discovery use the <a href="recruiting-psilocybin-clinical-trials/">exact-status recruiting collection</a> and confirm contacts, eligibility and local availability at ClinicalTrials.gov.</p>
    </section>

    <section class="panel detail-card" id="scope">
      <span class="eyebrow">Methods and accountability</span><h2>How this guide was assembled</h2>
      <p>The selection covers eight primary randomized reports in MDD or TRD, chosen to illustrate waiting-list, placebo, active-treatment and feasibility designs, alongside relevant 2026 findings. It is a purposive educational selection, not a complete search, risk-of-bias assessment, systematic review or meta-analysis. Cancer-related distress, bipolar depression, observational programs and secondary analyses are outside this comparison.</p>
      <p>Bibliographic identity, sample descriptions, endpoints and numerical findings were checked against primary-report records on 7 October 2026. Source records and sponsor disclosures are linked separately. The annotations are original summaries of factual findings; unverified abstracts and source text are not redistributed. No formal certainty grade, pooled estimate or independent clinical-review credit is assigned.</p>
      <p>The eight report DOIs and their linked PubMed records define this guide's source set. Database updates can add a tracker link but do not silently update the interpretation or the source-check date. <a href="editorial-policy.php#corrections-heading">Report a correction with its authoritative source</a>.</p>
      <div class="research-shortcuts"><a href="<?= h(tracker_query_url(['search' => 'depression', 'range' => 'all'])) ?>">Search the wider depression literature</a><a href="evidence.php">Topic and design evidence map</a><a href="research-dataset.php">Download and cite research metadata</a><a href="./#alerts">Monitor new publications</a></div>
    </section>
  </article>
</main>
<?= detail_footer() ?><?= detail_scroll_top() ?>
</body></html>
