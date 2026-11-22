<?php
declare(strict_types=1);
final class MscaStatistics
{
    public static function yearDetails(Database $db): array
    {
        return PublicAggregateCache::remember($db,'msca-year-details',static fn(): array => $db->pdo()->query("SELECT p.publication_year AS year,
            COUNT(DISTINCT p.id) AS publications,
            COUNT(DISTINCT NULLIF(TRIM(p.journal), '')) AS journals,
            COUNT(DISTINCT COALESCE(NULLIF(TRIM(pa.author_key), ''), NULLIF(LOWER(TRIM(pa.author_name)), ''))) AS authors
            FROM publications p LEFT JOIN publication_authors pa ON pa.publication_id=p.id
            WHERE p.publication_year IS NOT NULL AND p.hidden=0 AND p.false_positive=0
            GROUP BY p.publication_year")->fetchAll(),(string)hash_file('sha256',__FILE__));
    }
}
