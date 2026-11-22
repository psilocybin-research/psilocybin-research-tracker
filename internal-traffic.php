<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
if (InternalTraffic::validLink((int)($_GET['until'] ?? 0), (string)($_GET['key'] ?? ''))) {
    setcookie('tracker_internal', InternalTraffic::cookieValue(), ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: internal-traffic.php?status=excluded', true, 303);
    exit;
}
if (!InternalTraffic::marked($_COOKIE)) { http_response_code(404); exit; }
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Internal browsing excluded</title><style>body{font:17px/1.6 system-ui,sans-serif;max-width:620px;margin:10vh auto;padding:24px;color:#203e30}a{color:#245b3f}</style><h1>This browser is excluded from statistics</h1><p>Your visits, installation events and research-task requests are excluded from the tracker’s visitor and conversion reports. The marker follows this browser across network changes without excluding other people on a shared connection.</p><p>Keep browser cookies enabled. Open the private setup link on each of your other browsers and devices, including the browser used by the installed app.</p><p><a href="/">Return to the database</a></p><script>try{localStorage.setItem('publicationTrackerMetricsExcluded','1');localStorage.removeItem('publicationTrackerPwaMetricQueue')}catch(e){}</script></html>
