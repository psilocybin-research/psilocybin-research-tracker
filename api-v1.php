<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
header('X-Robots-Tag: noindex, follow');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Expose-Headers: Link, X-Publication-Tracker-Export-Count, X-Publication-Tracker-Export-Limit');
$route = preg_replace('~^/api/v1/~', '', (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$route = trim($route, '/');
$format = is_scalar($_GET['format'] ?? '') ? strtolower((string)($_GET['format'] ?? 'json')) : '';
if (!in_array($format,['json','csv'],true)) { http_response_code(400); header('Content-Type: application/json; charset=utf-8'); echo '{"error":"format must be json or csv"}'; exit; }
if (isset($_GET['compound']) && $route !== 'papers') { http_response_code(400); header('Content-Type: application/json; charset=utf-8'); echo '{"error":"compound filtering is supported on papers; trial collections contain indexed psilocybin studies"}'; exit; }
$allowed = ['changes'=>'research_changes','papers'=>'papers','trials'=>'research_trials','network'=>'research_network','results'=>'research_results','topics'=>'topics','authors'=>'authors'];
if ($route === 'study-links') {
    header('Content-Type: application/json; charset=utf-8');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); exit; }
    $unknown = array_filter(array_keys($_GET), static fn($key): bool => !in_array($key, ['id', 'format'], true) && !str_starts_with((string)$key, 'utm_'));
    if ($unknown || $format !== 'json' || (isset($_GET['id']) && (!is_string($_GET['id']) || preg_match('/^[1-9][0-9]{0,9}$/D', $_GET['id']) !== 1))) { http_response_code(400); echo '{"error":"Use JSON with an optional visible numeric record id."}'; exit; }
    RequestWorkload::protect('api', 4);
    $db = new Database(); $db->initialize();
    try { $data = isset($_GET['id']) ? StudyRelationships::forRecord($db, (int)$_GET['id']) : StudyRelationships::catalogue($db); }
    catch (OutOfBoundsException $error) { http_response_code(404); echo '{"error":"Public record not found."}'; exit; }
    header('Cache-Control: no-store');
    $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    header('X-Publication-Tracker-SHA256: ' . hash('sha256', $content));
    header('Content-Length: ' . strlen($content));
    if ($method !== 'HEAD') echo $content;
    exit;
}
if ($route === 'statistics/publications-by-year') {
    header('Content-Type: application/json; charset=utf-8');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); echo '{"error":"Use GET or HEAD."}'; exit; }
    $unknown = array_filter(array_keys($_GET), static fn($key): bool => $key !== 'format' && !str_starts_with((string)$key, 'utm_'));
    if ($unknown) { http_response_code(400); if ($method !== 'HEAD') echo '{"error":"This aggregate describes the complete public catalogue; filters and pagination are not supported."}'; exit; }
    RequestWorkload::protect('api', 4);
    $db = new Database(); $db->initialize();
    $summary = PublicationStatistics::summary($db);
    $content = $format === 'csv' ? PublicationStatistics::csv($summary) : json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'application/json') . '; charset=utf-8');
    header('Cache-Control: no-store');
    header('Link: <https://psilocybin-research.com/for-ai.txt>; rel="describedby"');
    header('X-Publication-Tracker-Export-Count: ' . $summary['record_count']);
    header('X-Publication-Tracker-Catalogue-Revision: ' . $summary['catalogue_revision']);
    header('X-Publication-Tracker-Generated-At: ' . $summary['generated_at_utc']);
    header('X-Publication-Tracker-SHA256: ' . hash('sha256', $content));
    header('Access-Control-Expose-Headers: Link, X-Publication-Tracker-Export-Count, X-Publication-Tracker-Catalogue-Revision, X-Publication-Tracker-Generated-At, X-Publication-Tracker-SHA256');
    if ($format === 'csv') header('Content-Disposition: attachment; filename="psilocybin-publications-by-year.csv"');
    header('Content-Length: ' . strlen($content));
    if ($method !== 'HEAD') echo $content;
    exit;
}
if (str_starts_with($route, 'countries/')) {
    $slug = substr($route, 10);
    $db = new Database(); $db->initialize();
    $explorer = new ResearchExplorer($db);
    $all = $explorer->trials(); $country = null;
    foreach (array_keys($all['facets']['countries']) as $name) if (preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) === $slug) { $country = $name; break; }
    if (!$country) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo '{"error":"Country not present in indexed registry locations"}'; exit; }
    $_GET['country'] = $country; $route = 'trials';
} elseif ($route === 'countries') {
    if ($format !== 'json') { http_response_code(400); header('Content-Type: application/json; charset=utf-8'); echo '{"error":"The country directory supports JSON; use a country collection for CSV"}'; exit; }
    RequestWorkload::protect('api', 4);
    $db = new Database(); $db->initialize();
    $all = (new ResearchExplorer($db))->trials();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['countries'=>array_map(static fn($name,$count)=>['name'=>$name,'trial_count'=>$count,'url'=>LinkedData::base().'api/v1/countries/'.preg_replace('/[^a-z0-9]+/','-',strtolower($name))],array_keys($all['facets']['countries']),array_values($all['facets']['countries']))],JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}
if (!isset($allowed[$route])) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo '{"error":"Unknown API resource","documentation":"/research-api.php"}'; exit; }
if (isset($_GET['compound'])) {
    $compound = is_scalar($_GET['compound']) ? strtolower(trim((string)$_GET['compound'])) : '';
    if (!in_array($compound, ['psilocybin','psilocin'],true)) { http_response_code(400); header('Content-Type: application/json; charset=utf-8'); echo '{"error":"compound must be psilocybin or psilocin"}'; exit; }
    $_GET['substances'] = [$compound];
}
if ($format === 'csv' && $route === 'papers') { $_GET['format']='csv'; require __DIR__.'/export.php'; exit; }
if ($format === 'csv' && $route === 'trials') {
    RequestWorkload::protect('api', 4); $db = new Database(); $db->initialize();
    $filters=[];foreach(['q','country','sponsor','phase','status','condition'] as $key)$filters[$key]=(string)request_value($key,'');
    $data=(new ResearchExplorer($db))->trials($filters);
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="psilocybin-trials.csv"');
    $stream=fopen('php://output','w');fputcsv($stream,['nct_id','title','sponsor','phase','status','enrollment','enrollment_type','start_date','primary_completion_date','completion_date','countries','registry_url'],',','"','');
    foreach($data['trials'] as $t)fputcsv($stream,[$t['nct_id'],$t['title'],$t['sponsor'],implode(';',$t['phases']),$t['status'],$t['enrollment']['count'],$t['enrollment']['type'],$t['start_date'],$t['primary_completion_date'],$t['completion_date'],implode(';',$t['countries']),$t['registry_url']],',','"','');exit;
}
if ($format === 'csv') { http_response_code(400);header('Content-Type: application/json; charset=utf-8');echo '{"error":"CSV is supported for papers, trials and country trial collections"}';exit; }
$_GET['resource'] = $allowed[$route];
require __DIR__ . '/api.php';
