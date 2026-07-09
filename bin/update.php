#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

$db = new Database();
$db->initialize();

$options = UpdateOptions::parse($argv);
$from = $options['from'];
$to = $options['to'];
$limit = $options['limit'];
$mode = $options['mode'];
$sources = $options['sources'];
$sendAlerts = in_array('--send-alerts', $argv, true);
$sendPush = $mode === 'daily' || in_array('--send-push', $argv, true);
$startedAt = current_utc();

$context = ['from' => $from, 'to' => $to, 'limit' => $limit, 'sources' => $sources, 'send_alerts' => $sendAlerts, 'send_push' => $sendPush];
Heartbeat::beat('update-' . $mode, 'running', $context);
OperationalLogger::info('update.started', ['mode' => $mode] + $context);

try {
    $summary = PublicationService::create($db)->refresh($from, $to, $limit, $sources);
} catch (Throwable $e) {
    Heartbeat::beat('update-' . $mode, 'fail', ['message' => $e->getMessage()] + $context);
    OperationalLogger::exception('update.failed', $e, ['mode' => $mode] + $context);
    throw $e;
}

echo 'Mode: ' . $mode . ($from ? ' From: ' . $from : ' From: all') . ($to ? ' To: ' . $to : '') . "\n";
if ($sources) {
    echo 'Sources: ' . implode(', ', $sources) . "\n";
}
echo implode("\n", $summary['messages']) . "\n";
echo 'Inserted: ' . $summary['inserted'] . ' Updated: ' . $summary['updated'] . ' Skipped: ' . $summary['skipped'] . ' Errors: ' . $summary['errors'] . "\n";
$updateStatus = $summary['errors'] > 0 ? 'fail' : 'ok';
Heartbeat::beat('update-' . $mode, $updateStatus, [
    'started_at' => $startedAt,
    'inserted' => $summary['inserted'],
    'updated' => $summary['updated'],
    'skipped' => $summary['skipped'],
    'errors' => $summary['errors'],
    'sources' => $sources,
]);
OperationalLogger::info('update.finished', ['mode' => $mode, 'status' => $updateStatus, 'inserted' => $summary['inserted'], 'updated' => $summary['updated'], 'skipped' => $summary['skipped'], 'errors' => $summary['errors'], 'sources' => $sources]);
if ($sendPush && $summary['errors'] === 0 && $summary['inserted'] > 0) {
    $push = new PushService($db, new PublicationRepository($db));
    $pushSummary = $push->notifyNewPublications($startedAt);
    echo 'Push subscriptions: ' . $pushSummary['subscriptions'] . ' Sent: ' . $pushSummary['sent'] . ' Failed: ' . $pushSummary['failed'] . ' Expired: ' . $pushSummary['expired'] . "\n";
    foreach ($pushSummary['messages'] as $message) {
        echo $message . "\n";
    }
    Heartbeat::beat('push-latest', $pushSummary['failed'] > 0 ? 'fail' : 'ok', $pushSummary);
    OperationalLogger::info('push.finished', $pushSummary);
}
if ($sendAlerts && $summary['errors'] === 0) {
    $alerts = new AlertService($db, new PublicationRepository($db));
    $alertSummary = $alerts->deliverDue('daily');
    echo 'Alerts generated: ' . $alertSummary['generated'] . ' Sent: ' . $alertSummary['sent'] . ' Failed: ' . $alertSummary['failed'] . "\n";
    foreach ($alertSummary['messages'] as $message) {
        echo $message . "\n";
    }
    Heartbeat::beat('alerts-daily', $alertSummary['failed'] > 0 ? 'fail' : 'ok', $alertSummary);
    OperationalLogger::info('alerts.finished', $alertSummary);
    if ($alertSummary['failed'] > 0) {
        exit(1);
    }
}
exit($summary['errors'] > 0 ? 1 : 0);
