<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, follow');
header('Link: <https://psilocybin-research.com/for-ai.txt>; rel="describedby"');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); echo '{"error":"Use GET or HEAD."}'; exit; }
if (array_filter(array_keys($_GET), static fn($key): bool => !str_starts_with((string)$key, 'utm_'))) { http_response_code(400); if ($method !== 'HEAD') echo '{"error":"This manifest does not accept filters or format parameters."}'; exit; }
RequestWorkload::protect('api', 4);
$db = new Database(); $db->initialize();
$manifest = DatasetArtifacts::current($db);
if ($manifest === null) { http_response_code(503); header('Retry-After: 60'); if ($method !== 'HEAD') echo '{"error":"Verified snapshot is not available yet. Live public exports remain available at /research-dataset.php."}'; exit; }
$content = CatalogueDownload::encode($manifest);
header('X-Publication-Tracker-Snapshot: ' . $manifest['snapshot_id']);
header('X-Publication-Tracker-Export-Count: ' . $manifest['record_count']);
header('X-Publication-Tracker-SHA256: ' . hash('sha256', $content));
header('Access-Control-Expose-Headers: X-Publication-Tracker-Snapshot, X-Publication-Tracker-Export-Count, X-Publication-Tracker-SHA256');
header('Content-Length: ' . strlen($content));
if ($method !== 'HEAD') echo $content;
