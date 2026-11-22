<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('X-Robots-Tag: noindex, follow');
header('Content-Type: application/json; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); echo '{"error":"Use GET or HEAD."}'; exit; }
$unknown = array_filter(array_keys($_GET), static fn($key): bool => !in_array($key, ['snapshot', 'format'], true) && !str_starts_with((string)$key, 'utm_'));
$id = is_string($_GET['snapshot'] ?? null) ? $_GET['snapshot'] : '';
$format = is_string($_GET['format'] ?? null) ? $_GET['format'] : '';
if ($unknown || preg_match('/^[a-f0-9]{64}$/D', $id) !== 1 || (!isset(DatasetArtifacts::formats()[$format]) && $format !== 'manifest')) { http_response_code(400); if ($method !== 'HEAD') echo '{"error":"Use an exact snapshot and format URL from /dataset-manifest.json."}'; exit; }
RequestWorkload::protect();
$entry = DatasetArtifacts::artifact($id, $format);
if ($entry === null || ($handle = @fopen($entry['path'], 'rb')) === false) { http_response_code(404); if ($method !== 'HEAD') echo '{"error":"Snapshot unavailable or retired. Retrieve the latest /dataset-manifest.json."}'; exit; }
$stat = fstat($handle);
if ($stat === false || $stat['size'] !== $entry['bytes']) { fclose($handle); http_response_code(503); if ($method !== 'HEAD') echo '{"error":"Snapshot file unavailable. Retry later."}'; exit; }
$text = in_array($entry['mime_type'], ['application/json', 'text/plain', 'text/csv'], true);
header('Content-Type: ' . $entry['mime_type'] . ($text ? '; charset=utf-8' : ''));
header('Content-Disposition: ' . ($text ? 'inline' : 'attachment') . '; filename="' . $entry['filename'] . '"');
header('Content-Length: ' . $entry['bytes']);
header('X-Publication-Tracker-Export-Count: ' . $entry['record_count']);
header('X-Publication-Tracker-Snapshot: ' . $id);
header('X-Publication-Tracker-SHA256: ' . $entry['sha256']);
header('Access-Control-Expose-Headers: Content-Length, X-Publication-Tracker-Export-Count, X-Publication-Tracker-Snapshot, X-Publication-Tracker-SHA256');
if ($method !== 'HEAD') fpassthru($handle);
fclose($handle);
