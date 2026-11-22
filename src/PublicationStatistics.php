<?php
declare(strict_types=1);

/** The same public yearly counts as the research trends table, with unknown years. */
final class PublicationStatistics
{
    public static function summary(Database $db): array
    {
        $dependencies = hash_file('sha256', __FILE__) . hash_file('sha256', __DIR__ . '/ResearchOverview.php') . hash_file('sha256', __DIR__ . '/CatalogueRevision.php');
        $summary = PublicAggregateCache::remember($db, 'publication-statistics', static fn(): array => self::calculate($db), $dependencies);
        // Calendar context must not be frozen in the catalogue-revision aggregate.
        $summary['current_year'] = (int)gmdate('Y');
        foreach ($summary['years'] as &$row) $row['is_current_year'] = $row['year'] === $summary['current_year'];
        unset($row);
        return $summary;
    }

    private static function calculate(Database $db): array
    {
        $pdo = $db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $revision = CatalogueRevision::read($db);
            $overview = ResearchOverview::summarize($db);
            $fields = ['total', 'published', 'preprints', 'trial_registrations', 'other'];
            $convert = static function (array $row) use ($fields): array {
                $row['trial_registrations'] = $row['trials'] ?? $row['trial_registrations'] ?? 0;
                $value = [];
                foreach ($fields as $field) $value[$field] = (int)($row[$field] ?? 0);
                return $value;
            };
            $missing = $convert($overview['undated_statuses']);
            $years = [];
            $totals = $missing;
            foreach ($overview['years'] as $row) {
                $counts = $convert($row);
                $years[] = ['year' => (int)$row['year']] + $counts;
                foreach ($fields as $field) $totals[$field] += $counts[$field];
            }
            if ($totals['total'] !== (int)$overview['totals']['total']) throw new RuntimeException('Yearly counts do not reconcile.');
            foreach (array_merge($years, [$missing, $totals]) as $row) {
                if ($row['total'] !== $row['published'] + $row['preprints'] + $row['trial_registrations'] + $row['other']) throw new RuntimeException('Status counts do not reconcile.');
            }
            $result = [
                'schema_version' => 'publication-statistics-v1',
                'catalogue_revision' => $revision,
                'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'record_count' => $totals['total'],
                'dated_records' => $totals['total'] - $missing['total'],
                'missing_year_records' => $missing['total'],
                'status_totals' => $totals,
                'missing_year' => $missing,
                'years' => $years,
                'unit' => 'indexed records, not independent studies or participants',
                'scope' => 'Visible public records; hidden records and curated false positives are excluded. No search filters are applied.',
                'year_basis' => 'Stored bibliographic or registry year; unknown years are separate and are not inferred from trial completion dates.',
                'status_basis' => 'Site-normalized publication_status; published is not an independent peer-review assessment. Other includes protocols, reviews and residual statuses.',
                'source_url' => rtrim(Config::publicBaseUrl(), '/') . '/research-trends.php',
            ];
            if ($own) $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function csv(array $summary): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) throw new RuntimeException('Cannot create statistics CSV.');
        fputcsv($stream, ['year', 'total', 'published', 'preprints', 'trial_registrations', 'other', 'year_status'], ',', '"', '');
        foreach ($summary['years'] as $row) {
            fputcsv($stream, [$row['year'], $row['total'], $row['published'], $row['preprints'], $row['trial_registrations'], $row['other'], $row['is_current_year'] ? 'current_year_partial' : 'recorded_year'], ',', '"', '');
        }
        $row = $summary['missing_year'];
        fputcsv($stream, ['', $row['total'], $row['published'], $row['preprints'], $row['trial_registrations'], $row['other'], 'missing_year'], ',', '"', '');
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        if ($content === false) throw new RuntimeException('Cannot read statistics CSV.');
        return $content;
    }
}
