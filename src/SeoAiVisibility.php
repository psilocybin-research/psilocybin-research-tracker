<?php
declare(strict_types=1);

/** Private Bing AI citation observations, separate from traffic and crawl eligibility. */
final class SeoAiVisibility
{
    public const SITE = 'https://psilocybin-research.com/';

    public static function normalize(array $input, DateTimeImmutable $now): array
    {
        if (!is_array($input['period'] ?? null) || !is_array($input['pages'] ?? [])) {
            throw new InvalidArgumentException('Period and cited pages must be structured observations.');
        }
        if (($input['site'] ?? '') !== self::SITE || ($input['source'] ?? '') !== 'bing-ai-performance') {
            throw new InvalidArgumentException('A matching Bing AI Performance property is required.');
        }
        $observed = self::date((string)($input['observed_at_utc'] ?? ''));
        $start = self::date((string)($input['period']['start'] ?? ''));
        $end = self::date((string)($input['period']['end'] ?? ''));
        if (!$observed || !$start || !$end || $observed > $now || $start > $end || $end > $observed) {
            throw new InvalidArgumentException('Observation and period dates must be valid, ordered and not future-dated.');
        }
        $status = $input['status'] ?? '';
        if (!in_array($status, ['measured', 'no_data', 'unavailable'], true)) {
            throw new InvalidArgumentException('Use measured, no_data or unavailable as the provider status.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', (string)($input['evidence_sha256'] ?? ''))) {
            throw new InvalidArgumentException('Retain the SHA-256 of the private dashboard evidence.');
        }
        $citations = $input['total_citations'] ?? null;
        if ($status === 'measured' && (!is_int($citations) || $citations < 0)) {
            throw new InvalidArgumentException('Measured citations must be a nonnegative integer.');
        }
        if ($status !== 'measured' && ($citations !== null || !empty($input['pages']))) {
            throw new InvalidArgumentException('Unavailable or no-data reports must not fabricate zero citation counts.');
        }
        $pages = [];
        if (count($input['pages'] ?? []) > 1000) throw new InvalidArgumentException('Too many cited pages.');
        foreach ($input['pages'] ?? [] as $page) {
            if (!is_array($page)) throw new InvalidArgumentException('Invalid cited page.');
            $url = (string)($page['url'] ?? '');
            $parts = parse_url($url);
            if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') !== 'psilocybin-research.com'
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
                || preg_match('/[\x00-\x20|]/', $url) || !is_int($page['citations'] ?? null) || $page['citations'] < 0 || isset($pages[$url])) {
                throw new InvalidArgumentException('Cited pages must be unique property URLs with integer citation counts.');
            }
            $pages[$url] = ['url' => $url, 'citations' => $page['citations']];
        }
        return [
            'site' => self::SITE, 'source' => 'bing-ai-performance', 'status' => $status,
            'observed_at_utc' => $observed->format('c'),
            'period' => ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')],
            'total_citations' => $status === 'measured' ? $citations : null,
            'pages' => array_values($pages), 'evidence_sha256' => $input['evidence_sha256'],
        ];
    }

    public static function build(?array $input, DateTimeImmutable $now): array
    {
        $observation = null;
        try { if ($input !== null) $observation = self::normalize($input, $now); } catch (InvalidArgumentException) {}
        $measured = ($observation['status'] ?? '') === 'measured';
        $stale = $observation && new DateTimeImmutable($observation['observed_at_utc']) < $now->modify('-35 days');
        $queue = [];
        if (!$measured || $stale) $queue[] = [
            'priority' => 'medium', 'area' => 'AI visibility',
            'action' => 'Review Bing Webmaster Tools AI Performance for this property and retain dated citation/page evidence. Check grounding queries and Topics Portfolio when available. Missing or stale observations are not zero citations; the Bing traffic API and server referrers are separate measurements.',
        ];
        return [
            'generated_at_utc' => $now->format('c'), 'site' => self::SITE,
            'citation_measurement_available' => $measured, 'stale' => (bool)$stale,
            'observation' => $observation, 'review_queue' => $queue,
            'interpretation' => 'Bing AI Performance covers supported Microsoft and partner AI surfaces, not all AI engines. Citations are not visits, rankings, endorsements or conversions. Page rows may be partial; do not sum them into a site total or infer causation from changes. Compare only matching complete periods and scopes.',
        ];
    }

    public static function markdown(array $report): string
    {
        $o = $report['observation'];
        $md = "# Private AI visibility review\n\nGenerated: {$report['generated_at_utc']}\n\n{$report['interpretation']}\n\n";
        $md .= 'Provider status: ' . ($o['status'] ?? 'unavailable') . '; stale: ' . ($report['stale'] ? 'yes' : 'no') . ".\n\n";
        if ($o) $md .= "Observed: {$o['observed_at_utc']}; period: {$o['period']['start']}–{$o['period']['end']}.\n\n";
        $md .= 'Total citations: ' . ($o['total_citations'] ?? 'unavailable') . ".\n\n";
        foreach ($o['pages'] ?? [] as $page) $md .= '- ' . $page['url'] . ': ' . $page['citations'] . " citations\n";
        foreach ($report['review_queue'] as $item) $md .= "\n- [{$item['priority']}] {$item['action']}\n";
        return $md;
    }

    public static function trialReview(string $expires, DateTimeImmutable $now): array
    {
        $date = self::date($expires);
        if ($date && $date > $now->modify('+30 days')) return [];
        return [[
            'priority' => !$date || $date <= $now ? 'high' : 'medium', 'area' => 'browser agent support',
            'action' => 'Review the domain WebMCP origin-trial registration and current browser support. The recorded token expires ' . ($date ? $date->format('Y-m-d') . ' UTC' : 'on an unverified date') . '. Renew or remove it as appropriate; verify unflagged task execution and preserve native-form fallback.',
        ]];
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))?$/D', $value)) return null;
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            return $errors && ($errors['warning_count'] || $errors['error_count']) ? null : $date->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) { return null; }
    }
}
