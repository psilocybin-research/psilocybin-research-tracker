<?php
declare(strict_types=1);

/** In-memory calendar aggregation. Only anonymous totals leave this object. */
final class VisitorCalendar
{
    private array $buckets = [];

    public function add(array $event): void
    {
        $month = gmdate('Y-m', $event['time']);
        $day = gmdate('Y-m-d', $event['time']);
        $ip = $event['ip'];
        $this->buckets[$month]['addresses'][$ip] = true;
        $this->buckets[$month]['daily'][$day][$ip] = true;
        foreach (['devices' => 'device', 'browsers' => 'browser', 'pages' => 'path'] as $group => $field) {
            $key = $event[$field];
            $this->buckets[$month][$group][$key] = ($this->buckets[$month][$group][$key] ?? 0) + 1;
        }
        $host = strtolower((string)parse_url($event['referrer'], PHP_URL_HOST));
        if ($host !== '' && !in_array($host, ['psilocybin-research.com', 'www.psilocybin-research.com'], true)) {
            $this->buckets[$month]['referrers'][$host] = ($this->buckets[$month]['referrers'][$host] ?? 0) + 1;
        }
    }

    public function finish(array $visits, array $coverageDates, callable $country): array
    {
        sort($coverageDates);
        if (!$coverageDates) return ['months' => [], 'ranges' => [], 'daily' => []];
        $end = substr(end($coverageDates), 0, 7);
        // Bound cache size and refresh work while retaining up to two years.
        $first = max(substr($coverageDates[0], 0, 7), gmdate('Y-m', strtotime($end . '-01 UTC -23 months')));
        $months = [];
        for ($m = $first; $m <= $end; $m = gmdate('Y-m', strtotime($m . '-01 UTC +1 month'))) $months[] = $m;
        $daily = []; $recorded = array_fill_keys($coverageDates, true);
        for ($date = $first . '-01', $last = min(gmdate('Y-m-d'), gmdate('Y-m-t', strtotime($end . '-01 UTC'))); $date <= $last; $date = gmdate('Y-m-d', strtotime($date . ' UTC +1 day'))) {
            $daily[] = ['date' => gmdate('M j', strtotime($date . ' UTC')), 'date_utc' => $date, 'recorded' => isset($recorded[$date]), 'visitors' => isset($recorded[$date]) ? count($this->buckets[substr($date, 0, 7)]['daily'][$date] ?? []) : null];
        }
        $sessions = []; $firstVisit = []; $lastVisit = [];
        foreach ($visits as $ip => $times) {
            sort($times, SORT_NUMERIC); $previous = null;
            foreach ($times as $time) {
                $m = gmdate('Y-m', $time);
                if ($m < $first || $m > $end) continue;
                if (!isset($firstVisit[$m][$ip]) || $previous === null || $time - $previous > 1800) $sessions[$m] = ($sessions[$m] ?? 0) + 1;
                $firstVisit[$m][$ip] ??= $time;
                $lastVisit[$m][$ip] = $time;
                $previous = $time;
            }
        }
        $rank = static function(array $counts, int $limit = 12): array {
            arsort($counts, SORT_NUMERIC); $rows = [];
            foreach (array_slice($counts, 0, $limit, true) as $name => $count) $rows[] = ['name' => (string)$name, 'count' => $count];
            return $rows;
        };
        $locations = []; $ranges = [];
        foreach ($months as $i => $from) {
            $addresses = []; $countries = []; $lastSeen = []; $countSessions = 0;
            $groups = ['devices' => [], 'browsers' => [], 'pages' => [], 'referrers' => []];
            foreach (array_slice($months, $i) as $to) {
                $bucket = $this->buckets[$to] ?? [];
                $countSessions += $sessions[$to] ?? 0;
                foreach ($firstVisit[$to] ?? [] as $ip => $time) {
                    if (isset($lastSeen[$ip]) && $time - $lastSeen[$ip] <= 1800) --$countSessions;
                    $lastSeen[$ip] = $lastVisit[$to][$ip];
                }
                foreach ($bucket['addresses'] ?? [] as $ip => $_) {
                    if (isset($addresses[$ip])) continue;
                    $addresses[$ip] = true;
                    $location = $locations[$ip] ??= $country((string)$ip);
                    $countries[$location['name']] = ($countries[$location['name']] ?? 0) + 1;
                }
                foreach ($groups as $group => &$counts) foreach ($bucket[$group] ?? [] as $name => $count) $counts[$name] = ($counts[$name] ?? 0) + $count;
                unset($counts);
                $covered = count(array_filter($coverageDates, static fn(string $d): bool => substr($d, 0, 7) >= $from && substr($d, 0, 7) <= $to));
                $ranges[$from . ':' . $to] = [
                    'totals' => ['visitors' => count($addresses), 'sessions' => $countSessions, 'page_views' => array_sum($groups['pages'])],
                    'covered_dates' => $covered, 'countries' => $rank($countries),
                    'devices' => $rank($groups['devices']), 'browsers' => $rank($groups['browsers']),
                    'pages' => $rank($groups['pages']), 'referrers' => $rank($groups['referrers']),
                ];
            }
        }
        return ['months' => $months, 'ranges' => $ranges, 'daily' => $daily];
    }
}
