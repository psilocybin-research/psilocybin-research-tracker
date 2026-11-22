<?php
declare(strict_types=1);

/**
 * Privacy-preserving, log-derived visitor aggregates. This deliberately never
 * persists or returns an IP address: addresses exist only while an individual
 * log refresh is being calculated.
 */
final class VisitorStatisticsService
{
    private const CACHE_VERSION = 9;
    private const GEOIP_COUNTRY_BEGIN = 16776960;
    private $geoipHandle = null;
    private array $logDays = [];
    private ?string $fingerprintKey = null;
    private ?array $targetPaths = null;

    /** @var string[] */
    private const COUNTRY_CODES = [
        '', 'AP', 'EU', 'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'CW', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BM', 'BN', 'BO', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'SX', 'GA', 'GB', 'GD', 'GE', 'GF', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'ST', 'SV', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TM', 'TN', 'TO', 'TL', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'RS', 'ZA', 'ZM', 'ME', 'ZW', 'A1', 'A2', 'O1', 'AX', 'GG', 'IM', 'JE', 'BL', 'MF', 'BQ', 'SS'
    ];

    /** Public requests only read aggregates; log scans run in the scheduled CLI job. */
    public function summary(bool $refresh = false): array
    {
        $cache = Config::dataDir() . '/visitor-statistics.json';
        $cached = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
        $valid = is_array($cached) && in_array($cached['cache_version'] ?? 0, [5, 6, 7, 8, self::CACHE_VERSION], true);
        if (!$refresh) return $valid ? $cached : ['available' => false, 'message' => 'Visitor aggregates are being prepared. Please check again shortly.'];
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Log refresh requires the CLI.');
        $lock = fopen(Config::dataDir() . '/visitor-statistics-refresh.lock', 'c');
        if ($lock === false) throw new RuntimeException('Unable to open visitor refresh lock.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return $valid ? $cached : ['available' => false, 'message' => 'Visitor aggregates are being prepared.'];
        }
        try {
            $logs = $this->logFiles();
            if ($logs === []) {
                return ['available' => false, 'message' => 'Visitor aggregates are not available on this installation.'];
            }

            $now = time();
            $this->logDays = [];
            $internal = array_fill_keys($this->internalFingerprints(), true);
            $internalIps = []; $localeChecks = []; $scriptRequests = [];
            $rawAddresses = []; $rawRequests = 0; $rawStart = null; $rawEnd = null; $rawDates = [];
            $this->eachRecentEvent($logs, $now, function (array $event) use ($internal, &$internalIps, &$localeChecks): void {
                $ip = $event['ip'];
                if (isset($internal[$this->ipFingerprint($ip)])) $internalIps[$ip] = true;
                if ($this->isMscaStory($event['path'])) $localeChecks[$ip][] = [$event['time'], $event['path']];
            }, static function (string $ip, int $timestamp, string $path, string $method, int $status) use (&$rawAddresses, &$rawRequests, &$rawStart, &$rawEnd, &$rawDates, &$scriptRequests): void {
                $rawAddresses[$ip] = true;
                ++$rawRequests;
                $rawStart = $rawStart === null ? $timestamp : min($rawStart, $timestamp);
                $rawEnd = $rawEnd === null ? $timestamp : max($rawEnd, $timestamp);
                $rawDates[gmdate('Y-m-d', $timestamp)] = true;
                if ($method === 'GET' && (($status >= 200 && $status < 300) || $status === 304) && in_array($path, ['/assets/msca-impact/app.min.js', '/assets/msca-impact/app.js'], true)) $scriptRequests[$ip][] = $timestamp;
            });
            $rawTraffic = ['requests' => $rawRequests, 'requesting_addresses' => count($rawAddresses),
                'covered_calendar_dates' => count($rawDates),
                'first_date' => $rawStart === null ? null : gmdate('Y-m-d', $rawStart),
                'last_date' => $rawEnd === null ? null : gmdate('Y-m-d', $rawEnd)];
            unset($rawAddresses, $rawDates);
            $automated = $this->rapidLocaleCrawlers($localeChecks);
            unset($localeChecks);
            $excludedSet = $internalIps + $automated;
            $excluded = array_keys($excludedSet);

            // Stream logs twice; retain only qualifying integer timestamps for sessions,
            // never full requests, headers or query strings.
            $coverageStart = null;
            $lastSeen = []; $visitTimes = [];
            $mscaTimes = ['english' => [], 'all_languages' => []];
            $pageViews = ['7' => 0, '30' => 0, '90' => 0, 'all' => 0];
            $dailyIps = [];
            for ($i = 6; $i >= 0; --$i) $dailyIps[gmdate('Y-m-d', $now - $i * 86400)] = [];
            $devices = []; $browsers = []; $pages = []; $referrers = []; $pageAddresses = []; $pageSources = [];
            $calendar = new VisitorCalendar();
            $this->eachRecentEvent($logs, $now, function (array $event) use (
                &$coverageStart, &$lastSeen, &$pageViews, &$dailyIps,
                &$devices, &$browsers, &$pages, &$referrers, &$pageAddresses, &$pageSources, &$visitTimes, &$mscaTimes, $excludedSet, $now, $calendar
            ): void {
                $ip = $event['ip'];
                if (isset($excludedSet[$ip])) return;
                $calendar->add($event);
                $time = $event['time'];
                $coverageStart = $coverageStart === null ? $time : min($coverageStart, $time);
                $visitTimes[$ip][] = $time;
                if ($this->isMscaStory($event['path'])) {
                    $mscaTimes['all_languages'][$ip][] = $time;
                    if (in_array($event['path'], ['/msca-impact/', '/msca-impact/index.php'], true)) $mscaTimes['english'][$ip][] = $time;
                }
                $lastSeen[$ip] = max($lastSeen[$ip] ?? 0, $time);
                foreach ([7, 30, 90, 'all'] as $days) {
                    if ($days !== 'all' && $time < $this->periodCutoff($days, $now)) continue;
                    ++$pageViews[(string)$days];
                }
                $day = gmdate('Y-m-d', $time);
                if (isset($dailyIps[$day])) $dailyIps[$day][$ip] = true;
                if ($time < $this->periodCutoff(30, $now)) return;
                $devices[$event['device']] = ($devices[$event['device']] ?? 0) + 1;
                $browsers[$event['browser']] = ($browsers[$event['browser']] ?? 0) + 1;
                $pages[$event['path']] = ($pages[$event['path']] ?? 0) + 1;
                $pageAddresses[$event['path']][$ip] = true;
                $channel = $this->referralChannel($event['referrer']);
                $pageSources[$event['path']][$channel] = ($pageSources[$event['path']][$channel] ?? 0) + 1;
                $host = parse_url($event['referrer'], PHP_URL_HOST);
                if (is_string($host) && $host !== '' && !in_array(strtolower($host), ['psilocybin-research.com', 'www.psilocybin-research.com'], true)) {
                    $referrers[$host] = ($referrers[$host] ?? 0) + 1;
                }
            });

            $calendarData = $calendar->finish($visitTimes, array_values(array_unique($this->logDays)), function(string $ip): array {
                $code = $this->countryCode($ip) ?: 'Unknown';
                return ['name' => $code === 'Unknown' ? 'Unclassified' : $this->countryName($code)];
            });
            $sessionMetrics = $this->visitMetrics($visitTimes, $now);
            unset($visitTimes);
            $periods = [];
            foreach ([7, 30, 90, 'all'] as $days) {
                $visitors = 0;
                foreach ($lastSeen as $timestamp) if ($days === 'all' || $timestamp >= $this->periodCutoff($days, $now)) ++$visitors;
                $cutoff = $days === 'all' ? '' : gmdate('Y-m-d', $now - ($days - 1) * 86400);
                $covered = count(array_filter(array_unique($this->logDays), static fn(string $day): bool => $day >= $cutoff && $day <= gmdate('Y-m-d', $now)));
                $periods[(string)$days] = ['visitors' => $visitors, 'sessions' => $sessionMetrics['periods'][(string)$days]['sessions'], 'page_views' => $pageViews[(string)$days], 'covered_calendar_dates' => $covered];
            }
            $countries = [];
            foreach ($lastSeen as $ip => $timestamp) {
                if ($timestamp < $this->periodCutoff(30, $now)) continue;
                $code = $this->countryCode((string)$ip) ?: 'Unknown';
                $countries[$code] = ($countries[$code] ?? 0) + 1;
            }
            unset($lastSeen);
            $rank = static function (array $counts, int $limit): array {
                arsort($counts, SORT_NUMERIC);
                $rows = [];
                foreach (array_slice($counts, 0, $limit, true) as $name => $count) $rows[] = ['name' => (string)$name, 'count' => $count];
                return $rows;
            };
            arsort($countries, SORT_NUMERIC);
            $countryRows = [];
            foreach (array_slice($countries, 0, 10, true) as $code => $count) {
                $countryRows[] = ['name' => $code === 'Unknown' ? 'Unclassified' : $this->countryName((string)$code), 'code' => (string)$code, 'count' => $count];
            }

            $pageMetrics = [];
            foreach ($pages as $path => $views) $pageMetrics[] = ['path' => $path, 'visitors' => count($pageAddresses[$path]), 'page_views' => $views, 'referral_requests' => $pageSources[$path]];
            usort($pageMetrics, static fn($a, $b) => $b['page_views'] <=> $a['page_views']);
            $coverageDates = array_values(array_unique($this->logDays)); sort($coverageDates);
            $result = [
                'available' => true,
                'cache_version' => self::CACHE_VERSION,
                'site' => rtrim(Config::publicBaseUrl(), '/') . '/',
                'coverage_dates_utc' => $coverageDates,
                'calendar' => $calendarData,
                'page_metrics_30_days' => $pageMetrics,
                'coverage_note' => 'All time covers retained access logs only. Rotated or missing logs cannot be reconstructed.',
                'generated_at' => gmdate('c'),
                'coverage_start' => $coverageStart !== null ? gmdate('Y-m-d', $coverageStart) : null,
                'excluded_addresses' => count($excluded),
                'internal_excluded_addresses' => count($internalIps),
                'automated_excluded_addresses' => count(array_diff_key($automated, $internalIps)),
                'filter_method' => 'Configured internal networks, known automation and rapid locale sweeps; no busiest-address exclusion.',
                'msca_impact' => array_map(fn(array $visits): array => $this->visitMetrics($visits, $now, $scriptRequests), $mscaTimes),
                // Pseudonymous only; retained in the private data directory so the
                // live-activity counter can apply the same exclusions without
                // retaining a raw address.
                'excluded_fingerprints' => array_map(fn(string $ip): string => $this->ipFingerprint($ip), $excluded),
                'raw_traffic' => $rawTraffic,
                'periods' => $periods,
                'daily' => array_map(static fn(string $day, array $ips): array => ['date' => gmdate('M j', strtotime($day . ' UTC')), 'date_utc' => $day, 'recorded' => in_array($day, $coverageDates, true), 'visitors' => in_array($day, $coverageDates, true) ? count($ips) : null], array_keys($dailyIps), array_values($dailyIps)),
                'countries' => $countryRows,
                'devices' => $rank($devices, 6),
                'browsers' => $rank($browsers, 6),
                'pages' => $rank($pages, 8),
                'referrers' => $rank($referrers, 6),
            ];
            $temporary = $cache . '.tmp-' . bin2hex(random_bytes(4));
            try {
                if (file_put_contents($temporary, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Unable to write visitor aggregates.');
                chmod($temporary, 0640);
                if (!rename($temporary, $cache)) throw new RuntimeException('Unable to publish visitor aggregates.');
            } finally {
                if (is_file($temporary)) unlink($temporary);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** A lightweight, uncached activity reading from the current SSL log. */
    public function currentActivity(): array
    {
        $summary = $this->summary();
        if (empty($summary['available'])) return ['available' => false, 'online' => 0, 'active_sessions' => 0, 'page_views' => 0, 'countries' => []];
        $files = array_values(array_filter($this->logFiles(), static fn(string $path): bool => !str_ends_with($path, '.gz')));
        $files = array_slice(array_reverse($files), 0, 2);
        $now = time(); $events = []; $checks = []; $readable = false;
        $excluded = array_fill_keys(array_merge((array)($summary['excluded_fingerprints'] ?? []), $this->internalFingerprints()), true);
        foreach ($files as $path) {
            if (!is_readable($path) || ($handle = @fopen($path, 'rb')) === false) continue;
            $readable = true;
            $size = filesize($path) ?: 0;
            if ($size > 2_000_000) { fseek($handle, -2_000_000, SEEK_END); fgets($handle); }
            while (($line = fgets($handle)) !== false) {
                $event = $this->parseLine($line);
                if ($event === null || $event['time'] < $now - 1800 || $event['time'] > $now || isset($excluded[$this->ipFingerprint($event['ip'])])) continue;
                $events[] = $event;
                if ($this->isMscaStory($event['path'])) $checks[$event['ip']][] = [$event['time'], $event['path']];
            }
            fclose($handle);
        }
        $crawlers = $this->rapidLocaleCrawlers($checks);
        $online = []; $active = []; $pageViews = 0;
        foreach ($events as $event) {
            if (isset($crawlers[$event['ip']])) continue;
            $active[$event['ip']] = true;
            if ($event['time'] >= $now - 300) { $online[$event['ip']] = true; ++$pageViews; }
        }
        $countries = [];
        foreach ($online as $ip => $_) {
            $code = $this->countryCode((string)$ip) ?: 'Unknown';
            if (!isset($countries[$code])) $countries[$code] = ['code' => $code, 'name' => $code === 'Unknown' ? 'Unclassified' : $this->countryName($code), 'count' => 0];
            ++$countries[$code]['count'];
        }
        usort($countries, static fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
        return ['available' => $readable, 'online' => count($online), 'active_sessions' => count($active), 'page_views' => $pageViews, 'countries' => array_values($countries), 'window_seconds' => 300];
    }

    private function logFiles(): array
    {
        $configured = getenv('PUBLICATION_TRACKER_VISITOR_LOG_DIR');
        $dir = is_string($configured) && $configured !== '' ? $configured : dirname(dirname(Config::baseDir())) . '/logs/psilocybin-research.com';
        $files = glob($dir . '/access_ssl_log*') ?: [];
        $files = array_values(array_filter($files, static fn(string $path): bool => is_file($path) && preg_match('/^access_ssl_log(?:\.processed)?(?:\.\d+\.gz)?$/', basename($path)) === 1));
        // Some rotations are hard links to the same file; count each inode once.
        $seen = [];
        $files = array_values(array_filter($files, static function (string $path) use (&$seen): bool {
            $stat = stat($path);
            if ($stat === false) return false;
            $identity = $stat['dev'] . ':' . $stat['ino'];
            if (isset($seen[$identity])) return false;
            $seen[$identity] = true;
            return true;
        }));
        usort($files, static fn(string $a, string $b): int => (filemtime($a) ?: 0) <=> (filemtime($b) ?: 0));
        return $files;
    }

    private function eachRecentEvent(array $logs, int $now, callable $consume, ?callable $consumeRaw = null): void
    {
        foreach ($logs as $path) {
            $compressed = str_ends_with($path, '.gz');
            $handle = $compressed ? @gzopen($path, 'rb') : @fopen($path, 'rb');
            if ($handle === false) continue;
            while (($line = $compressed ? gzgets($handle) : fgets($handle)) !== false) {
                if ($consumeRaw !== null && preg_match('/^([^ ]+) .*?\[([^\]]+)\] "([A-Z]+) ([^ ]+) HTTP\/[^ ]+" ([0-9]{3}) /', $line, $rawMatch)
                    && ($timestamp = strtotime($rawMatch[2])) !== false && $timestamp <= $now) {
                    if (!InternalTraffic::logRequestExcluded($rawMatch[1], $timestamp, $rawMatch[3], $rawMatch[4]) && !preg_match('/"[^"]*(?:HeadlessChrome|Playwright|TrackerAudit|TrackerTest)[^"]*"\s*$/i', $line))
                    $consumeRaw($rawMatch[1], $timestamp, (string)(parse_url($rawMatch[4], PHP_URL_PATH) ?: '/'), $rawMatch[3], (int)$rawMatch[5]);
                }
                $event = $this->parseLine($line);
                if ($event !== null && $event['time'] <= $now) $consume($event);
            }
            $compressed ? gzclose($handle) : fclose($handle);
        }
    }

    private function parseLine(string $line): ?array
    {
        if (!preg_match('#^([^ ]+) .*?\[([^\]]+)\] "(GET|HEAD) ([^ ]+) HTTP/[^ ]+" ([0-9]{3}) [^ ]+ "([^"]*)" "([^"]*)"#', $line, $m)) return null;
        // Record log coverage even on dates with no qualifying human page views.
        // An access-log date can span two UTC dates; memoize by hour/offset.
        $dateKey = substr($m[2], 0, 14) . substr($m[2], -5);
        if (!isset($this->logDays[$dateKey]) && ($dateTime = strtotime($m[2])) !== false) $this->logDays[$dateKey] = gmdate('Y-m-d', $dateTime);
        $url = parse_url($m[4]);
        $path = (string)($url['path'] ?? '/');
        $ua = $m[7];
        if (InternalTraffic::logRequestExcluded($m[1], (int)strtotime($m[2]), $m[3], $m[4], false) || InternalTraffic::testAgent($ua)) return null;
        if ($m[3] !== 'GET' || (int)$m[5] < 200 || ((int)$m[5] >= 300 && (int)$m[5] !== 304) || !$this->isHumanPage($path, $ua)) return null;
        $time = strtotime($m[2]);
        if ($time === false) return null;
        // The distributed crawl uses forged Mac Chrome identities and bare
        // multi-facet requests. Exclude this observed pattern, not all direct visits.
        parse_str((string)($url['query'] ?? ''), $query);
        $facets = array_intersect_key($query, array_flip(['author', 'journal', 'topic', 'cited_doi']));
        if (preg_match('/Macintosh.*Chrome\//i', $ua) && in_array($m[6], ['', '-'], true)
            && count($facets) >= 2) return null;
        if (isset($query['format']) && is_string($query['format']) && in_array(strtolower($query['format']), ['csv', 'json', 'xml', 'bibtex', 'ris', 'sqlite'], true)) return null;
        return ['ip' => $m[1], 'time' => $time, 'path' => $this->canonicalPath($path), 'ua' => $ua, 'referrer' => $m[6], 'device' => $this->device($ua), 'browser' => $this->browser($ua)];
    }

    private function isHumanPage(string $path, string $ua): bool
    {
        if (!preg_match('/mozilla|opera/i', $ua) || preg_match('/bot|crawler|spider|slurp|scrapy|headless|curl|wget|python|httpclient|go-http|uptime|monitor|facebookexternalhit|externalagent|lighthouse|preview|inspectiontool|googleother|pagespeed|phantomjs|selenium|playwright/i', $ua)) return false;
        if (preg_match('/^Mozilla\/5\.0\s*$/i', trim($ua))) return false;
        // Count real public HTML pages only, never polling, assets, or unknown routes.
        $mscaPath = preg_match('#^/msca-impact/(?:index\.php|data-protection\.php|credits\.php|(?:de|es|fr|pt-br|zh-hans|zh-hant|ja|ko|ar|fa|hi|bn|ur|id|vi|sw|tr|ru|it)/)?$#D', $path) === 1;
        if ($mscaPath) return true;
        return isset($this->targetPaths()[$path]) || preg_match('#^/(?:index\.php|about\.php|authors\.php|publication\.php|evidence\.php|trials\.php|trials-map\.php|citation-network\.php|research-dataset\.php|research-trends\.php|open-datasets\.php|visitor-statistics\.php|editorial-policy\.php|data-protection\.php|brain-model\.php|receptor-binding\.php|chemical-structure\.php|alert\.php|recruiting-psilocybin-clinical-trials\.php|clinical-trials-topic\.php|recruiting-psilocybin-clinical-trials/?|psilocybin-clinical-trials-[a-z-]+/?|(?:early-)?phase-[123]-psilocybin-clinical-trials/?)?$#D', $path) === 1;
    }

    /** Reviewed canonical HTML targets also count; exports/APIs remain outside this list. */
    private function targetPaths(): array
    {
        if ($this->targetPaths !== null) return $this->targetPaths;
        $this->targetPaths = ['/index.php' => '/', '/msca-impact/index.php' => '/msca-impact/'];
        $file = Config::baseDir() . '/config/seo-targets.json';
        $targets = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        foreach ($targets['pages'] ?? [] as $page) {
            if (empty($page['index']) || !is_string($page['path'] ?? null) || preg_match('/[?#]/', $page['path'])) continue;
            $path = '/' . ltrim($page['path'], '/');
            $this->targetPaths[$path] = $path;
            if ($path !== '/' && str_ends_with($path, '/')) {
                $alias = rtrim($path, '/') . '.php';
                if (is_file(Config::baseDir() . $alias)) $this->targetPaths[$alias] = $path;
            }
        }
        foreach (['/european-projects.php' => '/european-psilocybin-research-projects/', '/research-brief-2026-10.php' => '/psilocybin-research-brief-2026-10/'] as $alias => $canonical) if (isset($this->targetPaths[$canonical])) $this->targetPaths[$alias] = $canonical;
        return $this->targetPaths;
    }

    private function canonicalPath(string $path): string { return $this->targetPaths()[$path] ?? $path; }

    private function referralChannel(string $referrer): string
    {
        $host = strtolower((string)parse_url($referrer, PHP_URL_HOST));
        if ($host === '') return 'direct_or_unattributed';
        if (in_array($host, ['psilocybin-research.com', 'www.psilocybin-research.com'], true)) return 'internal';
        // Host boundaries prevent lookalike domains from being attributed to AI services.
        if (preg_match('/(?:^|\.)(?:chatgpt\.com|chat\.openai\.com|perplexity\.ai|claude\.ai|copilot\.microsoft\.com|gemini\.google\.com)$/D', $host)) return 'ai_referrer';
        // These are observed referrer requests, not verified organic clicks or rankings.
        if (preg_match('/(?:^|\.)(?:google\.(?:com|de|co\.uk|fr|es|it|ca|com\.au)|bing\.com|duckduckgo\.com|search\.yahoo\.com|baidu\.com|ecosia\.org|search\.brave\.com)$/D', $host)) return 'search_referrer';
        return 'external_referrer';
    }

    /** Explicit private exclusions replace the former busiest-address guess. */
    private function internalFingerprints(): array
    {
        $file = Config::dataDir() . '/visitor-internal-fingerprints.json';
        if (!is_file($file)) return [];
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['fingerprints'] ?? null)) throw new RuntimeException('Invalid internal visitor exclusions');
        return array_values(array_unique(array_filter($data['fingerprints'], static fn($value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1)));
    }

    private function isMscaStory(string $path): bool
    {
        return preg_match('#^/msca-impact/(?:index\.php|(?:de|es|fr|pt-br|zh-hans|zh-hant|ja|ko|ar|fa|hi|bn|ur|id|vi|sw|tr|ru|it)/)?$#D', $path) === 1;
    }

    /** Twelve distinct language/story routes in ten seconds is a crawl, not normal reading. */
    private function rapidLocaleCrawlers(array $checks): array
    {
        $excluded = [];
        foreach ($checks as $ip => $events) {
            if (count($events) < 12) continue;
            usort($events, static fn($a, $b) => $a[0] <=> $b[0]);
            $left = 0; $paths = [];
            foreach ($events as $right => [$time, $path]) {
                $paths[$path] = ($paths[$path] ?? 0) + 1;
                while ($time - $events[$left][0] > 10) {
                    $old = $events[$left++][1];
                    if (--$paths[$old] === 0) unset($paths[$old]);
                }
                if (count($paths) >= 12) { $excluded[$ip] = true; break; }
            }
        }
        return $excluded;
    }

    private function periodCutoff(int|string $days, int $now): int
    {
        return $days === 'all' ? 0 : strtotime(gmdate('Y-m-d', $now) . ' UTC') - ($days - 1) * 86400;
    }

    /** Sort timelines: rotation order must never create or suppress sessions. */
    private function visitMetrics(array $visits, int $now, array $scripts = []): array
    {
        $periods = []; $first = null; $last = null;
        foreach ($visits as &$times) sort($times, SORT_NUMERIC);
        unset($times);
        foreach ([7, 30, 90, 'all'] as $days) {
            $cutoff = $this->periodCutoff($days, $now);
            $visitors = 0; $sessions = 0; $views = 0; $withScript = 0;
            foreach ($visits as $ip => $times) {
                $previous = null; $seen = false; $scriptSeen = false;
                foreach ($times as $time) {
                    $first = $first === null ? $time : min($first, $time);
                    $last = $last === null ? $time : max($last, $time);
                    if ($time < $cutoff || $time > $now) continue;
                    if (!$seen) { ++$visitors; $seen = true; }
                    if ($previous === null || $time - $previous > 1800) ++$sessions;
                    ++$views; $previous = $time;
                    if (!$scriptSeen) foreach ($scripts[$ip] ?? [] as $scriptTime) {
                        if (abs($scriptTime - $time) <= 300) { $scriptSeen = true; ++$withScript; break; }
                    }
                }
            }
            $periods[(string)$days] = ['visitors' => $visitors, 'sessions' => $sessions, 'page_views' => $views, 'addresses_with_script_request' => $withScript];
        }
        return ['periods' => $periods, 'first_visit_utc' => $first !== null ? gmdate('c', $first) : null, 'last_visit_utc' => $last !== null ? gmdate('c', $last) : null];
    }

    private function device(string $ua): string { return preg_match('/ipad|tablet/i', $ua) ? 'Tablet' : (preg_match('/mobile|android|iphone|ipod/i', $ua) ? 'Mobile' : 'Desktop'); }
    private function browser(string $ua): string { return preg_match('/edg\//i', $ua) ? 'Edge' : (preg_match('/firefox\//i', $ua) ? 'Firefox' : (preg_match('/chrome\//i', $ua) && !preg_match('/edg\//i', $ua) ? 'Chrome' : (preg_match('/safari\//i', $ua) ? 'Safari' : 'Other'))); }

    private function countryCode(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return null;
        $file = Config::dataDir() . '/geoip/GeoIP.dat';
        if (!is_resource($this->geoipHandle)) $this->geoipHandle = is_readable($file) ? @fopen($file, 'rb') : false;
        if (!is_resource($this->geoipHandle)) return null;
        $fp = $this->geoipHandle;
        $offset = 0; $ipnum = ip2long($ip);
        for ($depth = 31; $depth >= 0; --$depth) {
            if (fseek($fp, 6 * $offset) !== 0 || ($buf = fread($fp, 6)) === false || strlen($buf) !== 6) break;
            $left = ord($buf[0]) | (ord($buf[1]) << 8) | (ord($buf[2]) << 16);
            $right = ord($buf[3]) | (ord($buf[4]) << 8) | (ord($buf[5]) << 16);
            $next = ($ipnum & (1 << $depth)) ? $right : $left;
            if ($next >= self::GEOIP_COUNTRY_BEGIN) return self::COUNTRY_CODES[$next - self::GEOIP_COUNTRY_BEGIN] ?? null;
            $offset = $next;
        }
        return null;
    }

    private function countryName(string $code): string
    {
        return class_exists('Locale') ? (string)Locale::getDisplayRegion('und_' . $code, 'en') : $code;
    }

    private function ipFingerprint(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->fingerprintKey ??= Config::sensitiveDataKey());
    }
}
