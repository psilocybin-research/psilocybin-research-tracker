#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

$limit = 0;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDERR, "Usage: php bin/repair-metadata.php [--limit=N] [--dry-run]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$http = new HttpClient();
$pubmed = new PubMedFetcher($http);
$crossref = new CrossrefFetcher($http);
$limitSql = $limit > 0 ? ' LIMIT ' . $limit : '';

$pmids = $db->pdo()->query(
    "SELECT DISTINCT pubmed_id
     FROM publications
     WHERE hidden = 0
       AND false_positive = 0
       AND merged_into_id IS NULL
       AND pubmed_id IS NOT NULL
       AND TRIM(pubmed_id) != ''
       AND (TRIM(COALESCE(authors, '')) = ''
         OR TRIM(COALESCE(journal, '')) = ''
         OR TRIM(COALESCE(publication_date, '')) = ''
         OR TRIM(COALESCE(doi, '')) = '')
     ORDER BY id" . $limitSql
)->fetchAll(PDO::FETCH_COLUMN);

$dois = $db->pdo()->query(
    "SELECT DISTINCT doi
     FROM publications
     WHERE hidden = 0
       AND false_positive = 0
       AND merged_into_id IS NULL
       AND doi IS NOT NULL
       AND TRIM(doi) != ''
       AND (TRIM(COALESCE(authors, '')) = ''
         OR TRIM(COALESCE(journal, '')) = ''
         OR TRIM(COALESCE(publication_date, '')) = '')
     ORDER BY id" . $limitSql
)->fetchAll(PDO::FETCH_COLUMN);

$summary = [
    'dry_run' => $dryRun,
    'pubmed_identifiers' => count($pmids),
    'crossref_identifiers' => count($dois),
    'fetched' => 0,
    'inserted' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => 0,
];

$apply = static function (array $papers) use (&$summary, $dryRun, $repo): void {
    foreach ($papers as $paper) {
        $summary['fetched']++;
        if ($dryRun) {
            continue;
        }
        try {
            $result = $repo->enrichMissingMetadataByIdentifier($paper);
            $summary[$result] = ($summary[$result] ?? 0) + 1;
        } catch (Throwable $e) {
            $summary['errors']++;
            OperationalLogger::exception('metadata_repair.upsert_failed', $e, [
                'doi' => $paper['doi'] ?? null,
                'pubmed_id' => $paper['pubmed_id'] ?? null,
            ]);
        }
    }
};

try {
    foreach (array_chunk(array_map('strval', $pmids), 100) as $chunk) {
        $apply($pubmed->fetchByIds($chunk));
    }
    foreach (array_chunk(array_map('strval', $dois), 25) as $chunk) {
        $apply($crossref->fetchByDois($chunk));
    }
} catch (Throwable $e) {
    $summary['errors']++;
    OperationalLogger::exception('metadata_repair.fetch_failed', $e);
}

OperationalLogger::info('metadata_repair.finished', $summary);
echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($summary['errors'] > 0 ? 1 : 0);
