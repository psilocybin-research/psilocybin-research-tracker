#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

$limit = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int)substr($arg, 8));
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDERR, "Usage: php bin/rebuild-normalized-indexes.php [--limit=N]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

$db = new Database();
$db->initialize();
$result = (new PublicationRepository($db))->backfillNormalizedMetadata($limit);

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
