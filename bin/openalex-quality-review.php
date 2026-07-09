#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

$decision = null;
$openAlexId = null;
$publicationId = null;
$reason = null;
$reviewer = 'manual';
$report = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--report') {
        $report = true;
    } elseif (preg_match('/^--(approve|reject|needs-review)=(.+)$/', $arg, $match)) {
        $decision = $match[1] === 'approve' ? 'approved' : ($match[1] === 'reject' ? 'rejected' : 'needs_review');
        $openAlexId = $match[2];
    } elseif (str_starts_with($arg, '--publication-id=')) {
        $publicationId = max(1, (int)substr($arg, 17));
    } elseif (str_starts_with($arg, '--reason=')) {
        $reason = trim(substr($arg, 9));
    } elseif (str_starts_with($arg, '--reviewer=')) {
        $reviewer = trim(substr($arg, 11)) ?: 'manual';
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDERR, "Usage: php bin/openalex-quality-review.php --report\n");
        fwrite(STDERR, "       php bin/openalex-quality-review.php --approve=W123 [--publication-id=N] [--reason=TEXT] [--reviewer=NAME]\n");
        fwrite(STDERR, "       php bin/openalex-quality-review.php --reject=W123 [--reason=TEXT] [--reviewer=NAME]\n");
        fwrite(STDERR, "       php bin/openalex-quality-review.php --needs-review=W123 [--publication-id=N] [--reason=TEXT] [--reviewer=NAME]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);

if ($report) {
    $summary = $db->pdo()->query(
        'SELECT decision, COUNT(*) count
         FROM openalex_quality_reviews
         GROUP BY decision
         ORDER BY decision'
    )->fetchAll();
    echo json_encode(['summary' => $summary], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if ($decision === null || $openAlexId === null) {
    fwrite(STDERR, "Missing review action. Use --help for usage.\n");
    exit(2);
}

$repo->recordOpenAlexQualityReview($openAlexId, $publicationId, $decision, $reason, $reviewer);
$review = $repo->openAlexReviewDecision($openAlexId);

echo json_encode($review, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
