#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

$file = $argv[1] ?? null;
if (!$file || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/import-pubmed-ids.php /path/to/pmids.txt\n");
    exit(2);
}

$ids = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$ids) {
    echo "No PubMed IDs to import\n";
    exit(0);
}

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$fetcher = new PubMedFetcher(new HttpClient());

$inserted = 0;
$updated = 0;
$skipped = 0;
$errors = 0;
foreach ($fetcher->fetchByIds($ids) as $paper) {
    try {
        $result = $repo->upsert($paper);
        if ($result === 'inserted') {
            $inserted++;
        } elseif ($result === 'updated') {
            $updated++;
        } else {
            $skipped++;
        }
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, $e->getMessage() . ' [' . ($paper['pubmed_id'] ?? 'unknown') . "]\n");
    }
}

echo "PubMed IDs processed: " . count($ids) . "\n";
echo "Inserted: $inserted Updated: $updated Skipped: $skipped Errors: $errors\n";
exit($errors > 0 ? 1 : 0);
