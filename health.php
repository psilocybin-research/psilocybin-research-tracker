<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$format = request_value('format', 'json');

try {
    $db = new Database();
    $db->initialize();
    $report = (new HealthService($db, new PublicationRepository($db), new FetchRunRepository($db)))->report();
    $httpCode = $report['status'] === 'fail' ? 503 : 200;
    http_response_code($httpCode);
    if ($format === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Publication Tracker Health</title>
  <style>
    body{margin:0;background:#ffffff;color:#24251f;font-family:Arial,Helvetica,sans-serif}
    main{max-width:980px;margin:32px auto;padding:0 18px}
    h1{margin:0 0 6px;font-size:28px}
    .status{display:inline-block;margin:8px 0 20px;padding:6px 10px;border-radius:4px;font-weight:800;text-transform:uppercase}
    .ok{background:#e8efe7;color:#123c31}.warn{background:#fbf2df;color:#8a5e14}.fail{background:#fbebe7;color:#8f3328}
    section{margin:12px 0;background:#fff;border:1px solid #d8d2c4;border-left:4px solid #123c31;border-radius:8px;overflow:hidden}
    h2{margin:0;padding:12px 14px;border-bottom:1px solid #d8d2c4;background:#f7f6f1;font-size:16px}
    pre{margin:0;padding:14px;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.45}
  </style>
</head>
<body><main>
  <h1>Publication Tracker Health</h1>
  <div>Generated <?= h((string)$report['generated_at']) ?> UTC</div>
  <span class="status <?= h((string)$report['status']) ?>"><?= h((string)$report['status']) ?></span>
  <?php foreach ($report['checks'] as $name => $check): ?>
    <section>
      <h2><?= h((string)$name) ?> · <span class="<?= h((string)$check['status']) ?>"><?= h((string)$check['status']) ?></span></h2>
      <pre><?= h(json_encode($check, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </section>
  <?php endforeach; ?>
</main></body></html>
        <?php
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    OperationalLogger::exception('health.failed', $e);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => false,
        'status' => 'fail',
        'generated_at' => current_utc(),
        'message' => 'Health check failed.',
    ], JSON_UNESCAPED_SLASHES);
}
