-- One row per recorded year, then an explicit missing-year row (even if zero).
-- Counts are catalogue records, not independent studies or participants.
-- "published" is a stored status, not independent peer-review verification.
SELECT publication_year AS year,
       COUNT(*) AS total,
       COALESCE(SUM(publication_status = 'published'), 0) AS published,
       COALESCE(SUM(publication_status = 'preprint'), 0) AS preprints,
       COALESCE(SUM(publication_status = 'clinical trial'), 0) AS trial_registrations,
       COALESCE(SUM(publication_status NOT IN ('published','preprint','clinical trial')
                    OR publication_status IS NULL), 0) AS other
FROM publications
WHERE publication_year IS NOT NULL
GROUP BY publication_year
UNION ALL
SELECT NULL, COUNT(*),
       COALESCE(SUM(publication_status = 'published'), 0),
       COALESCE(SUM(publication_status = 'preprint'), 0),
       COALESCE(SUM(publication_status = 'clinical trial'), 0),
       COALESCE(SUM(publication_status NOT IN ('published','preprint','clinical trial')
                    OR publication_status IS NULL), 0)
FROM publications
WHERE publication_year IS NULL
ORDER BY year DESC;
