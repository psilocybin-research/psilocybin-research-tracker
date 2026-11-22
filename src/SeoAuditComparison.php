<?php
declare(strict_types=1);

final class SeoAuditComparison
{
    public static function compare(array $current, ?array $previous): ?array
    {
        if (!$previous || ($previous['base_url'] ?? '') !== $current['base_url']) return null;
        $sameScope = isset($previous['audit_scope']) && $previous['audit_scope'] === $current['audit_scope'];
        $before = array_column($previous['pages'] ?? [], null, 'url');
        $common = 0;
        $beforeCritical = $afterCritical = 0;
        foreach ($current['pages'] as $page) {
            if (!isset($before[$page['url']])) continue;
            $common++;
            foreach ($before[$page['url']]['issues'] ?? [] as $issue) if ($issue['severity'] === 'critical') $beforeCritical++;
            foreach ($page['issues'] ?? [] as $issue) if ($issue['severity'] === 'critical') $afterCritical++;
        }
        return [
            'previous_generated_at_utc' => $previous['generated_at_utc'] ?? null,
            'same_audit_scope' => $sameScope,
            'note' => $sameScope ? 'Matching origin and audit scope; scores measure technical checks only.' : 'Audit scope changed or was not recorded; score and issue-count deltas are not comparable.',
            'score_delta' => $sameScope ? $current['score'] - (int)$previous['score'] : null,
            'critical_issue_delta' => $sameScope ? $current['critical_issues'] - (int)$previous['critical_issues'] : null,
            'warning_delta' => $sameScope ? $current['warnings'] - (int)$previous['warnings'] : null,
            'sitemap_url_delta' => $current['discovery']['sitemap_urls'] - (int)($previous['discovery']['sitemap_urls'] ?? 0),
            'common_page_count' => $common,
            'common_page_critical_issues_before' => $beforeCritical,
            'common_page_critical_issues_after' => $afterCritical,
        ];
    }
}
