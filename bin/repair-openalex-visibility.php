#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

$limit = 5000;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDERR, "Usage: php bin/repair-openalex-visibility.php [--limit=N] [--dry-run]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$result = $repo->restoreOverQuarantinedOpenAlexRows($limit, $dryRun);

if (!$dryRun) {
    try {
        $db->pdo()->exec("INSERT INTO publications_fts(publications_fts) VALUES ('rebuild')");
    } catch (Throwable) {
        // FTS5 is optional; the repository falls back to SQL LIKE search when unavailable.
    }
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
