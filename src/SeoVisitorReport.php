<?php
declare(strict_types=1);

/** Private landing-page evidence supporting the existing Search Console SEO review. */
final class SeoVisitorReport
{
    public static function build(?array $traffic, array $targets, DateTimeImmutable $now): array
    {
        $base = rtrim((string)$targets['site'], '/') . '/';
        $generated = $traffic['generated_at'] ?? null;
        try { $observed = $generated ? new DateTimeImmutable($generated) : null; } catch (Throwable) { $observed = null; }
        $valid = !empty($traffic['available']) && ($traffic['site'] ?? '') === $base && ($traffic['cache_version'] ?? 0) >= 7 && $observed && $observed <= $now;
        $queue = [];
        if (!$valid) $queue[] = ['priority' => 'high', 'area' => 'visitor measurement', 'action' => 'Refresh the production visitor aggregate; a matching current-method observation is unavailable.'];
        elseif ($observed < $now->modify('-12 hours')) $queue[] = ['priority' => 'medium', 'area' => 'visitor measurement', 'action' => 'Check the scheduled six-hour visitor refresh; the cached observation is more than twelve hours old.'];
        $windowClock = $valid ? $observed : $now;
        $start = $windowClock->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->modify('-29 days')->format('Y-m-d');
        $end = $windowClock->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $dates = $valid ? array_values(array_unique(array_filter($traffic['coverage_dates_utc'] ?? [], static fn($date) => is_string($date) && $date >= $start && $date <= $end))) : [];
        sort($dates);
        if ($valid && count($dates) < 30) $queue[] = ['priority' => 'medium', 'area' => 'visitor measurement', 'action' => 'Server-log coverage is incomplete. Use the dated aggregate reports as observations, and obtain completed matched Search Console Web windows before claiming organic growth.'];
        $byPath = [];
        if ($valid) foreach ($traffic['page_metrics_30_days'] ?? [] as $page) $byPath[$page['path']] = $page;
        $pages = [];
        foreach ($targets['pages'] ?? [] as $target) {
            if (empty($target['index']) || !is_string($target['path'] ?? null) || preg_match('/[?#]/', $target['path'])) continue;
            $path = '/' . ltrim($target['path'], '/');
            $metrics = $byPath[$path] ?? null;
            $sources = $metrics['referral_requests'] ?? [];
            $pages[] = [
                'path' => $path, 'canonical_url' => $base . ltrim($path, '/'), 'label' => (string)($target['label'] ?? $path),
                'observation' => !$valid ? 'unavailable' : ($metrics ? 'observed' : 'not_observed_in_retained_logs'),
                'browser_addresses' => $valid ? (int)($metrics['visitors'] ?? 0) : null,
                'page_views' => $valid ? (int)($metrics['page_views'] ?? 0) : null,
                'search_referrer_requests' => $valid ? (int)($sources['search_referrer'] ?? 0) : null,
                'ai_referrer_requests' => $valid && ($traffic['cache_version'] ?? 0) >= 8 ? (int)($sources['ai_referrer'] ?? 0) : null,
                'external_referrer_requests' => $valid ? (int)($sources['external_referrer'] ?? 0) : null,
                'internal_referrer_requests' => $valid ? (int)($sources['internal'] ?? 0) : null,
                'direct_or_unattributed_requests' => $valid ? (int)($sources['direct_or_unattributed'] ?? 0) : null,
                'review' => $metrics ? 'Compare this canonical page with query/device/country evidence in the existing Search Console report.' : 'No conclusion about demand or indexing; inspect Search Console coverage before changing content.',
            ];
        }
        return [
            'generated_at_utc' => $now->format('c'), 'site' => $base, 'available' => (bool)$valid,
            'source_generated_at_utc' => $generated, 'source_method_version' => $valid ? $traffic['cache_version'] : null,
            'window_utc' => ['start' => $start, 'end' => $end, 'calendar_days' => 30, 'recorded_dates' => count($dates), 'dates' => $dates, 'includes_partial_today' => true],
            'comparison_ready' => false,
            'comparison_note' => 'This rolling server window includes today and may contain gaps. Do not compare it directly with completed Search Console periods, add overlapping snapshots, or sum page-level unique addresses into a site audience.',
            'interpretation' => 'Filtered server visits and referrer requests support the existing SEO strategy. They are not verified people, organic clicks, rankings, search demand or conversions. Referrers may be missing or spoofed; search referrals can include paid traffic. AI referrers identify requests from recognized service hosts, not verified citations or all AI visits; older aggregate methods leave this field unavailable. Search Console Web observations remain the organic-performance source.',
            'internal_networks_excluded' => $valid ? (int)($traffic['internal_excluded_addresses'] ?? 0) : null,
            'site_periods' => $valid ? ($traffic['periods'] ?? []) : [],
            'msca_impact' => $valid ? ($traffic['msca_impact'] ?? []) : [],
            'landing_pages' => $pages, 'review_queue' => $queue,
        ];
    }

    public static function markdown(array $report): string
    {
        $md = "# Private SEO visitor evidence\n\nGenerated: " . $report['generated_at_utc'] . "\n\n" . $report['interpretation'] . "\n\n";
        $window = $report['window_utc'];
        $md .= "Server window: {$window['start']}–{$window['end']} UTC; {$window['recorded_dates']} of 30 dates recorded. Today is partial.\n\n" . $report['comparison_note'] . "\n\n";
        $md .= "| Canonical page | Browser addresses | Page views | Search-referrer requests | AI-referrer requests | Other external requests |\n|---|---:|---:|---:|---:|---:|\n";
        foreach ($report['landing_pages'] as $p) $md .= '| ' . str_replace('|', '\\|', $p['path']) . ' | ' . ($p['browser_addresses'] ?? 'unavailable') . ' | ' . ($p['page_views'] ?? 'unavailable') . ' | ' . ($p['search_referrer_requests'] ?? 'unavailable') . ' | ' . ($p['ai_referrer_requests'] ?? 'unavailable') . ' | ' . ($p['external_referrer_requests'] ?? 'unavailable') . " |\n";
        $md .= "\nZero means no qualifying request was observed in the retained logs, not proof of no audience. Missing observations stay unavailable.\n\nRead alongside seo-local-gsc/latest.json, seo-review/query-watch.md and research-usage/latest.md. Evaluate useful research requests separately from visits and search performance.\n";
        foreach ($report['review_queue'] as $item) $md .= "\n- [{$item['priority']}] {$item['action']}";
        return $md . "\n";
    }

    public static function csv(array $report): string
    {
        $stream = fopen('php://temp', 'w+');
        $keys = ['path', 'canonical_url', 'observation', 'browser_addresses', 'page_views', 'search_referrer_requests', 'ai_referrer_requests', 'external_referrer_requests', 'internal_referrer_requests', 'direct_or_unattributed_requests'];
        $context = ['window_start_utc' => $report['window_utc']['start'], 'window_end_utc' => $report['window_utc']['end'], 'recorded_dates' => $report['window_utc']['recorded_dates'], 'source_generated_at_utc' => $report['source_generated_at_utc'], 'method_version' => $report['source_method_version']];
        fputcsv($stream, array_merge(array_keys($context), $keys), ',', '"', '');
        foreach ($report['landing_pages'] as $page) fputcsv($stream, array_merge(array_values($context), array_map(static fn($key) => $page[$key], $keys)), ',', '"', '');
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream); return $csv;
    }
}
