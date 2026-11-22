<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';
RequestWorkload::protect();

$db = new Database();
$db->initialize();
$source = $db->pdo();

// Header probes must not build and vacuum a complete SQLite download.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    $filename = download_filename('psilocybin-research-publications', 'sqlite');
    header('Content-Type: application/vnd.sqlite3');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Publication-Tracker-Export-Count: ' . (int)$source->query('SELECT COUNT(*) FROM publications WHERE hidden = 0 AND false_positive = 0')->fetchColumn());
    header('X-Publication-Tracker-Export-Scope: rights-safe-metadata-core');
    header('X-Publication-Tracker-Filename: ' . $filename);
    header('X-Publication-Tracker-Site: ' . ExportService::TRACKER_SITE_URL);
    exit;
}

$tmp = tempnam(sys_get_temp_dir(), 'publication-tracker-sqlite-');
if ($tmp === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to create database export.';
    exit;
}
$sqlitePath = $tmp . '.sqlite';
rename($tmp, $sqlitePath);
register_shutdown_function(static function () use ($sqlitePath): void {
    if (is_file($sqlitePath)) {
        @unlink($sqlitePath);
    }
});

$snapshot = PublicDatabaseExport::build($db, $sqlitePath);
$count = $snapshot['record_count'];

$filename = download_filename('psilocybin-research-publications', 'sqlite');
header('Content-Type: application/vnd.sqlite3');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($sqlitePath));
header('X-Publication-Tracker-Export-Count: ' . $count);
header('X-Publication-Tracker-Export-Scope: rights-safe-metadata-core');
header('X-Publication-Tracker-Filename: ' . $filename);
header('X-Publication-Tracker-Site: ' . ExportService::TRACKER_SITE_URL);
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}
readfile($sqlitePath);
