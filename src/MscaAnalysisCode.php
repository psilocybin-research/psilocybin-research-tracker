<?php
declare(strict_types=1);

/** Self-contained R exports using the same public annual counts as the MSCA models. */
final class MscaAnalysisCode
{
    public const ANALYSES = [
        'linear' => ['title' => 'Linear publication growth since 2020', 'file' => 'psilocybin-linear-growth.R'],
        'exponential' => ['title' => 'Log-linear publication growth since 2010', 'file' => 'psilocybin-exponential-growth.R'],
        'breaks' => ['title' => 'Bai-Perron structural-break analysis', 'file' => 'psilocybin-bai-perron-structural-breaks.R'],
        'inference' => ['title' => 'Bai-Perron formal structural-change tests', 'file' => 'psilocybin-bai-perron-tests.R'],
    ];

    public static function rows(Database $db): array
    {
        return PublicationTrendInference::rows($db->pdo()->query('SELECT publication_year AS year, COUNT(*) AS count FROM publications WHERE publication_year IS NOT NULL AND hidden = 0 AND false_positive = 0 GROUP BY publication_year ORDER BY publication_year')->fetchAll());
    }

    public static function csv(array $rows): string
    {
        $csv = "year,count\n";
        foreach (PublicationTrendInference::rows($rows) as $row) $csv .= $row['year'] . ',' . $row['count'] . "\n";
        return $csv;
    }

    public static function script(string $analysis, array $rows): string
    {
        if (!isset(self::ANALYSES[$analysis])) throw new InvalidArgumentException('Unknown analysis');
        $rows = PublicationTrendInference::rows($rows);
        if (!$rows) throw new RuntimeException('No complete-year counts available');
        $dir = __DIR__ . '/../assets/msca-impact/r-scripts/';
        $common = file_get_contents($dir . 'common.R');
        $body = file_get_contents($dir . $analysis . '.R');
        if ($common === false || $body === false) throw new RuntimeException('Analysis source unavailable');
        if ($analysis === 'breaks') {
            $body .= "\nbreakpoint_result <- analysis_result\n\n# Formal structural-change tests on the same annual-count snapshot.\n" . file_get_contents($dir . 'inference.R') . "\nformal_test_result <- analysis_result\nanalysis_result <- breakpoint_result\n";
        }
        $base = 'https://ukri.psilocybin-research.com';
        $quote = static fn(string $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $csvLines = array_map($quote, explode("\n", rtrim(self::csv($rows), "\n")));
        $csvExpression = "paste(c(\n  " . implode(",\n  ", array_map(static fn(array $chunk): string => implode(', ', $chunk), array_chunk($csvLines, 6))) . "\n), collapse = \"\\n\")";
        return strtr($common, [
            '__TITLE__' => self::ANALYSES[$analysis]['title'], '__FILENAME__' => self::ANALYSES[$analysis]['file'],
            '__SOURCE_URL__' => $base . '/msca-impact/',
            '__INPUT_HASH__' => PublicationTrendInference::inputHash($rows),
            '__LAST_YEAR__' => (string)max(array_column($rows, 'year')),
            '__COUNTS_CSV__' => $csvExpression,
            '__LIVE_CSV_URL__' => $quote($base . '/msca-impact/analysis-code.php?format=csv'),
        ]) . $body;
    }
}
