<?php
declare(strict_types=1);

/** Public citation snapshot: web requests read; a bounded CLI job refreshes. */
final class MscaCitations
{
    public const DOI = '10.1016/j.mehy.2019.109406';
    public const TTL = 21600;
    public static function path(): string { return Config::dataDir() . '/msca-impact-openalex-citations.json'; }

    public static function read(): array
    {
        $payload = is_file(self::path()) ? json_decode((string)file_get_contents(self::path()), true) : null;
        if (!is_array($payload) || (int)($payload['schema_version'] ?? 0) < 2 || empty($payload['fetched_all']) || !is_array($payload['citations'] ?? null)) {
            return ['target'=>null,'citations'=>[],'count'=>0,'updated_at'=>null,'cached'=>true,'fetched_all'=>false,'error'=>'OpenAlex citation data is temporarily unavailable.'];
        }
        $payload['cached'] = true;
        $timestamp = strtotime((string)($payload['updated_at'] ?? ''));
        $payload['error'] = $timestamp === false || time() - $timestamp > self::TTL + 900 ? 'Cached OpenAlex citation data may be out of date.' : null;
        return $payload;
    }

    public static function refresh(?callable $get = null): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Citation refresh is CLI only.');
        $lock = fopen(self::path() . '.lock', 'c');
        if ($lock === false) throw new RuntimeException('Cannot open citation refresh lock.');
        chmod(self::path() . '.lock', 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['skipped'=>true]; }
        $temporary = self::path() . '.tmp-' . bin2hex(random_bytes(8));
        try {
            $get ??= static fn(string $url): string => (new HttpClient())->get($url);
            $request = static function (string $path, array $parameters = []) use ($get): array {
                if (Config::openAlexApiKey()) $parameters['api_key'] = Config::openAlexApiKey();
                $url = 'https://api.openalex.org/' . $path . ($parameters ? '?' . http_build_query($parameters) : '');
                $data = json_decode($get($url), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data)) throw new RuntimeException('Invalid OpenAlex response.');
                return $data;
            };
            $target = $request('works/doi:' . rawurlencode(self::DOI));
            if (!preg_match('~^https://openalex.org/(W\d+)$~', (string)($target['id'] ?? ''), $id)) throw new RuntimeException('Invalid target work.');
            if (normalize_doi((string)($target['doi'] ?? '')) !== self::DOI) throw new RuntimeException('Target DOI mismatch.');
            $cursor = '*'; $seenCursors = []; $citations = []; $total = null;
            for ($page = 0; $page < 100; $page++) {
                if (isset($seenCursors[$cursor])) throw new RuntimeException('Repeated citation cursor.');
                $seenCursors[$cursor] = true;
                $data = $request('works', ['filter'=>'cites:' . $id[1], 'sort'=>'publication_date:desc','per_page'=>100,'cursor'=>$cursor,'select'=>'id,doi,display_name,publication_year,publication_date,authorships,primary_location,cited_by_count']);
                if (!isset($data['meta']['count']) || !is_array($data['results'] ?? null)) throw new RuntimeException('Incomplete citation response.');
                $total ??= (int)$data['meta']['count'];
                if ((int)$data['meta']['count'] !== $total) throw new RuntimeException('Citation set changed during pagination.');
                foreach ($data['results'] as $work) {
                    if (!is_array($work) || !preg_match('~^https://openalex.org/W\d+$~', (string)($work['id'] ?? ''))) throw new RuntimeException('Invalid citing work.');
                    $workId = $work['id'];
                    if (isset($citations[$workId])) throw new RuntimeException('Duplicate citation during pagination.');
                    $doi = normalize_doi(is_string($work['doi'] ?? null) ? $work['doi'] : null);
                    $authors = [];
                    foreach ((array)($work['authorships'] ?? []) as $author) {
                        $name = trim((string)($author['author']['display_name'] ?? ''));
                        if ($name !== '') $authors[] = $name;
                        if (count($authors) >= 4) break;
                    }
                    $citations[$workId] = [
                        'title'=>clean_scientific_text((string)($work['display_name'] ?? 'Untitled citing work')),
                        'authors'=>clean_scientific_text($authors ? implode(', ', $authors) . (count((array)($work['authorships'] ?? [])) > count($authors) ? ' et al.' : '') : 'Author metadata pending'),
                        'journal'=>clean_scientific_text((string)($work['primary_location']['source']['display_name'] ?? 'Source metadata pending')),
                        'date'=>clean_scientific_text((string)($work['publication_date'] ?? ($work['publication_year'] ?? ''))),
                        'doi'=>$doi,'url'=>$doi ? 'https://doi.org/' . $doi : $workId,'openalex_url'=>$workId,'cited_by_count'=>max(0,(int)($work['cited_by_count'] ?? 0)),
                    ];
                }
                if (count($citations) === $total) break;
                $next = $data['meta']['next_cursor'] ?? null;
                if (!$data['results'] || !is_string($next) || $next === '') throw new RuntimeException('Citation pagination ended early.');
                $cursor = $next;
            }
            if ($total === null || count($citations) !== $total) throw new RuntimeException('Citation refresh did not complete.');
            $payload=['schema_version'=>2,'target'=>['id'=>$target['id'],'title'=>clean_scientific_text((string)($target['display_name'] ?? 'The Psilocybin-Telomere Hypothesis')),'doi'=>self::DOI,'cited_by_count'=>max(0,(int)($target['cited_by_count'] ?? 0))], 'citations'=>array_values($citations),'count'=>max($total,(int)($target['cited_by_count'] ?? 0)),'updated_at'=>gmdate('c'),'cached'=>false,'fetched_all'=>true,'error'=>null];
            $handle = fopen($temporary, 'x');
            if ($handle === false) throw new RuntimeException('Cannot create citation snapshot.');
            chmod($temporary, 0600);
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            try { if (fwrite($handle, $json) !== strlen($json)) throw new RuntimeException('Incomplete snapshot write.'); } finally { fclose($handle); }
            if (!rename($temporary, self::path())) throw new RuntimeException('Cannot publish citation snapshot.');
            return ['citations'=>count($citations),'updated_at'=>$payload['updated_at']];
        } finally {
            if (is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
