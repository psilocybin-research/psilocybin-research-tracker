<?php
declare(strict_types=1);

/** A complete, compact public catalogue; deliberately separate from filtered exports. */
final class CatalogueDownload
{
    public const MAX_RECORDS = PHP_INT_MAX - 1;

    public static function snapshot(Database $db): array
    {
        $pdo = $db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $repo = new PublicationRepository($db);
            $filters = ['substances' => ['psilocybin', 'psilocin'], 'sort' => 'oldest'];
            $total = $repo->search($filters + ['per_page' => '5'])['total'];
            if ($total > self::MAX_RECORDS) {
                throw new OverflowException('Use the complete SQLite download for this catalogue size.');
            }
            $papers = $repo->allForExport($filters, self::MAX_RECORDS);
            if (count($papers) !== $total) throw new RuntimeException('Incomplete catalogue snapshot.');
            $snapshot = self::fromPapers($papers, gmdate('Y-m-d\TH:i:s\Z'));
            if ($ownTransaction) $pdo->commit();
            return $snapshot;
        } catch (Throwable $error) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function fromPapers(array $papers, string $generatedAt): array
    {
        $records = array_map(static function (array $paper): array {
            return [
                'id' => (int)$paper['id'],
                'title' => trim(preg_replace('/[\p{Z}\s]+/u', ' ', (string)$paper['title']) ?? (string)$paper['title']),
                'year' => empty($paper['publication_year']) ? null : (int)$paper['publication_year'],
                'publication_status' => $paper['publication_status'] ?? null,
                'source_name' => $paper['source_name'] ?? null,
                'doi' => $paper['doi'] ?: null,
                'source_url' => $paper['source_url'] ?: null,
                'record_url' => ExportService::TRACKER_SITE_URL . '/publication.php?id=' . (int)$paper['id'],
            ];
        }, $papers);
        $snapshot = [
            'schema_version' => 'public-catalogue-v1',
            'site_url' => ExportService::TRACKER_SITE_URL . '/',
            'generated_at_utc' => $generatedAt,
            'record_count' => count($records),
            'total_records' => count($papers),
            'complete' => true,
            'scope' => 'All currently visible psilocybin and psilocin catalogue records, including publications, preprints and trial registrations. Records are not necessarily independent studies; years are recorded bibliographic years, not inferred trial completion years.',
            'missing_year' => 'JSON uses null; TXT uses n.d. No year is inferred.',
            'records' => $records,
        ];
        return $snapshot;
    }

    public static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function text(array $snapshot): string
    {
        $lines = array_map(static fn(array $record): string =>
            ($record['year'] === null ? 'n.d.' : (string)$record['year']) . ' — ' . $record['title'], $snapshot['records']);
        return $lines ? implode("\n", $lines) . "\n" : '';
    }
}
