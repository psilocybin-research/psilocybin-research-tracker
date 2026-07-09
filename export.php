<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$format = strtolower((string)request_value('format', 'csv'));
$limit = min(max((int)request_value('limit', '5000'), 1), 50000);
$selectedIds = request_array('ids');
$papers = $selectedIds ? $repo->publicByIds($selectedIds) : $repo->allForExport(RequestFilters::fromGlobals(), $limit);

$content = match ($format) {
    'json' => ExportService::json($papers),
    'bib', 'bibtex' => ExportService::bibtex($papers),
    'ris' => ExportService::ris($papers),
    'latex', 'tex' => ExportService::latex($papers),
    default => ExportService::csv($papers),
};
$mime = match ($format) {
    'json' => 'application/json; charset=utf-8',
    'bib', 'bibtex' => 'application/x-bibtex; charset=utf-8',
    'ris' => 'application/x-research-info-systems; charset=utf-8',
    'latex', 'tex' => 'application/x-tex; charset=utf-8',
    default => 'text/csv; charset=utf-8',
};
$ext = match ($format) {
    'json' => 'json',
    'bib', 'bibtex' => 'bib',
    'ris' => 'ris',
    'latex', 'tex' => 'tex',
    default => 'csv',
};
$filename = download_filename('psilocybin-research-publication-tracker', $ext);
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content));
header('X-Publication-Tracker-Export-Count: ' . count($papers));
header('X-Publication-Tracker-Export-Limit: ' . $limit);
header('X-Publication-Tracker-Filename: ' . $filename);
header('X-Publication-Tracker-Site: ' . ExportService::TRACKER_SITE_URL);
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}
echo $content;
