<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';
RequestWorkload::protect('api', 4);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('Access-Control-Allow-Origin: *');

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$datasetRepo = new OpenDatasetRepository($db);
$resource = request_value('resource', 'papers');
if (in_array($resource, ['paper', 'papers', 'latest'], true)) {
    $semanticQuery = canonical_query_params($_GET);
    $semanticQuery['resource'] = $resource === 'paper' ? 'record' : 'records';
    if ($resource === 'paper') $semanticQuery['id'] = (int)request_value('id', '0');
    header('Link: <' . LinkedData::base() . 'linked-data.php?' . http_build_query($semanticQuery) . '>; rel="alternate"; type="application/ld+json"');
}

$requestedLimit = request_value('limit', '25');
$limit = strtolower((string)$requestedLimit) === 'all' ? PHP_INT_MAX : max(1, (int)$requestedLimit);
$offset = max(0, (int)request_value('offset', '0'));
$id = (int)request_value('id', '0');
$latestLimit = max(1, min((int)request_value('limit', '25'), 200));
$paperFilters = RequestFilters::fromGlobals();
$paperLimit = request_value('limit');
$paperOffset = request_value('offset');
if ($paperLimit !== null && request_value('per_page') === null) {
    if (mb_strtolower($paperLimit, 'UTF-8') === 'all') {
        $paperFilters['per_page'] = 'all';
        $paperFilters['page'] = 1;
    } else {
        $paperPageSize = max(5, min((int)$paperLimit, 200));
        $paperFilters['per_page'] = $paperPageSize;
        if ($paperOffset !== null && request_value('page') === null) {
            $paperFilters['page'] = (int)floor(max(0, (int)$paperOffset) / $paperPageSize) + 1;
        }
    }
}

$payload = match ($resource) {
    'research_changes'=>['changes'=>(new ResearchChanges($db))->latest([],max(1,min(200,$limit)))],
    'research_comparison' => (new ResearchExplorer($db))->comparison((array)($_GET['ids'] ?? [])),
    'research_network' => (new ResearchExplorer($db))->network((string)request_value('q', ''), (string)request_value('topic', ''), max(10, min(300, $limit)), request_value('full', '0') === '1'),
    'research_trials' => (new ResearchExplorer($db))->trials(array_combine(['q', 'country', 'sponsor', 'phase', 'status', 'condition'], array_map(static fn(string $key): string => mb_substr((string)request_value($key, ''), 0, 240), ['q', 'country', 'sponsor', 'phase', 'status', 'condition']))),
    'research_results' => ['studies' => (new ResearchExplorer($db))->results((string)request_value('topic', ''))],
    'latest' => [
        'papers' => array_map(static function (array $paper): array {
            $paper['bibtex'] = ExportService::bibtex([$paper]);
            return $paper;
        }, $repo->latest($latestLimit, $offset)),
        'limit' => $latestLimit,
        'offset' => $offset,
    ],
    'analytics' => $repo->analytics(),
    'authors' => ['authors' => $repo->authors(request_value('search', request_value('q')), $limit)],
    'topics' => ['topics' => $repo->topics()],
    'study_types' => ['study_types' => $repo->studyTypes()],
    'sources' => ['sources' => $repo->sources()],
    'publication_statuses' => ['publication_statuses' => $repo->publicationStatuses(), 'options' => PublicationRepository::publicationStatusOptions()],
    'journals' => ['journals' => $repo->journals()],
    'paper' => ['paper' => $id > 0 ? $repo->publicById($id) : null],
    'related' => ['papers' => ($id > 0 && ($paper = $repo->publicById($id))) ? $repo->relatedPapers($paper, $limit) : []],
    'citation_graph' => ['references' => ($id > 0 && ($paper = $repo->publicById($id))) ? $repo->citedReferences($paper, $limit) : [], 'citing' => ($id > 0 && ($paper = $repo->publicById($id)) && !empty($paper['doi'])) ? $repo->citingPapers((string)$paper['doi'], $limit) : []],
    'trials' => $repo->trials($paperFilters, $limit),
    'evidence_map' => ['rows' => $repo->evidenceMap()],
    'datasets' => array_merge($datasetRepo->search([
        'q' => request_value('q', ''),
        'topic' => request_value('topic', ''),
        'repository' => request_value('repository', ''),
        'access' => request_value('access', ''),
        'sort' => request_value('sort', 'newest'),
        'per_page' => request_value('per_page', request_value('limit', '24')),
        'page' => request_value('page', '1'),
    ]), ['resource' => 'datasets', 'stats' => $datasetRepo->stats(), 'repositories' => $datasetRepo->repositories(), 'topics' => $datasetRepo->topics()]),
    'citation' => ['citation' => ($id > 0 && ($paper = $repo->publicById($id))) ? ExportService::citationText($paper) : null],
    default => array_merge($repo->search($paperFilters), [
        'resource' => 'papers',
        'filters' => $paperFilters,
    ]),
};
// Network records already pass through public_paper(); avoid a second full graph copy.
echo json_encode($resource === 'research_network' ? $payload : rights_safe_public_payload($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
