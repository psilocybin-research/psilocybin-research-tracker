<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
RequestWorkload::protect('api', 4);
header('Content-Type: application/ld+json; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('Access-Control-Allow-Origin: *');
header('X-Robots-Tag: noindex, follow');
header('Link: <' . LinkedData::base() . 'research-dataset.php>; rel="describedby"');
$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$resource = (string)request_value('resource', 'dataset');
$map = new TrialMapService($db);
$makeRecord = static fn(array $p): array => LinkedData::record($p, $repo->citedReferences($p, 100), [], $p['publication_status'] === 'clinical trial' ? $map->forPublication((int)$p['id']) : null);
if ($resource === 'dataset') {
    $document = LinkedData::document([LinkedData::dataset(), LinkedData::website(), LinkedData::maintainer()]);
} elseif ($resource === 'vocabulary') {
    $document = LinkedData::vocabularyGraph();
} elseif ($resource === 'term' || $resource === 'substance') {
    $kind = $resource === 'substance' ? 'substance' : (string)request_value('kind', 'topic');
    $label = (string)request_value('term', '');
    if (!isset(LinkedData::vocabulary()[$kind][$label])) { http_response_code(404); $document = LinkedData::document([]); }
    elseif ($resource === 'substance') $document = LinkedData::document([['@type' => 'Drug', '@id' => LinkedData::base() . 'linked-data.php?resource=substance&term=' . rawurlencode($label), 'name' => ucfirst($label)]]);
    else $document = LinkedData::document([LinkedData::term($kind, $label)]);
} elseif ($resource === 'record') {
    $paper = $repo->publicById((int)request_value('id', '0'));
    if (!$paper) { http_response_code(404); $document = LinkedData::document([]); }
    else $document = LinkedData::recordGraph($paper, $repo->citedReferences($paper, 100), (new OpenDatasetRepository($db))->forPublication((int)$paper['id']), $paper['publication_status'] === 'clinical trial' ? $map->forPublication((int)$paper['id']) : null);
} elseif ($resource === 'author') {
    $profile = $repo->authorProfile((string)request_value('author', ''));
    if (!$profile) { http_response_code(404); $document = LinkedData::document([]); }
    else $document = LinkedData::document([LinkedData::person($profile['name'], $profile['orcid'], $profile['openalex_id'])]);
} elseif ($resource === 'records') {
    $filters = RequestFilters::fromGlobals();
    $filters['per_page'] = max(5, min(100, (int)request_value('per_page', '25')));
    $result = $repo->search($filters);
    $nodes = array_map(static fn(array $p): array => $makeRecord($repo->publicById((int)$p['id']) ?? $p), $result['rows']);
    $items = array_map(static fn(array $node, int $i): array => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => ['@id' => $node['@id']]], $nodes, array_keys($nodes));
    $collectionUrl = LinkedData::base() . 'linked-data.php?' . http_build_query(array_merge(canonical_query_params($_GET), ['resource' => 'records', 'page' => $result['page'], 'per_page' => $filters['per_page']]));
    array_unshift($nodes, ['@type' => 'CollectionPage', '@id' => $collectionUrl, 'url' => $collectionUrl, 'name' => 'Paginated public research entities',
        'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($nodes), 'itemListElement' => $items],
        'isPartOf' => ['@id' => LinkedData::base() . '#live-dataset']]);
    if ($result['page'] < $result['pages']) {
        $next = LinkedData::base() . 'linked-data.php?' . http_build_query(array_merge(canonical_query_params($_GET), ['resource' => 'records', 'page' => $result['page'] + 1, 'per_page' => $filters['per_page']]));
        header('Link: <' . $next . '>; rel="next"', false);
    }
    $document = LinkedData::document($nodes);
} else {
    http_response_code(400);
    $document = LinkedData::document([]);
}
$issues = LinkedDataValidator::issues($document);
if ($issues) { http_response_code(500); OperationalLogger::error('linked_data.invalid', ['issues' => array_column($issues, 'message')]); $document = LinkedData::document([]); }
echo json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
