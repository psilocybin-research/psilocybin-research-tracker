#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

function openalex_backfill_usage(): void
{
    fwrite(STDERR, "Usage: php bin/openalex-deep-backfill.php [--max=N] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--min-score=N] [--query=TERM]... [--dry-run]\n");
}

$max = 15000;
$from = null;
$to = null;
$minimumScore = 3;
$queries = [];
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--max=')) {
        $max = max(1, (int)substr($arg, 6));
    } elseif (str_starts_with($arg, '--from=')) {
        $from = parse_date_or_null(substr($arg, 7));
    } elseif (str_starts_with($arg, '--to=')) {
        $to = parse_date_or_null(substr($arg, 5));
    } elseif (str_starts_with($arg, '--min-score=')) {
        $minimumScore = max(1, (int)substr($arg, 12));
    } elseif (str_starts_with($arg, '--query=')) {
        $query = trim(substr($arg, 8));
        if ($query !== '') {
            $queries[] = $query;
        }
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        openalex_backfill_usage();
        exit(0);
    } else {
        openalex_backfill_usage();
        exit(2);
    }
}

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$runs = new FetchRunRepository($db);
$fetcher = new OpenAlexFetcher(new HttpClient());
$runId = $runs->start('OpenAlex deep backfill');
$inserted = 0;
$updated = 0;
$skipped = 0;
$errors = 0;
$startedAt = current_utc();
$context = [
    'max' => $max,
    'from' => $from,
    'to' => $to,
    'min_score' => $minimumScore,
    'queries' => $queries,
    'dry_run' => $dryRun,
];

Heartbeat::beat('openalex-deep-backfill', 'running', $context);
OperationalLogger::info('openalex_deep_backfill.started', $context);

try {
    $papers = $fetcher->fetchQueries($queries, $from, $to, $max, $minimumScore);
    foreach ($papers as $paper) {
        try {
            if ($dryRun) {
                $skipped++;
                continue;
            }
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
            $runs->error($runId, 'OpenAlex deep backfill', $e->getMessage() . ' [' . mb_substr((string)($paper['title'] ?? ''), 0, 160) . ']');
        }
    }
    $message = ($dryRun ? 'Dry run completed' : 'Deep backfill completed') . '; candidates=' . count($papers);
    $runs->finish($runId, $errors ? 'partial' : 'ok', $inserted, $updated, $skipped, $errors, $message);
} catch (Throwable $e) {
    $errors++;
    $runs->error($runId, 'OpenAlex deep backfill', $e->getMessage());
    $runs->finish($runId, 'error', $inserted, $updated, $skipped, $errors, $e->getMessage());
    $failure = [
        'ok' => false,
        'started_at' => $startedAt,
        'dry_run' => $dryRun,
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $errors,
        'max' => $max,
        'min_score' => $minimumScore,
        'message' => $e->getMessage(),
        'hint' => str_contains($e->getMessage(), '429') ? 'OpenAlex rate limit reached. Configure PUBLICATION_TRACKER_OPENALEX_API_KEY and retry later with smaller batches.' : null,
    ];
    Heartbeat::beat('openalex-deep-backfill', 'fail', $failure + $context);
    OperationalLogger::exception('openalex_deep_backfill.failed', $e, $context);
    fwrite(STDERR, json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$summary = [
    'ok' => $errors === 0,
    'started_at' => $startedAt,
    'dry_run' => $dryRun,
    'inserted' => $inserted,
    'updated' => $updated,
    'skipped' => $skipped,
    'errors' => $errors,
    'max' => $max,
    'min_score' => $minimumScore,
];
Heartbeat::beat('openalex-deep-backfill', $errors ? 'fail' : 'ok', $summary);
OperationalLogger::info('openalex_deep_backfill.finished', $summary);
echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($errors > 0 ? 1 : 0);
