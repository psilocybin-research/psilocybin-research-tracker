<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';

$assetVersion = '20261105-visitor-calendar-map-v2';
$baseUrl = Config::publicBaseUrl();
$visitorStatistics = new VisitorStatisticsService();
$stats = $visitorStatistics->summary();
$activity = !empty($stats['available']) ? $visitorStatistics->currentActivity() : ['online' => 0, 'active_sessions' => 0, 'page_views' => 0];
$pwaMetrics = [];
$engagement = ['installs' => 0, 'standalone_launches' => 0, 'push' => 0, 'email' => 0];
try {
    $engagementDb = new Database();
    $engagementDb->initialize();
    $pdo = $engagementDb->pdo();
    $engagement['installs'] = (int)$pdo->query("SELECT COALESCE(SUM(count), 0) FROM engagement_daily_metrics WHERE metric = 'pwa_install'")->fetchColumn();
    $pwaMetrics = $pdo->query("SELECT metric, SUM(count) AS total FROM engagement_daily_metrics WHERE metric_date >= date('now', '-30 days') GROUP BY metric")->fetchAll(PDO::FETCH_KEY_PAIR);
    $engagement['standalone_launches'] = (int)$pdo->query("SELECT COALESCE(SUM(count), 0) FROM engagement_daily_metrics WHERE metric = 'standalone_launch' AND metric_date >= date('now', '-30 days')")->fetchColumn();
    $engagement['push'] = (int)$pdo->query('SELECT COUNT(*) FROM push_subscriptions WHERE active = 1')->fetchColumn();
    $engagement['email'] = (int)$pdo->query('SELECT COUNT(*) FROM alert_subscriptions WHERE active = 1 AND confirmed_at IS NOT NULL')->fetchColumn();
} catch (Throwable $e) {
    OperationalLogger::exception('visitor_statistics.engagement_failed', $e);
}
$calendar = (array)($stats['calendar'] ?? []);
$calendarMonths = (array)($calendar['months'] ?? []);
$selectedFrom = $selectedTo = $calendarMonths ? end($calendarMonths) : '';
$rangeError = '';
if ($calendarMonths) {
    $from = $_GET['from'] ?? $selectedFrom; $to = $_GET['to'] ?? $from;
    if (!is_string($from) || !is_string($to) || !in_array($from, $calendarMonths, true) || !in_array($to, $calendarMonths, true) || $from > $to) {
        $rangeError = 'Choose an available start month and an end month on or after it.';
    } else { $selectedFrom = $from; $selectedTo = $to; }
}
$selectedRange = $calendar['ranges'][$selectedFrom . ':' . $selectedTo] ?? null;
$monthName = static fn(string $m): string => gmdate('F Y', strtotime($m . '-01 UTC'));
$rangeLabel = $selectedRange ? ($selectedFrom === $selectedTo ? $monthName($selectedFrom) : $monthName($selectedFrom) . ' – ' . $monthName($selectedTo)) : 'Last 30 days';
if ($selectedRange) {
    foreach (['countries', 'devices', 'browsers', 'pages', 'referrers'] as $key) $stats[$key] = $selectedRange[$key];
    $stats['daily'] = array_values(array_filter($calendar['daily'], static fn(array $d): bool => substr($d['date_utc'], 0, 7) >= $selectedFrom && substr($d['date_utc'], 0, 7) <= $selectedTo));
}
$periods = $stats['periods'] ?? [];
$number = static fn($value): string => number_format((int)$value);
$metricGroups = [];
foreach (['7', '30', '90', 'all'] as $window) {
    $period = $periods[$window] ?? [];
    if ($period === []) continue;
    $signature = implode(':', array_map(static fn(string $key): int => (int)($period[$key] ?? 0), ['visitors', 'sessions', 'page_views']));
    if (!isset($metricGroups[$signature])) $metricGroups[$signature] = ['windows' => [], 'period' => $period];
    $metricGroups[$signature]['windows'][] = $window;
}
$metricGroups = array_values($metricGroups);
foreach ($metricGroups as &$group) {
    $rolling = array_values(array_diff($group['windows'], ['all']));
    $group['label'] = in_array('all', $group['windows'], true) ? 'Recorded reach' : 'Last ' . implode(' / ', $rolling) . ' days';
    $group['rolling_label'] = $rolling ? 'Last ' . implode(' / ', $rolling) . ' days' : '';
}
unset($group);

$chartData = [
    'daily' => array_values((array)($stats['daily'] ?? [])),
    'period_groups' => array_map(static fn(array $group): array => ['label' => $group['label'] . (in_array('all', $group['windows'], true) && $group['rolling_label'] !== '' ? ' · ' . $group['rolling_label'] : '')] + array_intersect_key($group['period'], array_flip(['visitors', 'sessions', 'page_views'])), $metricGroups),
    'periods' => array_intersect_key((array)$periods, array_flip(['7', '30', '90', 'all'])),
    'countries' => array_map(static fn(array $row): array => ['name' => (string)($row['name'] ?? ''), 'count' => (int)($row['count'] ?? 0)], (array)($stats['countries'] ?? [])),
    'devices' => array_map(static fn(array $row): array => ['name' => (string)($row['name'] ?? ''), 'count' => (int)($row['count'] ?? 0)], (array)($stats['devices'] ?? [])),
    'browsers' => array_map(static fn(array $row): array => ['name' => (string)($row['name'] ?? ''), 'count' => (int)($row['count'] ?? 0)], (array)($stats['browsers'] ?? [])),
    'pages' => array_map(static fn(array $row): array => ['name' => (string)($row['name'] ?? ''), 'count' => (int)($row['count'] ?? 0)], (array)($stats['pages'] ?? [])),
];
if ($selectedRange) {
    $chartData['daily'] = $stats['daily'];
    $chartData['period_groups'] = [];
    foreach ($calendarMonths as $month) {
        if ($month < $selectedFrom || $month > $selectedTo) continue;
        $monthly = $calendar['ranges'][$month . ':' . $month];
        $chartData['period_groups'][] = ['label' => gmdate('M Y', strtotime($month . '-01 UTC'))] + ($monthly['covered_dates'] ? $monthly['totals'] : array_fill_keys(['visitors', 'sessions', 'page_views'], null));
    }
    $chartData['periods']['30'] = $selectedRange['totals'];
}
$rangeConfig = ['months' => $calendarMonths, 'from' => $selectedFrom, 'to' => $selectedTo, 'label' => $rangeLabel, 'calendar' => $selectedRange !== null];
$publicRange = ['chart' => $chartData, 'range' => $rangeConfig, 'totals' => $selectedRange['totals'] ?? ($periods['30'] ?? []), 'covered_dates' => $selectedRange['covered_dates'] ?? ($periods['30']['covered_calendar_dates'] ?? 0), 'referrers' => array_values($stats['referrers'] ?? []), 'generated_at' => $stats['generated_at'] ?? null];
if ($selectedRange && !$selectedRange['covered_dates']) $publicRange['totals'] = array_fill_keys(['visitors', 'sessions', 'page_views'], null);
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    if ($rangeError !== '') { http_response_code(400); echo json_encode(['error' => $rangeError]); }
    else echo json_encode($publicRange, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#123c31"><meta name="color-scheme" content="light">
  <title>Visitor statistics | Psilocybin Research</title>
  <meta name="description" content="Privacy-conscious, aggregated visitor statistics for Psilocybin-Research.com.">
  <link rel="canonical" href="<?= h($baseUrl) ?>visitor-statistics.php"><meta name="robots" content="noindex,follow">
  <link rel="icon" href="assets/logo.png"><link rel="manifest" href="manifest.webmanifest">
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <link rel="stylesheet" href="assets/visitor-dashboard.min.css?v=<?= h($assetVersion) ?>">
</head>
<body class="detail-page visitor-statistics-page">
<?= detail_preloader('Preparing visitor statistics…', 'Preparing visitor statistics') ?>
<?= detail_page_header('') ?>
<main class="detail-main visitor-dashboard" id="main-content">
  <header class="detail-hero visitor-hero">
    <p class="eyebrow">Aggregate traffic overview</p>
    <h1>Visitor statistics</h1>
  </header>
<?php if (empty($stats['available'])): ?>
  <section class="detail-section"><p><?= h((string)($stats['message'] ?? 'Visitor aggregates are unavailable.')) ?></p></section>
<?php else: ?>
<?php $rawTraffic = (array)($stats['raw_traffic'] ?? []); if ($rawTraffic !== []): ?>
  <section aria-labelledby="server-traffic-title">
    <h2 id="server-traffic-title">Total recorded server traffic</h2>
    <p><?= (int)($rawTraffic['covered_calendar_dates'] ?? 0) ?> recorded dates · <?= h((string)($rawTraffic['first_date'] ?? '')) ?> and <?= h((string)($rawTraffic['last_date'] ?? '')) ?> UTC.</p>
    <div class="visitor-kpis">
      <article><span>Recorded server requests</span><strong><?= $number($rawTraffic['requests'] ?? 0) ?></strong></article>
      <article><span>Distinct requesting IP addresses</span><strong><?= $number($rawTraffic['requesting_addresses'] ?? 0) ?></strong></article>
    </div>
  </section>
<?php endif; ?>
  <section class="visitor-live-map-card" aria-labelledby="visitor-live-title">
    <div class="visitor-live-header"><div><p class="eyebrow"><span class="visitor-live-dot" aria-hidden="true"></span> Live audience</p><h2 id="visitor-live-title">Around the world, right now</h2><p>Distinct browser addresses active in the last 5 minutes. Approximate country attribution.</p></div><div class="visitor-live-total"><strong id="visitor-live-total"><?= empty($activity['available']) ? '—' : $number($activity['online']) ?></strong><span>online now</span></div></div>
    <div class="visitor-geography"><div class="visitor-world-wrap"><div id="visitor-world-map" aria-label="Countries with visitors active in the last five minutes"><p>Country counts are listed alongside the map.</p></div><p id="visitor-map-detail" class="visitor-map-detail">Choose a highlighted country to see its live count.</p><div class="visitor-map-legend"><span><i></i>No attributed activity</span><span><i></i>Active country</span></div></div><aside class="visitor-live-countries"><h3>Online by country</h3><ol id="visitor-live-countries"><?php foreach ($activity['countries'] ?? [] as $row): ?><li><span><?= h($row['name']) ?></span><strong><?= $number($row['count']) ?></strong></li><?php endforeach; ?></ol><p id="visitor-live-empty"<?= !empty($activity['countries']) ? ' hidden' : '' ?>>No qualifying activity in this five-minute window.</p></aside></div>
    <div class="visitor-live-footer"><span id="visitor-live-updated" role="status">Live reading · refreshes every 30 seconds</span><span><b id="visitor-active-count"><?= $number($activity['active_sessions']) ?></b> active in 30 min · <b id="visitor-pageview-count"><?= $number($activity['page_views']) ?></b> requests in 5 min</span><button type="button" id="visitor-live-refresh">Refresh now</button></div>
  </section>
  <script type="application/json" id="visitor-live-data"><?= json_encode(array_intersect_key($activity, array_flip(['available', 'online', 'active_sessions', 'page_views', 'countries'])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
  <section class="visitor-period-panel" aria-labelledby="visitor-period-title">
    <h2 id="visitor-period-title" class="sr-only">Reporting period</h2>
    <?php if ($calendarMonths): ?>
    <form id="visitor-period-form" method="get" action="visitor-statistics.php">
      <div class="visitor-month-fields"><label for="visitor-from">From month<input type="month" id="visitor-from" name="from" min="<?= h($calendarMonths[0]) ?>" max="<?= h(end($calendarMonths)) ?>" value="<?= h($selectedFrom) ?>" required></label><span aria-hidden="true">→</span><label for="visitor-to">Through month<input type="month" id="visitor-to" name="to" min="<?= h($selectedFrom) ?>" max="<?= h(end($calendarMonths)) ?>" value="<?= h($selectedTo) ?>" required></label><button type="submit">Apply period</button></div>
      <div class="visitor-period-presets" role="group" aria-label="Quick reporting periods"><button type="button" data-visitor-period="latest">Latest month</button><button type="button" data-visitor-period="previous"<?= count($calendarMonths) < 2 ? ' disabled' : '' ?>>Previous month</button><button type="button" data-visitor-period="three">Last 3 months</button><button type="button" data-visitor-period="all">All available</button></div>
    </form>
    <?php endif; ?>
    <p class="visitor-period-status" id="visitor-period-status" role="status"><?= h($rangeError ?: $rangeLabel) ?> · <?= (int)$publicRange['covered_dates'] ?> recorded UTC dates.</p>
    <div class="visitor-range-kpis"><?php foreach (['visitors' => 'Distinct browser addresses', 'sessions' => 'Sessions', 'page_views' => 'Page views'] as $key => $label): ?><article><span><?= h($label) ?></span><strong data-visitor-total="<?= h($key) ?>"><?= ($publicRange['totals'][$key] ?? null) === null ? '—' : $number($publicRange['totals'][$key]) ?></strong></article><?php endforeach; ?></div>
  </section>
  <script type="application/json" id="visitor-range-config"><?= json_encode($rangeConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
  <section class="visitor-chart-card" aria-labelledby="visitor-chart-title">
    <div class="visitor-chart-heading">
      <div><p class="eyebrow">Interactive overview</p><h2 id="visitor-chart-title">Countries · <?= h($rangeLabel) ?></h2></div>
      <div class="visitor-chart-controls" role="group" aria-label="Choose visitor chart">
        <button type="button" class="is-active" data-visitor-chart-view="countries" aria-pressed="true">Countries</button>
        <button type="button" data-visitor-chart-view="reach" aria-pressed="false">Reach</button>
        <button type="button" data-visitor-chart-view="daily" aria-pressed="false">Daily</button>
        <button type="button" data-visitor-chart-view="devices" aria-pressed="false">Devices</button>
        <button type="button" data-visitor-chart-view="browsers" aria-pressed="false">Browsers</button>
        <button type="button" data-visitor-chart-view="pages" aria-pressed="false">Pages</button>
      </div>
    </div>
    <p class="visitor-chart-note" id="visitor-chart-note">UTC calendar dates.</p>
    <div class="visitor-chart-stage">
      <canvas id="visitor-insights-chart" role="img" aria-label="Interactive aggregate visitor statistics" hidden></canvas>
      <div id="visitor-chart-fallback" aria-label="Daily distinct visitors">
<?php foreach ($stats['daily'] as $day): ?><p><?= h($day['date']) ?>: <strong><?= ($day['recorded'] ?? true) ? $number($day['visitors']) : 'Logs unavailable' ?></strong></p><?php endforeach; ?>
      </div>
    </div>
    <details class="visitor-chart-data-details"><summary>View chart data</summary><div class="visitor-table-scroll"><table id="visitor-data-table">
      <caption>Daily distinct visitors · <?= h($rangeLabel) ?></caption>
      <thead><tr><th scope="col">Date</th><th scope="col">Distinct visitors</th></tr></thead>
      <tbody><?php foreach ($stats['daily'] as $day): ?><tr><th scope="row"><?= h($day['date']) ?></th><td><?= ($day['recorded'] ?? true) ? $number($day['visitors']) : 'Logs unavailable' ?></td></tr><?php endforeach; ?></tbody>
    </table></div></details>
    <p class="visitor-chart-meta">Updated <?= h(format_utc_display((string)($stats['generated_at'] ?? ''))) ?></p>
  </section>
  <script type="application/json" id="visitor-chart-data"><?= json_encode($chartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
  <section class="visitor-grid" aria-label="Selected period breakdowns">
<?php foreach (['countries' => 'Countries', 'devices' => 'Devices', 'browsers' => 'Browsers', 'pages' => 'Popular pages', 'referrers' => 'External referrers'] as $key => $heading): ?>
    <article class="visitor-card"><h2><?= h($heading) ?></h2><p class="visitor-breakdown-period"><?= h($rangeLabel) ?></p><ol class="visitor-list" data-visitor-breakdown="<?= h($key) ?>"><?php foreach ($stats[$key] as $row): ?><li><span><b><?= h($row['name']) ?></b></span><strong><?= $number($row['count']) ?></strong></li><?php endforeach; ?><?php if (!$stats[$key]): ?><li><span>No recorded data for this period</span></li><?php endif; ?></ol></article>
<?php endforeach; ?>
  </section>
  <details class="visitor-method"><summary>How these figures are calculated</summary><p>Sessions use a 30-minute inactivity gap. Reporting windows use UTC calendar dates. Historical figures update every six hours. Calendar selection covers up to 24 months of retained logs; missing or rotated logs cannot be reconstructed. Browser addresses are an approximation, not a count of individual people. Countries are inferred locally; unclassified addresses remain included in totals.</p></details>

<?php endif; ?>

</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
<script src="<?= h(FrontendAssets::url('assets/vendor/chart.umd.min.js')) ?>" defer></script>
<script src="assets/visitor-live.min.js?v=<?= h($assetVersion) ?>" defer></script>
<script src="assets/visitor-statistics.min.js?v=<?= h($assetVersion) ?>" defer></script>
<script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</body></html>
