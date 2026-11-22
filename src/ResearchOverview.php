<?php
declare(strict_types=1);

/** Aggregate bibliographic facts only; never load source text or importer payloads. */
final class ResearchOverview
{
    public static function summarize(Database $db): array
    {
        return PublicAggregateCache::remember($db, 'research-overview',
            static fn(): array => self::calculate($db), (string)hash_file('sha256', __FILE__));
    }

    private static function calculate(Database $db): array
    {
        $pdo = $db->pdo();
        $visible = 'hidden = 0 AND false_positive = 0';
        $totals = $pdo->query("SELECT COUNT(*) total,
            MIN(publication_year) first_year, MAX(publication_year) last_year,
            SUM(CASE WHEN publication_year IS NULL THEN 1 ELSE 0 END) undated,
            SUM(CASE WHEN doi IS NOT NULL AND TRIM(doi) != '' THEN 1 ELSE 0 END) with_doi,
            SUM(CASE WHEN pubmed_id IS NOT NULL AND TRIM(pubmed_id) != '' THEN 1 ELSE 0 END) with_pmid
            FROM publications WHERE $visible")->fetch();
        foreach (['total', 'undated', 'with_doi', 'with_pmid'] as $key) $totals[$key] = (int)$totals[$key];
        $years = $pdo->query("SELECT publication_year year, COUNT(*) total,
            SUM(CASE WHEN publication_status = 'published' THEN 1 ELSE 0 END) published,
            SUM(CASE WHEN publication_status = 'preprint' THEN 1 ELSE 0 END) preprints,
            SUM(CASE WHEN publication_status = 'clinical trial' THEN 1 ELSE 0 END) trials,
            SUM(CASE WHEN publication_status NOT IN ('published', 'preprint', 'clinical trial') OR publication_status IS NULL THEN 1 ELSE 0 END) other
            FROM publications WHERE $visible AND publication_year IS NOT NULL
            GROUP BY publication_year ORDER BY publication_year DESC")->fetchAll();
        $repo = new PublicationRepository($db);
        $undatedStatuses = $pdo->query("SELECT COUNT(*) total,
            SUM(CASE WHEN publication_status = 'published' THEN 1 ELSE 0 END) published,
            SUM(CASE WHEN publication_status = 'preprint' THEN 1 ELSE 0 END) preprints,
            SUM(CASE WHEN publication_status = 'clinical trial' THEN 1 ELSE 0 END) trials,
            SUM(CASE WHEN publication_status NOT IN ('published', 'preprint', 'clinical trial') OR publication_status IS NULL THEN 1 ELSE 0 END) other
            FROM publications WHERE $visible AND publication_year IS NULL")->fetch();
        return ['totals' => $totals, 'years' => $years, 'sources' => $repo->sources(),
            'statuses' => $repo->publicationStatuses(), 'study_types' => $repo->studyTypes(), 'undated_statuses' => $undatedStatuses];
    }
}
