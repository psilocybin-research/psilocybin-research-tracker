#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

$db = new Database();
$db->initialize();
$publications = new PublicationRepository($db);
$alerts = new AlertService($db, $publications);

$frequency = 'daily';
$mark = true;
$format = 'text';
$send = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--frequency=')) {
        $frequency = substr($arg, 12);
    } elseif ($arg === '--preview') {
        $mark = false;
    } elseif ($arg === '--send') {
        $send = true;
        $mark = false;
    } elseif ($arg === '--html') {
        $format = 'html';
    } elseif ($arg === '--mime') {
        $format = 'mime';
    }
}

if ($send) {
    Heartbeat::beat('alerts-' . $frequency, 'running', ['frequency' => $frequency]);
    OperationalLogger::info('alerts.started', ['frequency' => $frequency, 'send' => true]);
    $summary = $alerts->deliverDue($frequency);
    echo 'Generated: ' . $summary['generated'] . ' Sent: ' . $summary['sent'] . ' Failed: ' . $summary['failed'] . "\n";
    foreach ($summary['messages'] as $message) {
        echo $message . "\n";
    }
    Heartbeat::beat('alerts-' . $frequency, $summary['failed'] > 0 ? 'fail' : 'ok', $summary);
    OperationalLogger::info('alerts.finished', $summary);
    exit($summary['failed'] > 0 ? 1 : 0);
}

$digestCount = 0;
foreach ($alerts->generateDue($frequency, $mark) as $digest) {
    $digestCount++;
    echo "----- BEGIN DIGEST -----\n";
    if ($format === 'mime') {
        echo $alerts->renderMimeMessage($digest) . "\n";
    } elseif ($format === 'html') {
        echo $digest['html'] . "\n";
    } else {
        echo $digest['body'] . "\n";
    }
    echo "----- END DIGEST -----\n\n";
}
OperationalLogger::info('alerts.previewed', ['frequency' => $frequency, 'format' => $format, 'mark' => $mark, 'digests' => $digestCount]);
