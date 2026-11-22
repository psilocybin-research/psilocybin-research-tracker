<?php
declare(strict_types=1);

/** Descriptive Bai–Perron least-squares segmentation with BIC model selection. */
final class PublicationTrendBreaks
{
    public static function analyze(array $rows, int $maxBreaks = 5, float $trim = 0.15): ?array
    {
        if ($maxBreaks < 0 || $maxBreaks > 5 || $trim <= 0 || $trim > 0.5) {
            throw new InvalidArgumentException('Invalid breakpoint search bounds');
        }
        $rows = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['count'] > 0));
        usort($rows, static fn(array $a, array $b): int => (int)$a['year'] <=> (int)$b['year']);
        $n = count($rows);
        if ($n < 5) return null;
        if (count(array_unique(array_column($rows, 'year'))) !== $n) {
            throw new InvalidArgumentException('Annual series must have unique years');
        }
        $minimum = max(5, (int)ceil($trim * $n));
        $origin = (int)$rows[0]['year'];
        // Fit with centred actual calendar years, including gaps, to avoid cancellation.
        $fits = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + $minimum; $j <= $n; $j++) {
                $points = array_slice($rows, $i, $j - $i);
                $mx = array_sum(array_map(static fn(array $r): int => (int)$r['year'] - $origin, $points)) / count($points);
                $my = array_sum(array_map(static fn(array $r): float => log((float)$r['count']), $points)) / count($points);
                $xx = 0.0; $xy = 0.0;
                foreach ($points as $p) {
                    $x = (int)$p['year'] - $origin - $mx;
                    $xx += $x * $x; $xy += $x * (log((float)$p['count']) - $my);
                }
                $slope = $xy / $xx; $intercept = $my - $slope * $mx; $rss = 0.0;
                foreach ($points as $p) $rss += (log((float)$p['count']) - $intercept - $slope * ((int)$p['year'] - $origin)) ** 2;
                $fits[$i][$j] = ['rss' => $rss, 'slope' => $slope, 'intercept' => $intercept,
                    'origin_year' => $origin, 'first_year' => (int)$points[0]['year'], 'last_year' => (int)$points[array_key_last($points)]['year']];
            }
        }
        $whole = $fits[0][$n];
        // A common numerical floor keeps exact-fit fixtures from selecting spurious breaks.
        $floor = max(1.0e-12, $whole['rss'] * 1.0e-12);
        $bic = static fn(float $rss, int $segments): float => $n * log(max($rss, $floor) / $n)
            + (2 * $segments + $segments - 1) * log($n);
        $maxSegments = min($maxBreaks + 1, intdiv($n, $minimum));
        $dp = [0 => [0 => 0.0]]; $parents = []; $candidates = [];
        for ($k = 1; $k <= $maxSegments; $k++) {
            for ($end = $k * $minimum; $end <= $n; $end++) {
                $best = INF; $split = null;
                for ($start = ($k - 1) * $minimum; $start <= $end - $minimum; $start++) {
                    if (!isset($dp[$k - 1][$start], $fits[$start][$end])) continue;
                    $cost = $dp[$k - 1][$start] + $fits[$start][$end]['rss'];
                    if ($cost < $best) { $best = $cost; $split = $start; }
                }
                if ($split !== null) { $dp[$k][$end] = $best; $parents[$k][$end] = $split; }
            }
            if (isset($dp[$k][$n])) $candidates[] = ['segments' => $k, 'rss' => $dp[$k][$n], 'bic' => $bic($dp[$k][$n], $k)];
        }
        $best = $candidates[0];
        foreach ($candidates as $candidate) if ($candidate['bic'] < $best['bic'] - 1.0e-9) $best = $candidate;
        $segments = []; $breaks = []; $end = $n;
        for ($k = $best['segments']; $k > 0; $k--) {
            $start = $parents[$k][$end]; array_unshift($segments, $fits[$start][$end]);
            if ($start > 0) array_unshift($breaks, (int)$rows[$start]['year']);
            $end = $start;
        }
        return ['breaks' => $breaks, 'segments' => $segments, 'observations' => $n,
            'min_segment' => $minimum, 'max_breaks' => $maxBreaks, 'bic' => $best['bic'],
            'delta_bic' => $candidates[0]['bic'] - $best['bic'], 'candidates' => $candidates,
            'first_year' => $origin, 'last_year' => (int)$rows[$n - 1]['year'],
            'criterion' => 'n*log(RSS/n) + (2*segments + breaks)*log(n)'];
    }
}
