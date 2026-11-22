<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';
RequestWorkload::protect();

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$format = strtolower((string)request_value('format', 'csv'));
$requestedLimit = request_value('limit', 'all');
$limit = strtolower((string)$requestedLimit) === 'all' ? PHP_INT_MAX : max((int)$requestedLimit, 1);
$selectedIds = request_array('ids');
$papers = $selectedIds ? $repo->publicByIds($selectedIds) : $repo->allForExport(RequestFilters::fromGlobals(), $limit);

$content = match ($format) {
    'bundle', 'zip' => ResearchBundle::build($papers, search_filter_query($_GET), $limit, (bool)$selectedIds),
    'json' => ExportService::json($papers),
    'bib', 'bibtex' => ExportService::bibtex($papers),
    'ris' => ExportService::ris($papers),
    'latex', 'tex' => ExportService::latex($papers),
    default => ExportService::csv($papers),
};
$mime = match ($format) {
    'bundle', 'zip' => 'application/zip',
    'json' => 'application/json; charset=utf-8',
    'bib', 'bibtex' => 'application/x-bibtex; charset=utf-8',
    'ris' => 'application/x-research-info-systems; charset=utf-8',
    'latex', 'tex' => 'application/x-tex; charset=utf-8',
    default => 'text/csv; charset=utf-8',
};
$ext = match ($format) {
    'bundle', 'zip' => 'zip',
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
if (in_array($format, ['bundle', 'zip'], true)) header('X-Publication-Tracker-SHA256: ' . hash('sha256', $content));
header('X-Publication-Tracker-Export-Count: ' . count($papers));
header('X-Publication-Tracker-Export-Limit: ' . $limit);
header('X-Publication-Tracker-Filename: ' . $filename);
header('X-Publication-Tracker-Site: ' . ExportService::TRACKER_SITE_URL);
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}
echo $content;
