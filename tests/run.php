<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/ViewHelpers.php';

putenv('PUBLICATION_TRACKER_DATA_DIR=' . sys_get_temp_dir() . '/publication-tracker-test-data');
putenv('PUBLICATION_TRACKER_LOG_FILE=' . sys_get_temp_dir() . '/publication-tracker-test-app.log');
putenv('PUBLICATION_TRACKER_HEARTBEAT_DIR=' . sys_get_temp_dir() . '/publication-tracker-test-heartbeat');

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db = new Database($pdo);
$db->initialize();
$repo = new PublicationRepository($db);

$originalGet = $_GET;
$_GET = ['q' => 'Germann'];
$defaultSearchFilters = RequestFilters::fromGlobals();
$_GET = ['q' => 'Germann', 'range' => '5y'];
$explicitFiveYearFilters = RequestFilters::fromGlobals();
$_GET = $originalGet;
assert_true($defaultSearchFilters['from'] === null && $defaultSearchFilters['to'] === null, 'initial search without range should search all years');
assert_true(is_string($explicitFiveYearFilters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $explicitFiveYearFilters['from']) === 1, 'explicit 5-year range should still apply a lower date bound');
assert_true(str_contains(publication_recency_badge(gmdate('Y-m-d')), 'New this week') && str_contains(publication_recency_badge(gmdate('Y-m-d', strtotime('-14 days'))), 'New this month') && publication_recency_badge(gmdate('Y-m-d', strtotime('-40 days'))) === '', 'publication recency badge window failed');

$legacyPdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$legacyPdo->exec('CREATE TABLE publications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    normalized_title TEXT NOT NULL,
    authors TEXT,
    abstract TEXT,
    journal TEXT,
    publication_date TEXT,
    publication_year INTEGER,
    doi TEXT,
    pubmed_id TEXT,
    source_url TEXT,
    keywords TEXT,
    substance_tags TEXT NOT NULL DEFAULT "",
    topic_tags TEXT NOT NULL DEFAULT "",
    study_type TEXT,
    hidden INTEGER NOT NULL DEFAULT 0,
    false_positive INTEGER NOT NULL DEFAULT 0,
    curation_notes TEXT,
    curation_locked INTEGER NOT NULL DEFAULT 0,
    merged_into_id INTEGER,
    source_name TEXT,
    date_added TEXT NOT NULL,
    last_checked TEXT NOT NULL,
    raw_json TEXT,
    UNIQUE(doi),
    UNIQUE(pubmed_id)
)');
(new Database($legacyPdo))->initialize();
$legacyColumns = array_column($legacyPdo->query('PRAGMA table_info(publications)')->fetchAll(), 'name');
assert_true(in_array('publication_status', $legacyColumns, true), 'legacy migration should add publication_status before indexes');
assert_true(in_array('openalex_id', $legacyColumns, true), 'legacy migration should add openalex_id before indexes');
assert_true($legacyPdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'publication_authors'")->fetchColumn() === 'publication_authors', 'legacy migration should create normalized author table');
assert_true($legacyPdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'publication_topics'")->fetchColumn() === 'publication_topics', 'legacy migration should create normalized topic table');
assert_true($legacyPdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'openalex_quality_reviews'")->fetchColumn() === 'openalex_quality_reviews', 'legacy migration should create OpenAlex quality review table');

$base = [
    'title' => 'Psilocybin therapy for depression',
    'authors' => 'Doe J, Smith A',
    'abstract' => 'A psilocybin clinical trial for depression.',
    'journal' => 'Journal of Psychopharmacology',
    'publication_date' => gmdate('Y-m-d'),
    'doi' => 'https://doi.org/10.1000/test',
    'pubmed_id' => '123',
    'source_url' => 'https://example.org/a',
    'keywords' => 'depression, psilocybin',
    'source_name' => 'test',
];

assert_true($repo->upsert($base) === 'inserted', 'initial insert failed');
assert_true($repo->upsert(array_merge($base, ['title' => 'Changed title same DOI'])) === 'updated', 'DOI dedupe failed');
assert_true($repo->upsert($base) === 'updated', 'repeated insert should update, not duplicate');
$stats = $repo->stats();
assert_true($stats['total'] === 1, 'dedupe created duplicate row');
assert_true($repo->upsert(array_merge($base, ['doi' => '10.1000/published-version', 'pubmed_id' => '124'])) === 'updated', 'normalized-title fallback should reconcile version records with different identifiers');
assert_true($repo->stats()['total'] === 1, 'version reconciliation created a duplicate row');
assert_true((int)$pdo->query('SELECT COUNT(*) FROM publication_authors')->fetchColumn() === 2, 'upsert should maintain normalized author rows');
assert_true((int)$pdo->query('SELECT COUNT(*) FROM publication_topics')->fetchColumn() > 0, 'upsert should maintain normalized topic rows');
assert_true($repo->authors('Doe', 10)[0]['name'] === 'Doe J', 'author directory should use normalized author index');
assert_true($repo->search(['q' => 'clinical depression', 'per_page' => 10])['total'] === 1, 'FTS keyword search failed');

assert_true($repo->upsert(array_merge($base, [
    'doi' => null,
    'pubmed_id' => '456',
    'title' => 'Psilocin pharmacokinetics in humans',
    'abstract' => 'Human 4-HO-DMT metabolism and psilocin pharmacokinetics.',
    'publication_date' => gmdate('Y-m-d', strtotime('-2 years')),
])) === 'inserted', 'second insert failed');

assert_true($repo->upsert(array_merge($base, [
    'doi' => '10.1101/preprint-test',
    'pubmed_id' => null,
    'title' => 'Psilocybin preprint on telomere biology',
    'abstract' => 'A psilocybin preprint about telomeres and aging.',
    'journal' => 'PsyArXiv',
    'publication_date' => gmdate('Y-m-d', strtotime('-3 days')),
    'source_url' => 'https://psyarxiv.com/preprint-test',
    'keywords' => 'psilocybin, preprint',
    'source_name' => 'PsyArXiv',
    'publication_status' => 'preprint',
])) === 'inserted', 'preprint insert failed');
assert_true($repo->search(['sources' => ['PsyArXiv'], 'publication_statuses' => ['preprint'], 'substances' => ['psilocybin'], 'per_page' => 10])['total'] === 1, 'source/status filtering failed');
assert_true(PublicationRepository::normalizePublicationStatus(null, 'ClinicalTrials.gov') === 'clinical trial', 'clinical trial source should normalize status');
assert_true(PublicationRepository::normalizePublicationStatus(null, 'medRxiv') === 'preprint', 'medRxiv source should normalize as preprint');
assert_true(PublicationRepository::normalizePublicationStatus(null, 'bioRxiv') === 'preprint', 'bioRxiv source should normalize as preprint');

assert_true($repo->upsert(array_merge($base, [
    'doi' => '10.1016/j.mehy.2019.109406',
    'pubmed_id' => '31634774',
    'title' => 'The Psilocybin-Telomere Hypothesis',
    'authors' => 'Germann CB.',
    'abstract' => 'A psilocybin hypothesis about telomere length and genetic aging.',
    'journal' => 'Medical Hypotheses',
    'publication_date' => '2019-09-23',
    'keywords' => 'psilocybin, telomere',
    'source_name' => 'Europe PMC',
])) === 'inserted', 'abbreviated author fixture insert failed');
assert_true($repo->search(['q' => 'Christopher B. Germann', 'substances' => ['psilocybin'], 'range' => 'all', 'per_page' => 10])['total'] >= 1, 'full author name should match abbreviated source metadata');
assert_true($repo->search(['author' => 'Christopher B Germann', 'substances' => ['psilocybin'], 'range' => 'all', 'per_page' => 10])['total'] >= 1, 'author filter should match abbreviated source metadata');
assert_true($repo->search(['q' => '10.1016/j.mehy.2019.109406', 'substances' => ['psilocybin'], 'range' => 'all', 'per_page' => 10])['total'] >= 1, 'DOI search should match publication identifiers');
assert_true($repo->search(['q' => '31634774', 'substances' => ['psilocybin'], 'range' => 'all', 'per_page' => 10])['total'] >= 1, 'PubMed ID search should match publication identifiers');
assert_true($repo->upsert(array_merge($base, [
    'doi' => '10.1016/j.mehy.2019.109406',
    'pubmed_id' => '31634774',
    'title' => 'OpenAlex enriched title should not replace primary source title',
    'authors' => 'Christopher B. Germann',
    'abstract' => 'OpenAlex psilocybin enrichment metadata.',
    'journal' => 'Medical Hypotheses',
    'publication_date' => '2019-09-24',
    'keywords' => 'psilocybin, telomere, OpenAlex',
    'source_name' => 'OpenAlex',
    'raw' => [
        'openalex_id' => 'https://openalex.org/W9876543210',
        'authorships' => [[
            'display_name' => 'Christopher B. Germann',
            'id' => 'https://openalex.org/A5072900702',
            'orcid' => 'https://orcid.org/0000-0002-1573-4651',
        ]],
    ],
])) === 'updated', 'OpenAlex DOI enrichment should update existing source row');
$enriched = $repo->search(['q' => 'W9876543210', 'substances' => ['psilocybin'], 'range' => 'all', 'per_page' => 10]);
assert_true($enriched['total'] === 1 && $enriched['rows'][0]['source_name'] === 'Europe PMC' && $enriched['rows'][0]['title'] === 'The Psilocybin-Telomere Hypothesis', 'OpenAlex enrichment should preserve primary source metadata');
$germannProfile = $repo->authorProfile('Christopher B Germann');
assert_true($germannProfile !== null && $germannProfile['orcid'] === '0000-0002-1573-4651' && $germannProfile['openalex_id'] === 'https://openalex.org/A5072900702', 'author profile should read OpenAlex identifiers from enrichment metadata');

assert_true($repo->upsert(array_merge($base, [
    'doi' => null,
    'pubmed_id' => null,
    'title' => 'OpenAlex indexed psilocybin bibliometrics record',
    'authors' => 'OpenAlex A',
    'abstract' => 'A psilocybin bibliometrics record from OpenAlex.',
    'publication_date' => '2026-01-01',
    'source_name' => 'OpenAlex',
    'raw' => ['openalex_id' => 'https://openalex.org/W1234567890'],
])) === 'inserted', 'OpenAlex ID insert failed');
assert_true($repo->upsert(array_merge($base, [
    'doi' => null,
    'pubmed_id' => null,
    'title' => 'OpenAlex duplicate psilocybin bibliometrics record',
    'authors' => 'OpenAlex A',
    'abstract' => 'A psilocybin bibliometrics record from OpenAlex.',
    'publication_date' => '2026-01-02',
    'source_name' => 'OpenAlex',
    'raw' => ['openalex_id' => 'W1234567890'],
])) === 'updated', 'OpenAlex ID dedupe failed');
assert_true($repo->search(['q' => 'W1234567890', 'substances' => ['psilocybin'], 'range' => 'all', 'per_page' => 10])['total'] === 1, 'OpenAlex ID search failed');
assert_true($repo->upsert(array_merge($base, [
    'doi' => null,
    'pubmed_id' => null,
    'title' => 'Robotics: Growing maintenance option for utilities',
    'authors' => 'OpenAlex B',
    'abstract' => 'This mismatched OpenAlex abstract mentions psilocin but the standalone title does not.',
    'publication_date' => '2026-01-03',
    'source_name' => 'OpenAlex',
    'raw' => ['openalex_id' => 'https://openalex.org/W2234567890'],
])) === 'skipped', 'standalone OpenAlex rows should require direct title evidence or broader psychedelic title context with explicit psilocybin/psilocin metadata evidence');
$rejectedOpenAlex = $repo->openAlexReviewDecision('W2234567890');
assert_true($rejectedOpenAlex !== null && $rejectedOpenAlex['decision'] === 'rejected', 'skipped OpenAlex rows should be recorded in quality review workflow');
assert_true($repo->upsert(array_merge($base, [
    'doi' => '10.5281/zenodo.4208128',
    'pubmed_id' => null,
    'title' => 'Best [PDF] The Psilocybin Mushroom Bible: The Definitive Guide to Growing and Using Magic Mushrooms Full PDF Online',
    'authors' => 'Download Artifact',
    'abstract' => 'Read Online => Read The Psilocybin Mushroom Bible. Download Book => Download The Psilocybin Mushroom Bible. #downloadbook',
    'publication_date' => '2020-11-01',
    'source_name' => 'OpenAlex',
    'raw' => ['openalex_id' => 'https://openalex.org/W4287610338'],
])) === 'skipped', 'non-scholarly PDF/download artifacts should be skipped');
assert_true(OpenAlexFetcher::paperFromWork([
    'id' => 'https://openalex.org/W555',
    'display_name' => 'Psilocybin therapy and depression outcomes',
    'abstract_inverted_index' => ['psilocybin' => [0], 'trial' => [1]],
    'publication_date' => '2026-01-01',
    'authorships' => [['author' => ['display_name' => 'Example A', 'id' => 'https://openalex.org/A1']]],
    'primary_location' => ['source' => ['display_name' => 'Example Journal']],
    'concepts' => [['display_name' => 'Psilocybin']],
], 2) !== null, 'OpenAlex relevant work should map to paper');
assert_true(OpenAlexFetcher::paperFromWork([
    'id' => 'https://openalex.org/W556',
    'display_name' => 'General psychotherapy outcomes',
    'abstract_inverted_index' => ['therapy' => [0]],
    'publication_date' => '2026-01-01',
    'concepts' => [['display_name' => 'Psychotherapy']],
], 2) === null, 'OpenAlex weak non-psilocybin work should be rejected');
assert_true(OpenAlexFetcher::paperFromWork([
    'id' => 'https://openalex.org/W557',
    'display_name' => 'Among the New Books',
    'abstract_inverted_index' => [],
    'publication_date' => '2026-01-01',
    'concepts' => [['display_name' => 'Psychedelics and Drug Studies']],
    'keywords' => [['display_name' => 'Magic mushroom']],
], 2) === null, 'OpenAlex broad magic-mushroom metadata should be rejected');
assert_true(OpenAlexFetcher::paperFromWork([
    'id' => 'https://openalex.org/W558',
    'display_name' => 'Seeking a fun-guide',
    'abstract_inverted_index' => [],
    'publication_date' => '2026-01-01',
    'concepts' => [['display_name' => 'Counterculture']],
    'keywords' => [['display_name' => 'MAGIC (telescope)'], ['display_name' => 'Psilocybin']],
], 2) === null, 'OpenAlex standalone records should not pass on keyword-only psilocybin metadata');

$filtered = $repo->search(['from' => gmdate('Y-m-d', strtotime('-1 day')), 'to' => gmdate('Y-m-d', strtotime('+1 day')), 'substances' => ['psilocybin'], 'per_page' => 10]);
assert_true($filtered['total'] === 1, 'date/substance filtering failed');

$searched = $repo->search(['q' => 'pharmacokinetics', 'substances' => ['psilocin'], 'per_page' => 10]);
assert_true($searched['total'] === 1, 'keyword search failed');

$authorFiltered = $repo->search(['author' => 'Doe', 'substances' => ['psilocybin'], 'per_page' => 10]);
assert_true($authorFiltered['total'] >= 1, 'author filtering failed');
assert_true(str_contains(PublicationRepository::classifyTopics('psilocybin treatment-resistant depression safety telomeres'), 'Depression'), 'topic classification failed');
assert_true(str_contains(PublicationRepository::classifyTopics('psilocybin treatment-resistant depression safety telomeres'), 'Telomeres'), 'expanded topic classification failed');
assert_true(PublicationRepository::classifyStudyType('randomized controlled clinical trial of psilocybin') === 'Randomized Controlled Trial', 'study type classification failed');
assert_true(PublicationRepository::detectSubstances('magic mushroom compost improves yield') === '', 'broad magic mushroom match should be ignored');
assert_true(str_contains(PublicationRepository::detectSubstances('psychedelic magic mushroom study'), 'psilocybin'), 'contextual magic mushroom match failed');
assert_true(PublicationRepository::detectSubstances('Psilocybins and psilocins') === 'psilocybin,psilocin', 'inflected psilocybin and psilocin detection failed');
assert_true(clean_scientific_text('Contribution of the Serotonin 5‐ <scp> HT <sub>2A</sub> </scp> Receptor') === 'Contribution of the Serotonin 5-HT2A Receptor', 'scientific title markup cleanup failed');

for ($i = 0; $i < 6; $i++) {
    $repo->upsert([
        'title' => 'Latest psilocybin paper ' . $i,
        'authors' => 'Researcher ' . $i,
        'abstract' => 'New psilocybin paper.',
        'journal' => 'Latest Journal',
        'publication_date' => gmdate('Y-m-d', strtotime('+' . $i . ' days')),
        'doi' => '10.1000/latest-' . $i,
        'pubmed_id' => (string)(9000 + $i),
        'source_url' => 'https://example.org/latest-' . $i,
        'keywords' => 'psilocybin',
        'source_name' => 'test',
    ]);
}
for ($i = 0; $i < 30; $i++) {
    $repo->upsert([
        'title' => 'Long tail psilocybin author paper ' . $i,
        'authors' => 'Common Author ' . $i,
        'abstract' => 'New psilocybin paper.',
        'journal' => 'Long Tail Journal',
        'publication_date' => gmdate('Y-m-d', strtotime('-' . (20 + $i) . ' days')),
        'doi' => '10.1000/long-tail-' . $i,
        'pubmed_id' => (string)(9900 + $i),
        'source_url' => 'https://example.org/long-tail-' . $i,
        'keywords' => 'psilocybin',
        'source_name' => 'test',
    ]);
}
$repo->upsert([
    'title' => 'Rare Hecker psilocybin paper',
    'authors' => 'Hecker L, Example C',
    'abstract' => 'A psilocybin paper with a long-tail author.',
    'journal' => 'Long Tail Journal',
    'publication_date' => gmdate('Y-m-d', strtotime('-90 days')),
    'doi' => '10.1000/rare-hecker',
    'pubmed_id' => '99888',
    'source_url' => 'https://example.org/rare-hecker',
    'keywords' => 'psilocybin',
    'source_name' => 'test',
    'raw' => [
        'authorships' => [
            ['display_name' => 'Hecker L'],
            ['display_name' => 'Example C', 'id' => 'https://openalex.org/A5133076583', 'orcid' => 'https://orcid.org/0000-0002-4634-7216'],
        ],
    ],
]);
$rareAuthors = $repo->authors('hecker', 5);
assert_true(count($rareAuthors) === 1 && $rareAuthors[0]['name'] === 'Hecker L', 'author search should include long-tail authors before limiting');
assert_true(!in_array('L', PublicationRepository::authorSearchVariants('Hecker L'), true), 'surname-initial author search should not include bare one-letter variants');
$heckerSearch = $repo->search(['author' => 'Hecker L', 'substances' => ['psilocybin'], 'per_page' => 10]);
assert_true($heckerSearch['total'] === 1 && $heckerSearch['rows'][0]['title'] === 'Rare Hecker psilocybin paper', 'surname-initial author search should stay precise');
$heckerProfile = $repo->authorProfile('Hecker L');
assert_true($heckerProfile !== null && $heckerProfile['count'] === 1 && $heckerProfile['orcid'] === null && $heckerProfile['openalex_id'] === null, 'author profile should not inherit identifiers from unrelated co-authors');
$latest = $repo->latest(5);
assert_true(count($latest) === 5, 'latest 5 query returned wrong count');
assert_true($latest[0]['title'] === 'Latest psilocybin paper 5', 'latest 5 query did not sort by publication_date DESC');
$nextLatest = $repo->latest(5, 5);
assert_true(count($nextLatest) >= 1 && $nextLatest[0]['title'] !== $latest[0]['title'], 'latest pagination failed');
$allResults = $repo->search(['substances' => ['psilocybin'], 'per_page' => 'all']);
assert_true($allResults['per_page'] === 'all' && $allResults['page'] === 1 && count($allResults['rows']) === $allResults['total'], 'all-results pagination failed');
$largePage = $repo->search(['substances' => ['psilocybin'], 'per_page' => 200]);
assert_true($largePage['per_page'] === 200, 'results per-page option should allow 200');

$analytics = $repo->analytics();
assert_true(isset($analytics['trends'], $analytics['top_authors'], $analytics['top_journals'], $analytics['topics']), 'analytics payload missing sections');
assert_true(isset($analytics['timeline_papers']) && count($analytics['timeline_papers']) >= 1 && array_key_exists('doi', $analytics['timeline_papers'][0]) && array_key_exists('topic_tags', $analytics['timeline_papers'][0]), 'timeline publication drilldown payload missing');
assert_true(count($repo->sources()) >= 1 && count($repo->publicationStatuses()) >= 1, 'source/status facets missing');
$authors = $repo->authors('Researcher', 3);
assert_true(count($authors) === 3 && str_contains($authors[0]['name'], 'Researcher'), 'author index/search failed');
$publicPaper = $repo->publicById((int)$latest[0]['id']);
assert_true($publicPaper !== null && $publicPaper['title'] === $latest[0]['title'], 'public paper lookup failed');
$selectedPapers = $repo->publicByIds([(int)$latest[0]['id'], (int)$latest[1]['id']]);
assert_true(count($selectedPapers) === 2, 'selected publication collection lookup failed');
$authorProfile = $repo->authorProfile('Researcher 4');
assert_true($authorProfile !== null && $authorProfile['count'] >= 1 && isset($authorProfile['papers'][0]), 'author profile failed');
$relatedPapers = $repo->relatedPapers($latest[1], 3);
assert_true(is_array($relatedPapers), 'related papers lookup failed');
$repo->upsert([
    'title' => 'Psilocybin citation network source paper',
    'authors' => 'Network Researcher, Graph Author',
    'abstract' => 'A psilocybin paper used to test citation network references and author topic edges.',
    'journal' => 'Network Journal',
    'publication_date' => '2026-02-01',
    'doi' => '10.1000/network-source',
    'keywords' => 'psilocybin, citation graph',
    'source_name' => 'test',
    'raw' => ['reference_dois' => ['10.1000/network-target']],
]);
$repo->upsert([
    'title' => 'Psilocybin citation network target paper',
    'authors' => 'Target Researcher',
    'abstract' => 'A psilocybin depression target cited by the source paper.',
    'journal' => 'Network Journal',
    'publication_date' => '2025-02-01',
    'doi' => '10.1000/network-target',
    'keywords' => 'psilocybin, depression',
    'source_name' => 'test',
]);
$network = $repo->citationNetwork(['q' => 'citation network', 'substances' => ['psilocybin'], 'range' => 'all'], 0, 12);
assert_true(count($network['nodes']) >= 4 && count($network['edges']) >= 3, 'citation network graph should include paper and relationship nodes');
assert_true((bool)array_filter($network['edges'], static fn(array $edge): bool => ($edge['type'] ?? '') === 'cites'), 'citation network should include citation edges');
assert_true((bool)array_filter($network['nodes'], static fn(array $node): bool => ($node['type'] ?? '') === 'paper' && isset($node['related']) && is_array($node['related'])), 'citation network paper nodes should include related-paper recommendations');
assert_true((bool)array_filter($network['nodes'], static fn(array $node): bool => ($node['type'] ?? '') === 'paper' && !empty($node['reference_match'])), 'citation network should annotate cited DOI references that resolve to indexed papers');
$trialRows = $repo->trials([], 10);
assert_true(isset($trialRows['rows'], $trialRows['total']), 'trial tracker query failed');
$evidenceMap = $repo->evidenceMap();
assert_true($evidenceMap !== [] && isset($evidenceMap[0]['topic'], $evidenceMap[0]['study_type'], $evidenceMap[0]['substance'], $evidenceMap[0]['year']), 'evidence map failed');
$exportLimited = $repo->allForExport(['substances' => ['psilocybin']], 4);
assert_true(count($exportLimited) === 4, 'export limit pagination failed');
$bib = ExportService::bibtex([$latest[0]]);
$latex = ExportService::latex([$latest[0]]);
$ris = ExportService::ris([$latest[0]]);
$csv = ExportService::csv([$latest[0]]);
$jsonExport = ExportService::json([$latest[0]]);
assert_true(str_contains($bib, '@article'), 'BibTeX export failed');
assert_true(str_contains($latex, '\\documentclass') && str_contains($latex, '\\begin{enumerate}') && str_contains($latex, 'psilocybin-research.com'), 'LaTeX export failed');
assert_true(str_contains($ris, 'TY  - JOUR'), 'RIS export failed');
assert_true(str_contains($csv, 'id,title,authors,journal') && str_contains($csv, 'abstract_available') && str_contains($csv, 'text_rights_status'), 'rights-safe CSV export failed');
assert_true(str_contains($bib, 'https://psilocybin-research.com') && str_contains($latex, 'https://psilocybin-research.com') && str_contains($ris, 'https://psilocybin-research.com') && str_contains($csv, 'tracker_site_url'), 'export provenance metadata missing');

$rightsSentinels = [
    'abstract' => 'DO-NOT-PUBLISH-ABSTRACT-7f25',
    'keywords' => 'DO-NOT-PUBLISH-KEYWORDS-9a31',
    'raw_json' => json_encode(['description' => 'DO-NOT-PUBLISH-RAW-DESCRIPTION-4c82']),
];
$rightsPaper = array_merge($latest[0], $rightsSentinels);
$publicRightsPaper = public_paper($rightsPaper);
$publicRightsPayload = json_encode(rights_safe_public_payload(['papers' => [$rightsPaper]]), JSON_UNESCAPED_SLASHES);
$rightsCsv = ExportService::csv([$rightsPaper]);
$rightsJson = ExportService::json([$rightsPaper]);
assert_true(!array_key_exists('abstract', $publicRightsPaper) && !array_key_exists('keywords', $publicRightsPaper) && !array_key_exists('raw_json', $publicRightsPaper), 'public paper projection must omit source text and unrestricted payloads');
assert_true(($publicRightsPaper['abstract_available'] ?? false) === true && ($publicRightsPaper['abstract_redistributed'] ?? true) === false && ($publicRightsPaper['text_rights_status'] ?? '') === 'unverified_not_redistributed', 'public paper rights indicators are incorrect');
foreach (array_values($rightsSentinels) as $sentinel) {
    assert_true(!str_contains((string)$publicRightsPayload, (string)$sentinel) && !str_contains($rightsCsv, (string)$sentinel) && !str_contains($rightsJson, (string)$sentinel), 'source-text sentinel leaked through public payload or export');
}
assert_true(str_contains($jsonExport, 'rights-safe-core-v1') && !str_contains($jsonExport, '"abstract"') && !str_contains($jsonExport, '"keywords"') && !str_contains($jsonExport, '"raw_json"'), 'JSON export must use the rights-safe core projection');

$repo->curate((int)$latest[0]['id'], ['hidden' => 1, 'false_positive' => 1, 'topic_tags' => 'curated']);
$repo->upsert([
    'title' => $latest[0]['title'],
    'authors' => 'Researcher 5',
    'abstract' => 'New psilocybin paper about depression.',
    'journal' => 'Latest Journal',
    'publication_date' => $latest[0]['publication_date'],
    'doi' => $latest[0]['doi'],
    'pubmed_id' => $latest[0]['pubmed_id'],
    'source_url' => 'https://example.org/latest-5',
    'keywords' => 'psilocybin, depression',
    'source_name' => 'test',
]);
$curatedPaper = $repo->findById((int)$latest[0]['id']);
assert_true((int)$curatedPaper['curation_locked'] === 1 && $curatedPaper['topic_tags'] === 'curated', 'curated tags should survive import updates');
$hiddenResult = $repo->search(['q' => (string)$latest[0]['doi'], 'substances' => ['psilocybin'], 'per_page' => 10]);
assert_true($hiddenResult['total'] === 0, 'curation hidden/false positive filter failed');

$repo->upsert([
    'title' => 'Psilocybin treatment extends cellular lifespan and improves survival of aged mice',
    'authors' => 'Kosuke Kato, Louise Hecker',
    'abstract' => 'Psilocybin and psilocin treatment extend cellular lifespan in a high-confidence OpenAlex row.',
    'journal' => 'npj Aging',
    'publication_date' => '2025-07-08',
    'doi' => '10.1038/s41514-025-00244-x',
    'pubmed_id' => '40628762',
    'source_url' => 'https://doi.org/10.1038/s41514-025-00244-x',
    'keywords' => 'psilocybin, psilocin, aging',
    'source_name' => 'OpenAlex',
    'raw' => [
        'openalex_id' => 'https://openalex.org/W7777777777',
        'authorships' => [
            ['display_name' => 'Kosuke Kato'],
            [
                'display_name' => 'Louise Hecker',
                'id' => 'https://openalex.org/A5090676224',
                'orcid' => 'https://orcid.org/0000-0002-5025-5437',
            ],
        ],
    ],
]);
$katoFixture = $repo->search(['q' => '10.1038/s41514-025-00244-x', 'include_hidden' => true, 'per_page' => 10]);
assert_true($katoFixture['total'] === 1, 'high-confidence OpenAlex fixture insert failed');
$repo->curate((int)$katoFixture['rows'][0]['id'], [
    'hidden' => 1,
    'false_positive' => 1,
    'curation_notes' => 'OpenAlex duplicate DOI quarantined after provenance repair.',
]);
$repo->restoreOverQuarantinedOpenAlexRows();
$restoredKatoFixture = $repo->search(['q' => 'Kato Hecker npj Aging', 'substances' => ['psilocybin'], 'per_page' => 10]);
assert_true($restoredKatoFixture['total'] === 1 && $restoredKatoFixture['rows'][0]['doi'] === '10.1038/s41514-025-00244-x', 'over-quarantined high-confidence OpenAlex rows should be restored');
$repo->upsert([
    'title' => 'An international mega-analysis of psychedelic drug effects on brain circuit function',
    'authors' => 'Manesh Girn, Manoj K. Doss',
    'abstract' => 'This mega-analysis integrates resting-state fMRI datasets across classic psychedelics including psilocybin.',
    'journal' => 'Nature Medicine',
    'publication_date' => '2026-04-06',
    'doi' => '10.1038/s41591-026-04287-9',
    'pubmed_id' => '41942645',
    'source_url' => 'https://doi.org/10.1038/s41591-026-04287-9',
    'keywords' => 'psychedelic neuroimaging',
    'source_name' => 'OpenAlex',
    'raw' => ['openalex_id' => 'https://openalex.org/W7150978140'],
]);
$megaFixture = $repo->search(['q' => '10.1038/s41591-026-04287-9', 'include_hidden' => true, 'per_page' => 10]);
assert_true($megaFixture['total'] === 1, 'broader psychedelic OpenAlex fixture with abstract psilocybin evidence should insert');
$repo->curate((int)$megaFixture['rows'][0]['id'], [
    'hidden' => 1,
    'false_positive' => 1,
    'curation_notes' => 'OpenAlex duplicate DOI quarantined after provenance repair.',
]);
$repo->restoreOverQuarantinedOpenAlexRows();
$restoredMegaFixture = $repo->search(['q' => 'mega-analysis', 'substances' => ['psilocybin'], 'per_page' => 10]);
assert_true($restoredMegaFixture['total'] === 1 && $restoredMegaFixture['rows'][0]['doi'] === '10.1038/s41591-026-04287-9', 'quarantine repair should restore broader records with explicit psilocybin evidence outside the title');
$heckerInitialResults = $repo->search(['author' => 'Hecker L', 'substances' => ['psilocybin'], 'range' => 'all', 'per_page' => 20]);
$heckerInitialTitles = array_column($heckerInitialResults['rows'], 'title');
assert_true(in_array('Psilocybin treatment extends cellular lifespan and improves survival of aged mice', $heckerInitialTitles, true), 'surname-initial author search should match full first-name author metadata');
$heckerProfileWithFullName = $repo->authorProfile('Hecker L');
assert_true($heckerProfileWithFullName !== null && $heckerProfileWithFullName['orcid'] === '0000-0002-5025-5437' && $heckerProfileWithFullName['openalex_id'] === 'https://openalex.org/A5090676224', 'surname-initial author profile should read identifiers from matching full-name metadata');

$repo->upsert([
    'title' => 'Psilocybin duplicate visible source record',
    'authors' => 'Visible Source',
    'abstract' => 'A visible psilocybin record.',
    'journal' => 'Visible Journal',
    'publication_date' => '2025-01-01',
    'doi' => '10.1000/visible-duplicate-openalex',
    'keywords' => 'psilocybin',
    'source_name' => 'Europe PMC',
]);
$db->pdo()->prepare('INSERT INTO publications
    (title, normalized_title, authors, abstract, journal, publication_date, publication_year, doi, pubmed_id, openalex_id, source_url, keywords, substance_tags, topic_tags, study_type, hidden, false_positive, curation_notes, curation_locked, source_name, publication_status, date_added, last_checked, raw_json)
    VALUES
    (:title, :normalized_title, :authors, :abstract, :journal, :publication_date, :publication_year, :doi, :pubmed_id, :openalex_id, :source_url, :keywords, :substance_tags, :topic_tags, :study_type, :hidden, :false_positive, :curation_notes, :curation_locked, :source_name, :publication_status, :date_added, :last_checked, :raw_json)')->execute([
    'title' => 'Psilocybin duplicate visible source record',
    'normalized_title' => normalize_title('Psilocybin duplicate visible source record'),
    'authors' => 'OpenAlex Source',
    'abstract' => 'A hidden duplicate psilocybin record.',
    'journal' => 'Visible Journal',
    'publication_date' => '2025-01-02',
    'publication_year' => 2025,
    'doi' => null,
    'pubmed_id' => null,
    'openalex_id' => 'https://openalex.org/W7777777778',
    'source_url' => null,
    'keywords' => 'psilocybin',
    'substance_tags' => 'psilocybin',
    'topic_tags' => 'Clinical',
    'study_type' => 'Other',
    'hidden' => 1,
    'false_positive' => 1,
    'curation_notes' => 'OpenAlex duplicate DOI quarantined after provenance repair.',
    'curation_locked' => 1,
    'source_name' => 'OpenAlex',
    'publication_status' => 'published',
    'date_added' => current_utc(),
    'last_checked' => current_utc(),
    'raw_json' => json_encode(['openalex_id' => 'https://openalex.org/W7777777778'], JSON_UNESCAPED_SLASHES),
]);
$repo->restoreOverQuarantinedOpenAlexRows();
$stillHiddenDuplicate = $repo->search(['q' => 'W7777777778', 'substances' => ['psilocybin'], 'per_page' => 10]);
assert_true($stillHiddenDuplicate['total'] === 0, 'OpenAlex quarantine repair should not restore rows with visible duplicates');

$runs = new FetchRunRepository($db);
$runId = $runs->start('TestSource');
$runs->finish($runId, 'ok', 2, 3, 4, 0, 'done');
$successful = $runs->latestSuccessful();
assert_true((int)$successful['imported_count'] === 2 && (int)$successful['skipped_count'] === 4, 'last updated run counts failed');
assert_true(!empty($successful['finished_at']), 'last updated timestamp missing');
assert_true(str_ends_with(format_utc_display($successful['finished_at']), 'UTC'), 'last updated timestamp should render as UTC');

$daily = UpdateOptions::parse(['bin/update.php', '--daily']);
$backfill = UpdateOptions::parse(['bin/update.php', '--backfill']);
$sourceRun = UpdateOptions::parse(['bin/update.php', '--from=2019-01-01', '--to=2020-12-31', '--source=OpenAlex']);
assert_true($daily['mode'] === 'daily' && $daily['from'] !== null && $daily['to'] !== null, 'daily mode should use a recent date window');
assert_true($backfill['mode'] === 'backfill' && $backfill['from'] === null && $backfill['to'] === null, 'backfill should be historical and uncapped by date');
assert_true($sourceRun['mode'] === 'custom' && $sourceRun['sources'] === ['OpenAlex'], 'source-filtered update option parsing failed');

$europePmcPaper = EuropePmcFetcher::paperFromResult([
    'id' => 'PPR123',
    'source' => 'PPR',
    'doi' => '10.1101/epmc-test',
    'title' => 'Psilocybin preprint from Europe PMC',
    'abstractText' => 'A psilocybin preprint indexed by Europe PMC.',
    'authorString' => 'Example A.',
    'journalTitle' => 'Europe PMC Preprints',
    'firstPublicationDate' => '2026-01-02',
    'pubType' => 'preprint',
]);
assert_true($europePmcPaper !== null && $europePmcPaper['source_name'] === 'Europe PMC' && $europePmcPaper['publication_status'] === 'preprint', 'Europe PMC fetcher mapping failed');
$europePmcBioRxivPaper = EuropePmcFetcher::paperFromResult([
    'id' => 'PPR124',
    'source' => 'PPR',
    'doi' => '10.1101/epmc-biorxiv-test',
    'title' => 'Psilocybin bioRxiv preprint from Europe PMC',
    'abstractText' => 'A psilocybin preprint indexed by Europe PMC.',
    'authorString' => 'Example B.',
    'firstPublicationDate' => '2026-01-02',
    'pubType' => 'preprint',
    'bookOrReportDetails' => ['publisher' => 'bioRxiv'],
]);
assert_true($europePmcBioRxivPaper !== null && $europePmcBioRxivPaper['source_name'] === 'bioRxiv' && ($europePmcBioRxivPaper['raw']['importer'] ?? '') === 'Europe PMC', 'Europe PMC preprint source remapping failed');

$medrxivPaper = (new BioMedRxivFetcher(new HttpClient(), 'medrxiv'))->paperFromItem([
    'title' => 'Psilocybin clinical preprint',
    'abstract' => 'A medRxiv psilocybin study before peer review.',
    'authors' => 'Example A.; Example B.',
    'doi' => '10.1101/2026.01.01.123456',
    'date' => '2026-01-01',
    'category' => 'psychiatry',
    'version' => '1',
]);
assert_true($medrxivPaper !== null && $medrxivPaper['source_name'] === 'medRxiv' && $medrxivPaper['publication_status'] === 'preprint', 'medRxiv fetcher mapping failed');

$biorxivPaper = (new BioMedRxivFetcher(new HttpClient(), 'biorxiv'))->paperFromItem([
    'title' => 'Psilocin neuroscience preprint',
    'abstract' => 'A bioRxiv psilocin neuroscience study before peer review.',
    'authors' => 'Example C.',
    'doi' => '10.1101/2026.01.02.123456',
    'date' => '2026-01-02',
    'category' => 'neuroscience',
    'version' => '1',
]);
assert_true($biorxivPaper !== null && $biorxivPaper['source_name'] === 'bioRxiv' && $biorxivPaper['publication_status'] === 'preprint', 'bioRxiv fetcher mapping failed');

$psyarxivPaper = PsyArXivFetcher::paperFromPreprint([
    'id' => 'abcde_v1',
    'attributes' => [
        'title' => 'Psilocybin psychotherapy preprint',
        'description' => 'A PsyArXiv psilocybin psychotherapy manuscript.',
        'doi' => null,
        'date_published' => '2026-01-03T12:00:00',
        'tags' => ['psilocybin', 'psychotherapy'],
        'subjects' => [[['text' => 'Clinical Psychology']]],
        'version' => 1,
    ],
]);
assert_true($psyarxivPaper !== null && $psyarxivPaper['source_name'] === 'PsyArXiv' && $psyarxivPaper['publication_status'] === 'preprint', 'PsyArXiv fetcher mapping failed');

$clinicalTrialPaper = ClinicalTrialsFetcher::paperFromStudy([
    'protocolSection' => [
        'identificationModule' => ['nctId' => 'NCT00000001', 'briefTitle' => 'Psilocybin therapy trial'],
        'statusModule' => [
            'overallStatus' => 'RECRUITING',
            'lastUpdatePostDateStruct' => ['date' => '2026-01-04'],
        ],
        'descriptionModule' => ['briefSummary' => 'A trial of psilocybin therapy.'],
        'conditionsModule' => ['conditions' => ['Depression']],
        'armsInterventionsModule' => ['interventions' => [['name' => 'Psilocybin']]],
        'sponsorCollaboratorsModule' => ['leadSponsor' => ['name' => 'Example University']],
    ],
]);
assert_true($clinicalTrialPaper !== null && $clinicalTrialPaper['source_name'] === 'ClinicalTrials.gov' && $clinicalTrialPaper['publication_status'] === 'clinical trial', 'ClinicalTrials.gov fetcher mapping failed');

$openAlexPaper = OpenAlexFetcher::paperFromWork([
    'id' => 'https://openalex.org/W123',
    'display_name' => 'The Psilocybin-Telomere Hypothesis',
    'abstract_inverted_index' => [
        'psilocybin' => [0],
        'telomere' => [1],
        'hypothesis' => [2],
    ],
    'doi' => 'https://doi.org/10.1016/j.mehy.2019.109406',
    'ids' => ['pmid' => 'https://pubmed.ncbi.nlm.nih.gov/31634774'],
    'publication_date' => '2019-09-24',
    'type' => 'article',
    'cited_by_count' => 18,
    'primary_location' => [
        'landing_page_url' => 'https://doi.org/10.1016/j.mehy.2019.109406',
        'source' => ['display_name' => 'Medical Hypotheses'],
    ],
    'authorships' => [[
        'author' => [
            'id' => 'https://openalex.org/A5072900702',
            'display_name' => 'Christopher B. Germann',
            'orcid' => 'https://orcid.org/0000-0002-1573-4651',
        ],
    ]],
    'concepts' => [['display_name' => 'Psilocybin'], ['display_name' => 'Telomeres']],
]);
assert_true($openAlexPaper !== null && $openAlexPaper['source_name'] === 'OpenAlex' && $openAlexPaper['authors'] === 'Christopher B. Germann' && $openAlexPaper['pubmed_id'] === '31634774' && ($openAlexPaper['raw']['cited_by_count'] ?? 0) === 18, 'OpenAlex fetcher mapping failed');

$index = file_get_contents(__DIR__ . '/../index.php');
$js = file_get_contents(__DIR__ . '/../assets/app.js');
$sw = file_get_contents(__DIR__ . '/../sw.js');
$css = file_get_contents(__DIR__ . '/../assets/styles.css');
$manifestJson = file_get_contents(__DIR__ . '/../manifest.webmanifest');
$htaccess = file_get_contents(__DIR__ . '/../.htaccess');
$dataHtaccess = file_get_contents(__DIR__ . '/../data/.htaccess');
$backupSqlite = file_get_contents(__DIR__ . '/../bin/backup-sqlite.php');
$alertPhp = file_get_contents(__DIR__ . '/../alert.php');
$aboutPhp = file_get_contents(__DIR__ . '/../about.php');
$dataProtectionPhp = file_get_contents(__DIR__ . '/../data-protection.php');
$citationNetworkPhp = file_get_contents(__DIR__ . '/../citation-network.php');
$alertServicePhp = file_get_contents(__DIR__ . '/../src/AlertService.php');
$healthPhp = file_get_contents(__DIR__ . '/../health.php');
$config = file_get_contents(__DIR__ . '/../src/Config.php');
$publicationService = file_get_contents(__DIR__ . '/../src/PublicationService.php');
$helpers = file_get_contents(__DIR__ . '/../src/helpers.php');
$viewHelpers = file_get_contents(__DIR__ . '/../src/ViewHelpers.php');
preg_match('/const CACHE_VERSION = "([^"]+)";/', $sw, $cacheVersionMatch);
preg_match('/const ASSET_VERSION = "([^"]+)";/', $sw, $assetVersionMatch);
$cacheVersion = $cacheVersionMatch[1] ?? '';
$assetVersion = $assetVersionMatch[1] ?? '';
assert_true(str_contains($index, 'app-preloader') && str_contains($index, 'id="scroll-progress"') && str_contains($index, 'id="scroll-top"') && str_contains($index, 'preloader-title">Loading') && !str_contains($index, 'Loading the local research index, filters, analytics, and alert tools.') && str_contains($css, '.preloader-meter'), 'preloader or scroll controls should be present and styled');
assert_true(str_contains($index, 'Search all years') && str_contains($index, "'range' => 'all'") && str_contains($index, "'from' => null") && str_contains($index, "'to' => null"), 'zero-result all-years affordance missing');
assert_true(str_contains($helpers, "ViewHelpers.php") && str_contains($viewHelpers, 'function query_with') && str_contains($viewHelpers, 'function source_filter_url') && !str_contains($index, 'function query_with(') && !str_contains($index, 'function source_filter_url('), 'view helper split should keep rendering helpers out of index.php');
assert_true(str_contains($index, 'paper-results-title') && str_contains($index, 'Latest publications') && str_contains($index, 'Search results') && str_contains($index, 'publication-timeline'), 'single publication result surface or timeline missing');
assert_true(str_contains($index, 'result-title-block') && str_contains($index, 'results-toolbar') && str_contains($index, 'Updated <strong id="last-updated-text"') && str_contains($css, '.result-title-block') && str_contains($css, '.result-display-controls label > span') && !str_contains($index, 'latest-panel-toggle') && !str_contains($index, 'latest-next') && !str_contains($index, 'latest-list'), 'homepage should use one canonical results list without a duplicate latest panel');
assert_true(str_contains($index, 'public_refresh') && str_contains($index, 'public_refresh.lock') && !str_contains($index, 'Refresh recent papers') && !str_contains($index, 'hero-refresh-form') && !str_contains($index, 'public-refresh-form'), 'public refresh backend should remain but visible refresh buttons should be removed');
assert_true(str_contains($index, '$_POST[\'admin_token\'] ?? \'\'') && !str_contains($index, '$_GET[\'admin_token\']'), 'admin token should be accepted from POST only');
assert_true(str_contains($index, 'result-display-controls') && str_contains($index, 'name="per_page"') && str_contains($index, '[10, 20, 50, 100, 200]') && str_contains($index, 'value="all"') && str_contains($index, 'All results') && str_contains($index, 'Sort matching results') && str_contains($index, 'Copy BibTeX'), 'results page-size, sorting, all-results option, or BibTeX copy UI missing');
assert_true(str_contains($index, 'Evidence status') && str_contains($viewHelpers, 'PREPRINT (not peer reviewed)') && str_contains($index, 'Source database'), 'source/status filter UI missing');
assert_true(str_contains($index, 'database.php') && str_contains($index, 'SQLite database') && str_contains($index, 'application/vnd.sqlite3'), 'full SQLite database download UI or dataset metadata missing');
assert_true(str_contains($index, 'hero-evidence') && str_contains($index, 'aria-label="Database summary" open') && str_contains($css, '.hero-evidence-grid') && str_contains($css, '.hero-evidence-summary-metrics') && str_contains($css, 'grid-template-columns: repeat(3, minmax(0, 1fr))') && str_contains($index, 'Database summary') && str_contains($index, '<span>Database</span>') && !str_contains($index, 'Database evidence') && str_contains($index, 'database-summary-metric') && !str_contains($index, 'Total Publications') && str_contains($index, 'Psilocybin Records') && str_contains($index, 'Psilocin Records') && !str_contains($index, 'Search Matches') && str_contains($index, 'Journals') && str_contains($index, 'Research Topics') && str_contains($index, 'Source coverage') && str_contains($index, 'source-coverage-table evidence-accordion') && str_contains($index, '<table>') && str_contains($index, '<th scope="col">Count</th>') && str_contains($viewHelpers, 'function source_filter_url') && str_contains($index, 'class="source-filter-link"') && str_contains($index, 'publication-growth-mini evidence-accordion') && str_contains($index, 'publication-growth-data') && str_contains($index, 'since <?= h((string)($publicationGrowthFirstYear ?? 2020)) ?>') && str_contains($index, 'Cumulative line'), 'academic open database summary, source coverage accordion, source links, or growth timeline missing');
assert_true(!str_contains($index, 'class="metric-strip"') && !str_contains($index, 'Publication database metrics'), 'database metrics should not be duplicated outside the collapsed Database accordion');
assert_true(str_contains($index, 'class="brand-icon brand-icon-mushroom"') && str_contains($index, 'assets/mushroom-brand-mark.webp') && !str_contains($index, 'brand-lockup-preloader" aria-label="Psilocybin-Research.com">' . "\n" . '      <img'), 'header should use the cropped mushroom brand mark, but preloader should not render a small logo image');
assert_true(str_contains($index, 'hero-filter-shortcut') && str_contains($index, 'data-open-advanced') && str_contains($index, 'id="publication-results"') && str_contains($index, 'Latest publications') && str_contains($index, 'Full SQLite index sorted by newest first') && !str_contains($index, 'data-entry-action="search"') && !str_contains($index, 'data-entry-action="alert"'), 'hero settings button should directly open advanced filters without duplicate search/alert shortcuts');
assert_true(str_contains($index, 'class="hero-action-panel"') && str_contains($css, '.hero-action-panel') && str_contains($css, 'preloader-mushroom-desktop.webp') && str_contains($css, 'min-height: 54px') && str_contains($css, 'backdrop-filter: blur(18px)') && str_contains($css, 'background: rgba(255, 255, 255, .34)') && str_contains($css, '.hero-search input:focus') && str_contains($css, 'background: rgba(255, 255, 255, .54)'), 'central translucent glass search/status mushroom photo treatment missing');
assert_true(str_contains($index, 'href="#alerts" data-open-alerts'), 'sidebar alert link should open alert enrollment sheet');
assert_true(str_contains($index, 'href="about.php"') && str_contains($alertPhp, 'href="about.php"') && str_contains($aboutPhp, 'About the Psilocybin Research Publication Tracker') && str_contains($aboutPhp, 'What It Does') && str_contains($aboutPhp, 'Privacy') && str_contains($aboutPhp, 'Encryption') && str_contains($aboutPhp, 'Non-Sensitive Security Stats') && str_contains($aboutPhp, 'PHP Architecture') && str_contains($aboutPhp, 'AJAX search flow') && str_contains($aboutPhp, 'asynchronous JavaScript requests') && str_contains($aboutPhp, 'Progressive enhancement') && str_contains($aboutPhp, 'Source Context And Automated Updates') && str_contains($aboutPhp, 'Daily cron update') && str_contains($aboutPhp, '03:20 server time') && str_contains($aboutPhp, 'Data Compression And Speed') && str_contains($aboutPhp, 'Deflate and Brotli') && str_contains($aboutPhp, 'Minified static assets') && str_contains($aboutPhp, 'SQLite read performance') && str_contains($aboutPhp, 'PWA caching strategy') && !str_contains($aboutPhp, 'Storage protection') && !str_contains($aboutPhp, 'Backup freshness') && !str_contains($aboutPhp, 'Alert cipher/index fields') && !str_contains($aboutPhp, 'Push cipher/index fields') && str_contains($aboutPhp, 'double opt-in') && str_contains($aboutPhp, 'encrypted at rest') && str_contains($aboutPhp, 'no tracking pixel') && str_contains($aboutPhp, 'full SQLite database') && str_contains($aboutPhp, 'og:image:width') && str_contains($aboutPhp, 'application/ld+json'), 'about page, navigation, privacy, encryption, architecture, source context, compression, stats, or SEO metadata missing');
assert_true(str_contains($index, 'href="data-protection.php"') && str_contains($aboutPhp, 'href="data-protection.php"') && str_contains($alertPhp, 'href="data-protection.php"') && str_contains($alertServicePhp, "Config::publicBaseUrl() . 'data-protection.php'") && str_contains($dataProtectionPhp, 'Data Protection Notice') && str_contains($dataProtectionPhp, 'What data is processed, where it goes, and why') && str_contains($dataProtectionPhp, 'Core App Use') && str_contains($dataProtectionPhp, 'Search, API, Export, And Widget Data') && str_contains($dataProtectionPhp, 'Email Alert Encryption Model') && str_contains($dataProtectionPhp, 'search terms') && str_contains($dataProtectionPhp, 'research topics') && str_contains($dataProtectionPhp, 'email alert configuration') && str_contains($dataProtectionPhp, 'Digest emails show the current research filters') && str_contains($dataProtectionPhp, 'Email configuration') && str_contains($dataProtectionPhp, 'protection-flow') && str_contains($dataProtectionPhp, 'Publication Data Sources And Source Context') && str_contains($dataProtectionPhp, 'Automated Updates') && str_contains($dataProtectionPhp, '03:20 server time') && str_contains($dataProtectionPhp, 'Web Push And PWA Data') && str_contains($dataProtectionPhp, 'Cookies And Local Browser Storage') && str_contains($dataProtectionPhp, 'User Rights') && str_contains($css, '.protection-flow') && str_contains($css, '.protection-step-secure') && str_contains($css, '.protection-arrow'), 'dedicated data protection page, chart, navigation, alert filter configuration, current email filter display, or alert links missing');
assert_true(str_contains($index, 'class="page-tools"') && str_contains($index, 'id="sidebar-fullscreen-toggle"') && str_contains($index, 'id="sidebar-print-results"') && str_contains($index, 'id="sidebar-share-results"') && str_contains($index, 'Share results') && str_contains($index, 'Print results'), 'page tool fullscreen, print, or share controls missing');
assert_true(str_contains($index, 'Application created by Dr. Christopher B. Germann') && str_contains($index, '$appVersion = \'2.1.2\'') && str_contains($index, 'Version <?= h($appVersion) ?>') && str_contains($index, 'gmdate(\'Y\')') && str_contains($index, 'data-client-environment'), 'footer creator/version/year/client environment metadata missing');
assert_true(str_contains($index, '<span>Auto-updated</span>') && str_contains($index, '<em>Indexed records</em>') && str_contains($index, 'data-count-up') && str_contains($index, 'Updated <?= h(format_utc_display($lastSuccessfulUpdate)) ?>') && str_contains($js, 'initCountUpStats') && str_contains($js, 'prefers-reduced-motion: reduce') && str_contains($css, 'font-variant-numeric: tabular-nums'), 'sidebar status should show animated auto-updated indexed records and timestamp');
assert_true(str_contains($index, 'footer-db-meta') && str_contains($index, 'SQLite size') && str_contains($index, 'Last DOI article added') && str_contains($index, 'DB query') && !str_contains($index, 'Footer DB query') && str_contains($index, 'formatBytes') && str_contains($index, 'microtime(true)'), 'footer database metadata missing');
assert_true(str_contains($index, 'og:image:width') && str_contains($index, 'og:image:height') && str_contains($index, 'og:image:alt') && str_contains($index, 'twitter:image:alt') && str_contains($index, '$shareImageUrl'), 'Open Graph or Twitter share image metadata missing');
assert_true(!str_contains($index, 'Scite context') && !str_contains($index, 'scite_context_url') && !str_contains($index, 'SciteService'), 'Scite context UI should be removed');
assert_true(!str_contains($index, 'rss.php') && !str_contains($index, 'application/rss+xml') && !str_contains($index, 'RSS Feed') && !str_contains($index, '<span>RSS</span>'), 'RSS feed UI and metadata should be removed');
assert_true(str_contains($index, 'data-open-analytics') && str_contains($index, 'id="analytics-modal"') && str_contains($index, 'class="analytics-scrollbody"') && str_contains($index, 'data-close-analytics'), 'analytics should open in a scrollable modal sheet');
assert_true(str_contains($index, 'Deep research lens') && str_contains($index, 'analytics-lens-cta') && str_contains($css, '.analytics-lens-summary-main') && str_contains($css, '.analytics-query-insights') && str_contains($css, '.analytics-paper-detail') && str_contains($js, 'analyticsMatchReasons') && str_contains($js, 'analyticsFacetSummary') && str_contains($js, 'Inspect record'), 'salient analytics lens trigger or paper inspection UI missing');
assert_true(str_contains($css, '#tracker-heading') && str_contains($css, 'font-weight: 300'), 'main tracker heading should use a thin font weight');
assert_true(str_contains($css, '.filters.is-collapsed.is-modal-open .filters-body') && str_contains($js, 'prepareDialogHost') && str_contains($js, 'restoreFiltersBodyHidden'), 'advanced filter modal host should remain visible while collapsed filters are open');
assert_true(str_contains($js, 'window.requestAnimationFrame(apply)') && str_contains($js, 'document.querySelector("dialog[open]")') && str_contains($js, 'classList.toggle("modal-open", hasOpenDialog)'), 'modal blur state should be recalculated after dialog close state settles');
assert_true(str_contains($css, 'grid-template-rows: auto minmax(0, 1fr)') && str_contains($css, '.analytics-modal') && str_contains($css, 'height: min(920px, calc(100dvh - 24px))') && str_contains($css, '.analytics-sheet') && str_contains($css, 'height: 100%'), 'analytics modal should own viewport height and let the scroll body reach the bottom');
assert_true(str_contains($css, '.publication-growth-chart .chart-line') && str_contains($css, 'fill: none') && str_contains($css, '.publication-growth-chart .chart-bar') && str_contains($css, '.timeline-svg .chart-line'), 'publication growth and analytics timeline charts should share SVG chart styling');
assert_true(str_contains($index, 'publication_recency_badge($paper') && str_contains($css, '.tags .recency-week') && str_contains($css, '.tags .recency-month') && str_contains($js, 'syncRecencyBadges') && str_contains($js, 'recencyForPublicationDate') && str_contains($js, 'New this week') && str_contains($js, 'New this month'), 'relative publication recency badges missing');
assert_true(!str_contains($index, 'assets/vendor/three.r134.min.js') && !str_contains($index, 'assets/vendor/vanta.net.min.js') && !str_contains($index, 'id="footer-vanta-net"') && !str_contains($index, 'id="hero-vanta-net"'), 'network/Three scripts and mounts should be removed');
assert_true(str_contains($index, 'id="nav-sidebar-toggle"') && str_contains($index, 'id="primary-sidebar-content"') && str_contains($index, 'id="matching-results-body"') && str_contains($index, 'class="results-bottom-pager"') && str_contains($index, 'aria-label="Results pagination"') && !str_contains($index, 'id="result-panel-toggle"'), 'sidebar, canonical results body, or bottom pagination controls missing');
assert_true(!str_contains($index, 'id="transport-audio"') && !str_contains($index, 'transport.mp3'), 'transport audio player should be removed from the academic design');
foreach (['hero-media', 'hero-visual', 'hero-net', 'panel-visual', 'audio-visualizer', 'transport-audio', 'audio-player', 'visual-card', 'visual-brief', 'hero-fullscreen', 'footer-media', 'footer-image-band'] as $removedSelector) {
    assert_true(!str_contains($css, $removedSelector) && !str_contains($js, $removedSelector) && !str_contains($index, $removedSelector), 'stale visual/audio selector should be removed: ' . $removedSelector);
}
assert_true(str_contains($index, 'manifest.webmanifest') && str_contains($index, 'id="install-app"') && str_contains($index, 'apple-mobile-web-app-capable') && str_contains($manifestJson, '"theme_color": "#123c31"') && str_contains($manifestJson, '"background_color": "#ffffff"'), 'PWA metadata, brand colors, or install UI missing');
assert_true(($mushroomBrandInfo = getimagesize(__DIR__ . '/../assets/mushroom-brand-mark.webp')) && ($mushroomBrandInfo[0] ?? 0) === 512 && ($mushroomBrandInfo[1] ?? 0) === 512, 'mushroom brand WebP source should remain the icon source of truth');
assert_true(($logoInfo = getimagesize(__DIR__ . '/../assets/logo.png')) && ($logoInfo[0] ?? 0) === 512 && ($logoInfo[1] ?? 0) === 512 && ($logoInfo['mime'] ?? '') === 'image/png', 'favicon should use mushroom-brand-mark-derived 512px PNG brand mark');
assert_true(is_file(__DIR__ . '/../favicon.ico') && str_contains($sw, './favicon.ico'), 'root favicon.ico should be generated from the mushroom brand mark and cached by the service worker');
foreach ([['assets/pwa/icon-192.png', 192], ['assets/pwa/icon-512.png', 512], ['assets/pwa/maskable-512.png', 512], ['assets/pwa/apple-touch-icon.png', 180]] as [$iconPath, $iconSize]) {
    $iconInfo = getimagesize(__DIR__ . '/../' . $iconPath);
    assert_true(($iconInfo[0] ?? 0) === $iconSize && ($iconInfo[1] ?? 0) === $iconSize && ($iconInfo['mime'] ?? '') === 'image/png', 'PWA icon should use generated square PNG brand mark: ' . $iconPath);
}
assert_true(str_contains($index, 'assets/styles.min.css?v=') && str_contains($index, 'assets/app.min.js?v='), 'versioned minified CSS/JS asset URLs missing');
assert_true(!str_contains($css, 'mushrooms-4560675.jpg') && str_contains($css, 'preloader-mushroom-desktop.webp') && str_contains($css, 'preloader-mushroom-mobile.webp'), 'main app background image should be removed while preloader keeps responsive backgrounds');
assert_true(!str_contains($index, 'footer-image-band') && !str_contains($css, 'footer-image-band') && !str_contains($css, 'footer-mushroom-bg.webp') && !str_contains($index, '<footer class="footer">' . "\n" . '  <img'), 'footer image surfaces should not render');
assert_true(str_contains($index, "Cache-Control: no-store") && str_contains($js, 'updateViaCache: "none"') && !str_contains($sw, '"./",'), 'app shell cache-busting safeguards missing');
assert_true(str_contains($htaccess, 'max-age=31536000, immutable') && str_contains($htaccess, 'AddOutputFilterByType DEFLATE') && str_contains($htaccess, 'BROTLI_COMPRESS'), 'static cache or compression headers missing');
assert_true(str_contains($htaccess, 'RewriteRule ^data/ - [F,L]') && str_contains($htaccess, 'RewriteRule ^src/ - [F,L]') && str_contains($htaccess, 'RewriteRule ^bin/ - [F,L]') && str_contains($htaccess, 'sqlite') && str_contains($htaccess, 'Require all denied'), 'runtime/source web-deny rules missing');
assert_true(str_contains($dataHtaccess, 'Options -Indexes') && str_contains($dataHtaccess, 'Require all denied') && str_contains($dataHtaccess, 'Deny from all') && str_contains($dataHtaccess, '<FilesMatch ".*">'), 'data directory deny-all .htaccess missing');
assert_true(str_contains($config, 'function backupDir') && str_contains($backupSqlite, 'VACUUM main INTO') && str_contains($backupSqlite, 'SQLite3') && str_contains($backupSqlite, 'backup($targetDb)') && str_contains($backupSqlite, 'chmod($target, 0640)') && str_contains($backupSqlite, 'backup.completed') && str_contains($backupSqlite, '--keep='), 'SQLite backup command or backup config missing');
assert_true(str_contains($databasePhp = file_get_contents(__DIR__ . '/../src/Database.php'), 'idx_publications_visible_date') && str_contains($databasePhp, 'publicationFtsNeedsRebuild') && !str_contains($databasePhp, 'publications_fts(publications_fts) VALUES (\'rebuild\');' . "\n        } catch"), 'SQLite visible-record indexes or guarded FTS rebuild missing');
$publicationRepository = file_get_contents(__DIR__ . '/../src/PublicationRepository.php');
preg_match('/public function allForExport\(.*?^\s*}\n\s*public function topics/ms', (string)$publicationRepository, $allForExportMatch);
$allForExportBody = $allForExportMatch[0] ?? '';
assert_true($allForExportBody !== '' && str_contains($allForExportBody, 'LIMIT :limit') && !str_contains($allForExportBody, '$this->search('), 'large export queries should avoid repeated counted search pagination');
assert_true(is_file(__DIR__ . '/../bin/build-assets.sh') && is_file(__DIR__ . '/../assets/styles.min.css') && is_file(__DIR__ . '/../assets/app.min.js'), 'minified asset build outputs missing');
assert_true((bool)preg_match('/^publication-tracker-pwa-v\d+-20\d{6}-[a-z0-9-]+$/', $cacheVersion) && (bool)preg_match('/^20\d{6}-[a-z0-9-]+-v\d+$/', $assetVersion) && str_contains($sw, 'ASSET_VERSION') && str_contains($sw, 'styles.min.css?v=${ASSET_VERSION}') && str_contains($sw, 'app.min.js?v=${ASSET_VERSION}') && !str_contains($sw, 'mushrooms-4560675.jpg') && str_contains($sw, 'mushroom-brand-mark.webp') && !str_contains($sw, 'footer-mushroom-bg.webp') && str_contains($sw, 'preloader-mushroom-desktop.webp') && str_contains($sw, 'preloader-mushroom-mobile.webp'), 'PWA cache version or versioned static assets missing');
assert_true(str_contains($js, 'fetch("status.php"'), 'status endpoint fetch missing');
assert_true(str_contains($js, 'initAjaxSearch') && str_contains($js, 'DOMParser') && str_contains($index, 'rank-filter-link'), 'AJAX search or clickable analytics filters missing');
assert_true(str_contains($js, 'renderTimeline') && str_contains($js, '<svg class="timeline-svg"') && str_contains($js, 'Interactive publication timeline chart') && str_contains($js, 'chart-plot-bg') && str_contains($js, 'vector-effect="non-scaling-stroke"') && str_contains($js, 'Matching publications') && str_contains($js, 'timelinePapersForRange') && str_contains($js, 'setTimelineInsight') && str_contains($js, 'pointerover') && str_contains($js, 'Open matching publications'), 'polished interactive timeline JavaScript missing');
assert_true(str_contains($js, 'initFullscreenToggle') && str_contains($js, 'initPrintResults') && str_contains($js, 'initNativeShare') && str_contains($js, 'navigator.share') && str_contains($js, 'currentResultsShareUrl') && str_contains($js, 'paperDataForPrint') && str_contains($js, 'confirmPrintResults') && str_contains($js, 'syncSidebarResultActions'), 'sidebar print/fullscreen/share JavaScript missing');
assert_true(str_contains($js, 'initClientEnvironment') && str_contains($js, 'navigator.userAgentData') && str_contains($js, 'browserFromUserAgent') && str_contains($js, 'osFromUserAgent') && str_contains($js, 'No visible results to print'), 'client environment metadata or print disabled-state JavaScript missing');
assert_true(str_contains($js, 'printReferenceText') && str_contains($js, 'printSourcesLabel') && str_contains($js, '<dt>Entries</dt>') && str_contains($js, '<dt>Date range</dt>') && str_contains($js, '<dt>Sources</dt>') && str_contains($js, '<ol') && str_contains($js, 'psilocybin-research.com</a>') && !str_contains($js, 'Generated from') && !str_contains($js, 'Links point to the indexed source records'), 'print output should contain reference-only records with compact summary metadata');
assert_true(str_contains($citationNetworkPhp, 'data-citation-search') && str_contains($citationNetworkPhp, 'data-citation-seed-limit') && str_contains($citationNetworkPhp, 'data-citation-seed-preset') && str_contains($citationNetworkPhp, 'data-citation-copy-selected') && str_contains($citationNetworkPhp, 'data-citation-focus-selected') && str_contains($citationNetworkPhp, 'data-citation-share-view') && str_contains($citationNetworkPhp, 'data-citation-export-json') && str_contains($citationNetworkPhp, 'data-citation-export-subgraph') && str_contains($citationNetworkPhp, 'data-citation-export-csv') && str_contains($citationNetworkPhp, 'data-citation-clusters') && !str_contains($citationNetworkPhp, 'citation-graph-vendor.min.js') && str_contains($citationNetworkPhp, 'id="network"') && str_contains($citationNetworkPhp, 'data-citation-layout-mode') && str_contains($citationNetworkPhp, 'Citation rings') && str_contains($citationNetworkPhp, 'Publication timeline') && str_contains($citationNetworkPhp, 'not causal claims') && str_contains($citationNetworkPhp, 'data-citation-label-mode') && !str_contains($citationNetworkPhp, 'data-citation-node-type') && !str_contains($citationNetworkPhp, 'data-citation-edge-type') && !str_contains($citationNetworkPhp, '<legend>Links</legend>') && str_contains($citationNetworkPhp, 'data-citation-insight') && str_contains($citationNetworkPhp, 'data-citation-relations') && !str_contains($js, 'CitationGraphVendor') && !str_contains($js, 'new graphVendor.Sigma') && str_contains($js, 'host.replaceChildren(svg)') && str_contains($js, 'graphFocusSets') && str_contains($js, 'visibleGraphSnapshot') && str_contains($js, 'downloadCitationGraphFile') && str_contains($js, 'selectedNodeText') && str_contains($js, 'shouldShowNode') && str_contains($js, 'shouldShowEdge') && str_contains($js, 'shouldShowLabel') && str_contains($js, 'layoutTarget') && str_contains($js, 'applyLayoutMode') && str_contains($js, 'Network topology:') && str_contains($js, 'host.dataset.citationCurrentLayout') && str_contains($js, 'selectedNeighborhoodIds') && str_contains($js, 'focusSelectedNode') && str_contains($js, 'updateSavedViewUrl') && str_contains($js, 'relatedListHtml') && str_contains($js, 'citation-node-preview') && str_contains($js, 'Shareable network view URL copied') && str_contains($js, 'Focused citation subgraph JSON exported') && str_contains($js, 'authorLinksHtml') && str_contains($js, 'nodeDetailDescription') && str_contains($js, 'External DOI reference cited by') && str_contains($js, 'relationCardMeta') && str_contains($js, 'citation-detail-authors') && str_contains($js, 'searchParams.set("limit"') && str_contains($js, 'Connected evidence') && str_contains($js, 'citation-arrow') && !str_contains($css, '.citation-sigma-canvas') && str_contains($css, '.citation-network-workbench') && str_contains($css, '.citation-seed-presets') && str_contains($css, '.citation-network-utility-actions') && str_contains($css, '.citation-toggle-control') && str_contains($css, '.citation-node-preview') && str_contains($css, '.citation-node-related') && str_contains($css, '.citation-match-note') && str_contains($css, '.citation-detail-authors') && str_contains($css, '.citation-node-relations small') && str_contains($css, '.citation-edge.is-highlighted') && str_contains($css, '.citation-node.is-match') && str_contains($css, '.citation-node-relations'), 'citation network pure SVG renderer, seed selector, topology/focus/share controls, author/reference details, exports, search, label modes, arrows, or selected-node relationship panel missing');
assert_true(str_contains($js, 'timeline-print-references') && str_contains($js, 'Print references') && str_contains($js, 'print-only') && str_contains($js, 'print-references') && str_contains($js, 'window.print()'), 'timeline blob pages should include reference-only print controls');
assert_true(str_contains($js, 'BibTeX citation copied to clipboard') && str_contains($js, 'showAppToast') && str_contains($js, 'Showing the latest indexed publications.') && !str_contains($js, 'latestLimitSelect'), 'BibTeX copy toast or canonical latest scroll JavaScript missing');
assert_true(str_contains($viewHelpers, 'PREPRINT (not peer reviewed)') && str_contains($index, 'publication_recency_badge($paper') && !str_contains($js, 'renderPublicationBadges'), 'source/status badges should be rendered by the canonical server-side results list');
assert_true(!str_contains($js, 'Scite context') && !str_contains($js, 'scite.ai/search') && !str_contains($js, '.scite-link'), 'latest Scite context link should be removed');
assert_true(!str_contains($js, 'initFooternetworkNet') && !str_contains($js, 'footerNetEffect') && str_contains($js, 'initAlertVanta') && str_contains($js, '#alert-vanta-bg') && str_contains($js, 'data-open-analytics') && str_contains($js, '#analytics-modal'), 'global network initializer should stay removed while alert-page Vanta and analytics modal handlers are present');
assert_true(str_contains($js, 'initNavSidebarCollapse') && str_contains($js, 'publicationTrackerNavSidebarCollapsed') && str_contains($js, 'matchMedia?.("(max-width: 1180px)")') && str_contains($css, 'html.nav-sidebar-collapsed .primary-sidebar-content') && str_contains($css, 'position: fixed') && str_contains($css, 'top: 0') && str_contains($css, 'z-index: 45') && str_contains($css, 'padding-top: 84px') && !str_contains($js, 'initResultPanelCollapse') && !str_contains($js, 'publicationTrackerResultsCollapsed'), 'sidebar collapse or always-visible canonical results behavior missing');
assert_true(str_contains($js, 'initSectionNavigation') && str_contains($js, 'setActiveNavLink') && str_contains($js, 'aria-current') && str_contains($js, 'currentScrollOffset') && str_contains($js, 'initDownloadConfirmations') && str_contains($js, 'X-Publication-Tracker-Export-Count') && str_contains($js, 'Date range:') && str_contains($js, 'Included sources:') && !str_contains($js, 'Content-Length') && !str_contains($js, 'Size: ${size}') && str_contains($js, 'window.confirm'), 'section navigation or download confirmation handlers missing');
assert_true(str_contains($js, 'initApiConfirmations') && str_contains($js, 'Open JSON API response?') && str_contains($js, 'All matching records') && str_contains($js, 'Use Export JSON when you want a downloadable file') && str_contains($index, '<span>Open API</span>') && str_contains($index, "'per_page' => 'all'"), 'API open confirmation or full filtered API link missing');
assert_true(str_contains($helpers, 'function download_request_parts') && str_contains($helpers, 'function download_filename'), 'download filename helpers missing');
assert_true(str_contains($js, 'initEntryChoices') && str_contains($js, '[data-entry-action]') && str_contains($js, '#publication-results') && str_contains($js, 'currentScrollOffset()') && str_contains($js, 'Showing the latest indexed publications') && str_contains($js, 'Search is ready') && str_contains($js, '[data-open-alerts]'), 'entry choice handlers or offset-aware latest scrolling missing');
assert_true(str_contains($js, 'renderPublicationGrowthChart') && str_contains($js, '#publication-growth-data') && str_contains($js, 'publication-growth-bar') && str_contains($js, 'publication-growth-line') && str_contains($js, 'openPublicationGrowthYear') && str_contains($js, 'data-growth-year'), 'publication growth chart renderer or interaction missing');
assert_true(str_contains($css, 'analytics-scrollbody') && str_contains($css, 'overscroll-behavior: contain') && str_contains($css, 'scrollbar-gutter: stable') && str_contains($css, 'button:hover') && str_contains($css, 'transform: none'), 'modal scrolling or academic button hover styling missing');
assert_true(str_contains($css, '.alert-sheet') && str_contains($css, 'overflow-y: auto') && str_contains($css, '.alert-form > .advanced-filter-actions') && str_contains($css, 'position: sticky'), 'alert signup modal must scroll and keep submit actions reachable');
assert_true(str_contains($css, '.timeline-svg .chart-plot-bg') && str_contains($css, '.timeline-svg .timeline-bucket:hover') && str_contains($css, 'vector-effect: non-scaling-stroke') && str_contains($css, 'width: max(100%, 760px)'), 'timeline chart polish styles missing');
assert_true(str_contains($alertServicePhp, 'border-left:4px solid #123c31') && str_contains($alertServicePhp, 'background:#ffffff') && str_contains($alertServicePhp, 'color:#24251f') && str_contains($alertServicePhp, 'color:#1a6b54') && !str_contains($alertServicePhp, '#062b49') && !str_contains($alertServicePhp, '#087d9d') && !str_contains($alertServicePhp, '#eef4f8'), 'alert email templates should match the app palette and avoid the old blue theme');
assert_true(str_contains($js, 'border-left:4px solid #123c31') && str_contains($js, 'border:1px solid #d8d2c4') && !str_contains($js, '#062b49') && !str_contains($js, '#087d9d') && !str_contains($js, '#eef4f8') && str_contains($healthPhp, 'border-left:4px solid #123c31') && !str_contains($healthPhp, '#eef4f8') && !str_contains($healthPhp, '#0e2742'), 'generated blob/utility pages should match the app palette and avoid the old blue theme');
assert_true(str_contains($css, 'padding-right: 48px') && str_contains($css, 'right: 3px') && str_contains($css, 'min-width: 34px') && str_contains($css, '.floating-utility-button svg') && str_contains($css, '.scroll-top svg'), 'mobile floating page tools should be smaller and reserve right-side text space');
assert_true(str_contains($css, '@media (min-width: 1181px)') && str_contains($css, 'html.nav-sidebar-collapsed .topbar') && str_contains($css, 'width: 74px') && str_contains($css, 'html.nav-sidebar-collapsed .hero-band') && str_contains($css, 'width: calc(100vw - 74px)') && str_contains($css, 'padding-right: 76px') && str_contains($css, '.page-tools') && str_contains($css, 'right: 10px') && str_contains($css, '.scroll-top') && str_contains($css, 'bottom: 24px'), 'desktop sidebar collapse and bottom-aligned floating utility rail should reserve layout space without a wrapper box');
assert_true(str_contains($css, '.floating-utility-button') && str_contains($css, 'opacity: .86') && str_contains($css, '.scroll-top.is-visible') && str_contains($css, '.scroll-top:hover') && str_contains($css, 'opacity: 1'), 'floating page tools should be slightly transparent until hover or focus');
assert_true(str_contains($css, '--academic-page: #ffffff') && str_contains($css, 'background: var(--academic-page)') && str_contains($css, '--important-shadow') && str_contains($css, 'page-tools') && str_contains($css, 'color: #fff'), 'plain white background, subtle important-button shadow, page tool controls, or light button text styling missing');
assert_true(str_contains($alertPhp, 'alert-manage-status') && str_contains($alertPhp, 'Alert UUID') && str_contains($alertPhp, 'Update preferences') && str_contains($alertPhp, 'Pause delivery') && str_contains($alertPhp, 'Resume delivery') && str_contains($alertPhp, 'resend_confirmation') && str_contains($alertPhp, 'unenrol') && str_contains($alertPhp, 'Delete alert data'), 'alert management controls missing');
assert_true(str_contains($js, 'subtleHaptic') && str_contains($js, 'navigator.vibrate'), 'subtle haptic feedback missing');
assert_true(str_contains($js, 'initScrollProgress') && str_contains($js, 'initScrollTop') && str_contains($js, 'initGsapEnhancements'), 'scroll progress, top control, or GSAP enhancement missing');
assert_true(str_contains($js, 'serviceWorker') && str_contains($js, 'beforeinstallprompt'), 'PWA registration or install prompt handling missing');
foreach (['PubMedFetcher', 'CrossrefFetcher', 'EuropePmcFetcher', 'OpenAlexFetcher', 'medrxiv', 'biorxiv', 'PsyArXivFetcher', 'ClinicalTrialsFetcher'] as $fetcherMarker) {
    assert_true(str_contains($publicationService, $fetcherMarker), 'active source fetcher missing: ' . $fetcherMarker);
}
$api = file_get_contents(__DIR__ . '/../api.php');
$rss = file_get_contents(__DIR__ . '/../rss.php');
assert_true(str_contains($api, "'authors'") && str_contains($api, "'citation'") && str_contains($api, "'sources'") && str_contains($api, "'publication_statuses'"), 'API research resources missing');
assert_true(!str_contains($api, "'scite'") && !str_contains($api, 'SciteService'), 'Scite API resource should be removed');
assert_true(str_contains($api, '$latestLimit = max(1, min') && str_contains($api, 'ExportService::bibtex'), 'latest API page-size/BibTeX support missing');
assert_true(str_contains($api, '$paperLimit = request_value') && str_contains($api, '$paperOffset = request_value') && str_contains($api, "mb_strtolower(\$paperLimit") && str_contains($api, "\$paperFilters['per_page'] = 'all'") && str_contains($api, "'resource' => 'papers'") && str_contains($api, "'filters' => \$paperFilters"), 'filtered papers API metadata or limit/offset/all aliases missing');
assert_true(str_contains($rss, 'http_response_code(410)') && str_contains($rss, 'This RSS feed has been removed') && !str_contains($rss, 'application/rss+xml') && !str_contains($rss, '$repo->latest'), 'RSS endpoint should be removed with 410 Gone');
assert_true(is_file(__DIR__ . '/../widget.js.php'), 'script widget endpoint missing');
assert_true(is_file(__DIR__ . '/../database.php'), 'SQLite database download endpoint missing');
$databasePhp = file_get_contents(__DIR__ . '/../database.php');
assert_true(str_contains($databasePhp, 'application/vnd.sqlite3') && str_contains($databasePhp, 'rights-safe-metadata-core') && str_contains($databasePhp, 'tracker_site_url') && str_contains($databasePhp, 'X-Publication-Tracker-Filename') && str_contains($databasePhp, 'download_filename'), 'SQLite database download headers missing');
assert_true(!str_contains($databasePhp, 'alert_subscriptions') && !str_contains($databasePhp, 'push_subscriptions') && !str_contains($databasePhp, 'admin_token'), 'SQLite database download must not expose sensitive runtime tables');
assert_true(
    str_contains($databasePhp, 'source_provenance_json')
    && str_contains($databasePhp, 'abstract_available')
    && str_contains($databasePhp, 'abstract_redistributed')
    && preg_match('/:\\b(?:abstract|keywords|raw_json)\\b/', $databasePhp) !== 1,
    'SQLite download must use an allowlisted rights-safe projection'
);
assert_true(is_file(__DIR__ . '/../manifest.webmanifest') && is_file(__DIR__ . '/../sw.js') && is_file(__DIR__ . '/../offline.html'), 'PWA files missing');
$manifest = json_decode((string)file_get_contents(__DIR__ . '/../manifest.webmanifest'), true);
assert_true(($manifest['display'] ?? '') === 'standalone' && ($manifest['scope'] ?? '') === '/' && ($manifest['start_url'] ?? '') === '/?source=pwa' && count($manifest['icons'] ?? []) >= 3, 'PWA manifest incomplete');
$sw = file_get_contents(__DIR__ . '/../sw.js');
assert_true(str_contains($sw, 'offline.html') && str_contains($sw, 'networkFirst') && str_contains($sw, 'cacheFirst'), 'service worker cache strategy missing');
assert_true(str_contains($sw, 'self.addEventListener("push"') && str_contains($sw, 'notificationclick'), 'service worker push handlers missing');
assert_true(!str_contains($sw, 'publication-tracker\\/') && !str_contains($config ?? '', 'publication-tracker/data'), 'legacy publication-tracker runtime path should be removed');
assert_true($cacheVersion !== '' && str_contains($sw, 'ASSET_VERSION') && str_contains($sw, 'styles.min.css?v=${ASSET_VERSION}') && str_contains($sw, 'app.min.js?v=${ASSET_VERSION}') && !str_contains($sw, 'three.r134.min.js') && !str_contains($sw, 'vanta.net.min.js') && !str_contains($sw, 'mockup-brand-green.png') && str_contains($sw, 'roboto-latin.woff2') && !str_contains($sw, 'mushrooms-4560675.jpg') && str_contains($sw, 'mushroom-brand-mark.webp') && !str_contains($sw, 'footer-mushroom-bg.webp') && str_contains($sw, 'preloader-mushroom-desktop.webp') && str_contains($sw, 'preloader-mushroom-mobile.webp') && str_contains($sw, 'SKIP_WAITING') && str_contains($sw, 'self.registration.scope') && !str_contains($sw, 'gsap.min.js') && !str_contains($sw, '|rss|'), 'service worker update cycle or current app shell assets missing');
$pushPhp = file_get_contents(__DIR__ . '/../push.php');
assert_true(str_contains($pushPhp, 'action') && str_contains($pushPhp, 'subscribe') && str_contains($pushPhp, 'public-key'), 'push endpoint missing actions');
$alerts = new AlertService($db, $repo);
$subscription = $alerts->subscribe('researcher@example.org', 'daily', ['psilocybin', 'psilocin'], null);
assert_true(!empty($subscription['id']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)($subscription['public_uuid'] ?? '')), 'subscription insert or UUID generation failed');
assert_true((int)$subscription['active'] === 0 && empty($subscription['confirmed_at']) && !empty($subscription['confirmation_token']), 'new subscription should require confirmation');
$storedAlert = $db->pdo()->query('SELECT public_uuid, email, email_cipher, email_blind_index, token, token_cipher, token_blind_index, confirmation_token, confirmation_token_cipher, confirmation_token_blind_index FROM alert_subscriptions WHERE id = ' . (int)$subscription['id'])->fetch();
assert_true((string)$storedAlert['public_uuid'] === (string)$subscription['public_uuid'], 'alert UUID should be stored');
assert_true(($storedAlert['email'] ?? '') === '[encrypted]' && ($storedAlert['token'] ?? '') === '[encrypted]' && ($storedAlert['confirmation_token'] ?? '') === '[encrypted]', 'alert sensitive legacy columns should be encrypted placeholders');
assert_true(!empty($storedAlert['email_cipher']) && !empty($storedAlert['email_blind_index']) && !empty($storedAlert['token_cipher']) && !empty($storedAlert['token_blind_index']) && !empty($storedAlert['confirmation_token_cipher']) && !empty($storedAlert['confirmation_token_blind_index']), 'alert encrypted columns missing');
assert_true(!str_contains((string)$storedAlert['email_cipher'], 'researcher@example.org'), 'alert email cipher must not contain plaintext');
assert_true(count($alerts->generateDue('daily', true)) === 0, 'unconfirmed alert should not generate digests');
$confirmation = $alerts->confirmationDigest($subscription);
assert_true(str_contains($confirmation['text'], 'No publication digests will be sent unless') && str_contains($confirmation['html'], 'cid:psilocybin-research-logo'), 'confirmation email template missing safeguards or logo');
assert_true(str_contains($confirmation['text'], 'Review or adjust preferences:') && str_contains($confirmation['html'], 'Review or adjust preferences'), 'confirmation email should include manage-preferences link');
assert_true(str_contains($confirmation['text'], 'Alert UUID:') && str_contains($confirmation['html'], 'Alert UUID'), 'confirmation email should include alert UUID');
$subscription = $alerts->confirm((string)$subscription['confirmation_token']);
assert_true($subscription !== null && (int)$subscription['active'] === 1 && !empty($subscription['confirmed_at']), 'confirmation should activate alert');

$first = $alerts->generateDue('daily', true);
$second = $alerts->generateDue('daily', true);
assert_true(count($first) === 1, 'expected first digest');
assert_true(count($second) === 0, 'duplicate alert prevention failed');
assert_true(str_contains($first[0]['text'], 'Manage alert preferences:') && str_contains($first[0]['text'], 'Unsubscribe from this alert:') && str_contains($first[0]['text'], 'Alert UUID:'), 'text digest manage/unsubscribe/UUID links missing');
assert_true(str_contains($first[0]['html'], 'cid:psilocybin-research-logo'), 'html digest CID logo missing');
assert_true(str_contains($first[0]['html'], 'Manage preferences'), 'html digest manage-preferences CTA missing');
assert_true(str_contains($first[0]['html'], 'Unsubscribe') && str_contains($first[0]['html'], 'Searchable psilocybin and psilocin bibliometric database.'), 'html digest unsubscribe CTA or branded header missing');
assert_true(str_contains($first[0]['html'], 'Data protection notice'), 'html digest data protection notice missing');
assert_true(isset($first[0]['headers']['List-Unsubscribe']), 'List-Unsubscribe header missing');
assert_true(isset($first[0]['headers']['X-Alert-Manage-URL']), 'alert manage URL header missing');
assert_true(($first[0]['headers']['X-Alert-UUID'] ?? '') === (string)$subscription['public_uuid'], 'alert UUID header missing');
assert_true(str_contains($first[0]['subject'], 'new psilocybin/psilocin publications'), 'broad alert subject missing');
assert_true(($first[0]['attachments'][0]['content_id'] ?? '') === 'psilocybin-research-logo' && ($first[0]['attachments'][0]['filename'] ?? '') === 'psilocybin-research-mushroom.webp' && ($first[0]['attachments'][0]['content_type'] ?? '') === 'image/webp', 'mushroom CID attachment metadata missing');
$mime = $alerts->renderMimeMessage($first[0]);
assert_true(str_contains($mime, 'Content-ID: <psilocybin-research-logo>') && str_contains($mime, 'multipart/related'), 'MIME CID logo embedding missing');
$mailMessage = $alerts->buildMailMessage($first[0]);
assert_true($mailMessage['to'] === 'researcher@example.org' && str_contains(implode("\n", $mailMessage['headers']), 'List-Unsubscribe'), 'mail message headers missing');
$updatedSubscription = $alerts->updatePreferences((string)$subscription['token'], 'weekly', ['psilocybin'], 'depression', null, null, null);
assert_true($updatedSubscription['frequency'] === 'weekly' && $updatedSubscription['keywords'] === 'depression' && (int)$updatedSubscription['active'] === 1, 'alert preference update failed');
assert_true($alerts->pause((string)$subscription['token']), 'pause failed');
$paused = $alerts->findByToken((string)$subscription['token']);
assert_true($paused !== null && (int)$paused['active'] === 0, 'pause did not deactivate alert');
$pausedUpdated = $alerts->updatePreferences((string)$subscription['token'], 'monthly', ['psilocin'], 'anxiety', null, null, null);
assert_true($pausedUpdated['frequency'] === 'monthly' && $pausedUpdated['keywords'] === 'anxiety' && (int)$pausedUpdated['active'] === 0, 'editing a paused alert should not resume delivery');
$resumed = $alerts->resume((string)$subscription['token']);
assert_true($resumed !== null && (int)$resumed['active'] === 1, 'resume did not reactivate confirmed alert');
assert_true($alerts->unenrol((string)$subscription['token']), 'unenrol failed');
$inactive = $alerts->findByToken((string)$subscription['token']);
assert_true($inactive !== null && (int)$inactive['active'] === 0, 'unsubscribe did not deactivate alert');
$deleteAlert = $alerts->subscribe('delete-alert@example.org', 'weekly', ['psilocybin'], 'safety');
$deleteAlert = $alerts->confirm((string)$deleteAlert['confirmation_token']);
assert_true($deleteAlert !== null && $alerts->deleteSubscription((string)$deleteAlert['token']), 'delete alert failed');
assert_true($alerts->findByToken((string)$deleteAlert['token']) === null, 'deleted alert should not be available');
$reactivated = $alerts->subscribe('researcher@example.org', 'daily', ['psilocybin', 'psilocin'], null);
assert_true((int)$reactivated['active'] === 0 && empty($reactivated['confirmed_at']) && !empty($reactivated['confirmation_token']), 're-subscribing should require a new confirmation');

$repo->upsert([
    'title' => 'Psilocybin citation follow-up paper',
    'authors' => 'Citation Author',
    'abstract' => 'A new psilocybin paper with reference metadata.',
    'journal' => 'Citation Journal',
    'publication_date' => gmdate('Y-m-d'),
    'doi' => '10.1000/citing-paper',
    'pubmed_id' => '77777',
    'source_url' => 'https://example.org/citing-paper',
    'keywords' => 'psilocybin, references',
    'source_name' => 'Crossref',
    'raw' => ['reference_dois' => ['10.5555/cited-target']],
]);
$citationAlert = $alerts->subscribe('citation@example.org', 'daily', ['psilocybin'], null, null, null, null, 'https://doi.org/10.5555/cited-target');
assert_true((string)($citationAlert['cited_doi'] ?? '') === '10.5555/cited-target', 'cited DOI should normalize on subscription');
$citationAlert = $alerts->confirm((string)$citationAlert['confirmation_token']);
assert_true($citationAlert !== null && (int)$citationAlert['active'] === 1, 'citation alert confirmation failed');
$citationDigests = $alerts->generateDue('daily', false);
$citationDigest = null;
foreach ($citationDigests as $digest) {
    if (($digest['subscription']['email'] ?? '') === 'citation@example.org') {
        $citationDigest = $digest;
    }
}
assert_true($citationDigest !== null && str_contains($citationDigest['text'], 'Psilocybin citation follow-up paper'), 'citation DOI alert did not match reference metadata');

$pushService = new PushService($db, $repo);
$vapidPublic = $pushService->publicKey();
assert_true(strlen($vapidPublic) > 80, 'VAPID public key missing');
$deviceKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$deviceDetails = openssl_pkey_get_details($deviceKey);
$devicePublic = Config::base64UrlEncode("\x04" . $deviceDetails['ec']['x'] . $deviceDetails['ec']['y']);
$pushResult = $pushService->subscribe([
    'endpoint' => 'https://push.example.test/subscription/1',
    'keys' => ['p256dh' => $devicePublic, 'auth' => Config::base64UrlEncode(random_bytes(16))],
], 'Test Browser');
assert_true($pushResult['ok'] === true && count($pushService->activeSubscriptions()) === 1, 'push subscription failed');
$storedPush = $db->pdo()->query('SELECT endpoint, endpoint_cipher, endpoint_blind_index, p256dh, p256dh_cipher, auth, auth_cipher, user_agent, user_agent_cipher FROM push_subscriptions LIMIT 1')->fetch();
assert_true(($storedPush['endpoint'] ?? '') === '[encrypted]' && ($storedPush['p256dh'] ?? '') === '[encrypted]' && ($storedPush['auth'] ?? '') === '[encrypted]', 'push secrets should use encrypted placeholders in legacy columns');
assert_true(!empty($storedPush['endpoint_cipher']) && !empty($storedPush['endpoint_blind_index']) && !empty($storedPush['p256dh_cipher']) && !empty($storedPush['auth_cipher']) && !empty($storedPush['user_agent_cipher']), 'push encrypted columns missing');
assert_true(!str_contains((string)$storedPush['endpoint_cipher'], 'push.example.test') && !str_contains((string)$storedPush['user_agent_cipher'], 'Test Browser'), 'push ciphertext must not contain plaintext');
$recentPushPapers = $pushService->newPublicationsSince(gmdate('Y-m-d H:i:s', strtotime('-1 hour')), 5);
assert_true(count($recentPushPapers) >= 1, 'push recent publication lookup failed');
assert_true($pushService->unsubscribe('https://push.example.test/subscription/1'), 'push unsubscribe failed');

OperationalLogger::info('test.health.log', ['token' => 'should-redact', 'count' => 1]);
Heartbeat::beat('update-daily', 'ok', ['inserted' => 1, 'secret' => 'should-redact']);
$heartbeat = Heartbeat::read('update-daily');
assert_true($heartbeat !== null && $heartbeat['status'] === 'ok', 'heartbeat read/write failed');
assert_true(($heartbeat['context']['secret'] ?? '') === '[redacted]', 'heartbeat secret redaction failed');
$health = (new HealthService($db, $repo, $runs))->report();
assert_true(isset($health['status'], $health['checks']['database'], $health['checks']['storage_security'], $health['checks']['backup'], $health['checks']['heartbeat'], $health['checks']['log']), 'health report missing checks');
assert_true(is_file(__DIR__ . '/../health.php'), 'health endpoint missing');

echo "All tests passed\n";
