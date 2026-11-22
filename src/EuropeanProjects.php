<?php
declare(strict_types=1);

/** Public CORDIS grant metadata, kept outside the publication catalogue. */
final class EuropeanProjects
{
    public const QUERY = 'contenttype=project AND (psilocybin OR psilocin)';
    public static function path(): string { return Config::dataDir() . '/european-projects/snapshot.json'; }
    public static function snapshot(): array
    {
        $path = self::path();
        if (!is_file($path)) return ['checked_at' => null, 'projects' => []];
        try {
            $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            return is_array($data['projects'] ?? null) ? $data : ['checked_at' => null, 'projects' => []];
        } catch (Throwable $e) { return ['checked_at' => null, 'projects' => []]; }
    }
    public static function xml(string $xml): SimpleXMLElement
    {
        if (strlen($xml) > 10000000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new RuntimeException('Unsafe or oversized CORDIS XML.');
        $previous = libxml_use_internal_errors(true);
        try { $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$root) throw new RuntimeException('Invalid CORDIS XML.');
        $root->registerXPathNamespace('c', 'http://cordis.europa.eu');
        return $root;
    }
    public static function parsePage(string $xml): array
    {
        $root = self::xml($xml);
        $totals = $root->xpath('/c:response/c:result/c:header/c:totalHits');
        if (!$totals || !ctype_digit((string)$totals[0])) throw new RuntimeException('Missing CORDIS total.');
        $projects = [];
        foreach ($root->xpath('/c:response/c:hits/c:hit/c:project') ?: [] as $project) $projects[] = self::normalize($project);
        return ['total' => (int)$totals[0], 'projects' => $projects];
    }
    public static function normalize(SimpleXMLElement $project): array
    {
        $project->registerXPathNamespace('c', 'http://cordis.europa.eu');
        $get = static function (string $path) use ($project): string {
            $nodes = $project->xpath($path);
            return trim(preg_replace('/\s+/u', ' ', strip_tags((string)($nodes[0] ?? ''))) ?? '');
        };
        $id = $get('c:id'); $title = $get('c:title'); $start = $get('c:startDate'); $end = $get('c:endDate');
        foreach ([$start, $end] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Invalid project dates.');
        }
        if (!preg_match('/^\d{6,9}$/D', $id) || $title === '' || $end < $start) throw new RuntimeException('Invalid project identity or interval.');
        // Full objectives are inspected for relevance, never added to the public snapshot.
        $relevant = (bool)preg_match('/\bpsiloc(?:ybin|in)\b/iu', $title . ' ' . $get('c:objective') . ' ' . $get('c:keywords'));
        $org = 'c:relations/c:associations/c:organization[@type="coordinator"]';
        $coordinator = $get($org . '/c:legalName');
        if ($coordinator === '') throw new RuntimeException('Missing coordinator.');
        $money = $get('c:ecMaxContribution');
        if ($money !== '' && (!is_numeric($money) || (float)$money < 0)) throw new RuntimeException('Invalid EU contribution.');
        return ['id' => $id, 'acronym' => $get('c:acronym'), 'title' => $title,
            'coordinator' => $coordinator, 'country' => $get($org . '/c:address/c:country'),
            'programme' => $get('c:relations/c:associations/c:programme[@type="relatedLegalBasis"]/c:title'),
            'framework' => $get('c:relations/c:associations/c:programme/c:frameworkProgramme'),
            'start_date' => $start, 'end_date' => $end, 'cordis_status' => $get('c:status'),
            'eu_contribution_eur' => $money === '' ? null : number_format((float)$money, 2, '.', ''),
            'source_updated' => substr($get('c:contentUpdateDate'), 0, 10),
            'source_url' => 'https://cordis.europa.eu/project/id/' . $id,
            'project_doi' => '10.3030/' . $id, 'relevant' => $relevant];
    }
    /** Complete bounded retrieval, with no writes until every page is validated. */
    public static function collect(callable $fetch, array $old): array
    {
        $projects = []; $expected = null;
        for ($page = 1; $page <= 10; $page++) {
            $url = 'https://cordis.europa.eu/search?' . http_build_query(['q' => self::QUERY, 'format' => 'xml', 'num' => 50, 'p' => $page]);
            $batch = self::parsePage($fetch($url));
            $expected ??= $batch['total'];
            if ($batch['total'] !== $expected || $expected < 1 || $expected > 500) throw new RuntimeException('Unstable or out-of-bounds CORDIS result count.');
            foreach ($batch['projects'] as $project) {
                if (isset($projects[$project['id']])) throw new RuntimeException('Repeated CORDIS page or identifier.');
                if (!$project['relevant']) throw new RuntimeException('Search match requires relevance review: ' . $project['id']);
                unset($project['relevant']); $projects[$project['id']] = $project;
            }
            if (count($projects) === $expected) break;
            if (!$batch['projects'] || count($projects) > $expected) throw new RuntimeException('Incomplete CORDIS response.');
            usleep(500000);
        }
        if (count($projects) !== $expected) throw new RuntimeException('Incomplete CORDIS pagination.');
        // An unexpected disappearance needs review; never silently erase the previous copy.
        $missing = array_diff(array_column($old['projects'], 'id'), array_keys($projects));
        if ($missing) throw new RuntimeException('Previously indexed projects absent; review required: ' . implode(', ', $missing));
        return $projects;
    }
    public static function schedule(array $project, ?string $today = null): string
    {
        $today ??= (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        if ($today < $project['start_date']) return 'Upcoming';
        return $today > $project['end_date'] ? 'Past scheduled end' : 'Within scheduled dates';
    }
    public static function select(array $projects, array $filters): array
    {
        return array_values(array_filter($projects, static function (array $p) use ($filters): bool {
            foreach (['country', 'programme'] as $key) if (($filters[$key] ?? '') !== '' && $p[$key] !== $filters[$key]) return false;
            if (($filters['schedule'] ?? '') !== '' && self::schedule($p) !== $filters['schedule']) return false;
            $q = mb_strtolower($filters['q'] ?? '', 'UTF-8');
            return $q === '' || str_contains(mb_strtolower(implode(' ', array_filter($p, 'is_scalar')), 'UTF-8'), $q);
        }));
    }
}
