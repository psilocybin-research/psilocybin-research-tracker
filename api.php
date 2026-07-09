<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('Access-Control-Allow-Origin: *');

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$resource = request_value('resource', 'papers');
$limit = (int)request_value('limit', '25');
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
    'citation' => ['citation' => ($id > 0 && ($paper = $repo->publicById($id))) ? ExportService::citationText($paper) : null],
    default => array_merge($repo->search($paperFilters), [
        'resource' => 'papers',
        'filters' => $paperFilters,
    ]),
};
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
