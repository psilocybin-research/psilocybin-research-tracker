<?php
declare(strict_types=1);

/** Private review priorities; crawl health is never interpreted as organic performance. */
final class SeoReview
{
    public static function build(array $targets, ?array $audit, ?array $performance, array $evidenceDates, DateTimeImmutable $now): array
    {
        $base = rtrim((string)$targets['site'], '/') . '/';
        $queue = [];
        $add = static function (string $priority, string $area, string $action) use (&$queue): void {
            $queue[] = compact('priority', 'area', 'action');
        };
        $auditValid = $audit && ($audit['base_url'] ?? '') === $base;
        if (!$auditValid) $add('high', 'technical', 'Run a production strict crawl audit; no matching production observation is available.');
        elseif ((int)($audit['critical_issues'] ?? 0) > 0) $add('high', 'technical', 'Resolve the critical crawl findings before interpreting search performance.');
        if ($auditValid) {
            $date = self::date((string)($audit['generated_at_utc'] ?? ''));
            if (!$date || $date < $now->modify('-35 days')) $add('high', 'technical', 'Refresh the production crawl audit; its observation is missing or older than 35 days.');
        }
        $property = (string)($performance['property'] ?? '');
        $matches = in_array($property, [$base, rtrim($base, '/'), 'sc-domain:' . parse_url($base, PHP_URL_HOST)], true);
        $periodEnd = self::date((string)($performance['current']['period']['end'] ?? ''));
        $performanceValid = $performance && $matches && ($performance['search_type'] ?? '') === 'web' && $periodEnd && $periodEnd <= $now;
        if (!$performanceValid) {
            $add('high', 'organic performance', 'Import an owner-accessible Search Console Web export for this property and a comparable preceding period. Rankings, traffic and keyword demand remain unmeasured.');
        } else {
            if ($periodEnd < $now->modify('-35 days')) $add('high', 'organic performance', 'Import newer Search Console observations; the measured period ended more than 35 days ago.');
            if (empty($performance['comparison'])) $add('medium', 'organic performance', 'Add a matching previous period before interpreting changes.');
            $add('medium', 'query review', 'Review query/page opportunities in the private Search Console report; prioritize intent match, indexing and comparable query/device/country cohorts.');
        }
        $add('medium', 'indexing and experience', 'Review Google Page indexing and real-user Core Web Vitals, plus Bing index coverage. Lab tests and sitemap counts do not establish field performance or indexed counts.');
        foreach ($evidenceDates as $path => $checked) {
            $date = self::date((string)$checked);
            $due = $date ? $date->modify('+30 days') : null;
            if (!$due || $due <= $now) $add('high', 'evidence review', 'Recheck primary sources for /' . $path . '; update annotations and dates only after substantive source verification.');
            elseif ($due <= $now->modify('+7 days')) $add('medium', 'evidence review', 'Source review for /' . $path . ' is due within seven days; verify new reports and corrections before changing dates.');
        }
        $add('medium', 'research authority', 'Review genuine scholarly resource listings, citations and reuse opportunities. Prepare accurate resource descriptions; contact or submit only with explicit owner authorization.');
        $coverage = [];
        $indexable = [];
        foreach ($targets['pages'] as $page) if (!empty($page['index'])) $indexable[$page['path']] = true;
        foreach ($targets['query_clusters'] as $cluster) {
            $path = $cluster['primary_path'] ?? null;
            $mapped = $path !== null && isset($indexable[$path]);
            $coverage[] = ['cluster' => $cluster['name'], 'intent' => $cluster['intent'], 'primary_path' => $path, 'indexable_owner' => $mapped, 'demand_verified' => false];
            if (!$mapped) $add('low', 'intent coverage', 'Review ' . $cluster['name'] . ': no indexable canonical owner is mapped. Publish only with distinct value and reviewed source support.');
        }
        return [
            'generated_at_utc' => $now->format(DateTimeInterface::ATOM), 'base_url' => $base,
            'crawl_observation_available' => (bool)$auditValid, 'performance_observation_available' => (bool)$performanceValid,
            'performance_period_end' => $performanceValid ? $periodEnd->format('Y-m-d') : null,
            'keyword_coverage' => $coverage, 'review_queue' => $queue,
            'interpretation' => 'Keyword mappings are editorial hypotheses, not verified demand or rankings. Organic changes require comparable performance observations. No queue item automatically changes a page or sends outreach.',
        ];
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/D', $value)) return null;
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            return $errors !== false && ($errors['warning_count'] || $errors['error_count']) ? null : $date;
        } catch (Throwable) { return null; }
    }
}
