<?php
declare(strict_types=1);

final class OpenAlexFetcher implements FetcherInterface
{
    private const BASE = 'https://api.openalex.org/works';
    private const DEFAULT_QUERIES = [
        'psilocybin',
        'psilocin',
        '"4-HO-DMT"',
        '"4 hydroxy DMT"',
        '"magic mushroom" psychedelic',
        '"psilocybe" psilocybin',
        '"COMP360"',
        '"CYB003"',
        '"ELE-101"',
    ];

    public function __construct(private HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'OpenAlex';
    }

    public function fetch(?string $from = null, ?string $to = null, int $limit = 0): array
    {
        return $this->fetchQueries(self::DEFAULT_QUERIES, $from, $to, $limit, 2);
    }

    /** @param array<int,string> $queries */
    public function fetchQueries(array $queries, ?string $from = null, ?string $to = null, int $limit = 0, int $minimumScore = 2): array
    {
        $queries = array_values(array_unique(array_filter(array_map('trim', $queries), static fn(string $query): bool => $query !== '')));
        if (!$queries) {
            $queries = self::DEFAULT_QUERIES;
        }
        $papers = [];
        $seen = [];
        foreach ($queries as $query) {
            $cursor = '*';
            do {
                $remaining = $limit > 0 ? $limit - count($papers) : 100;
                if ($limit > 0 && $remaining <= 0) {
                    return $papers;
                }
                $filters = ['type:article'];
                if ($from) {
                    $filters[] = 'from_publication_date:' . $from;
                }
                if ($to) {
                    $filters[] = 'to_publication_date:' . $to;
                }
                $params = [
                    'search' => $query,
                    'filter' => implode(',', $filters),
                    'per-page' => (string)min(100, $remaining),
                    'cursor' => $cursor,
                    'sort' => 'publication_date:desc',
                    'select' => implode(',', [
                        'id',
                        'doi',
                        'ids',
                        'display_name',
                        'title',
                        'abstract_inverted_index',
                        'publication_date',
                        'type',
                        'type_crossref',
                        'cited_by_count',
                        'authorships',
                        'primary_location',
                        'concepts',
                        'topics',
                        'keywords',
                        'referenced_works',
                    ]),
                ];
                if (Config::openAlexApiKey()) {
                    $params['api_key'] = Config::openAlexApiKey();
                }
                $data = json_decode($this->http->get(self::BASE . '?' . http_build_query($params)), true);
                $items = $data['results'] ?? [];
                foreach ($items as $item) {
                    $paper = self::paperFromWork($item, $minimumScore);
                    if ($paper === null) {
                        continue;
                    }
                    $key = mb_strtolower((string)($paper['doi'] ?: $paper['pubmed_id'] ?: $paper['openalex_id'] ?: normalize_title((string)$paper['title'])), 'UTF-8');
                    if ($key !== '' && isset($seen[$key])) {
                        continue;
                    }
                    if ($key !== '') {
                        $seen[$key] = true;
                    }
                    $papers[] = $paper;
                }
                $nextCursor = (string)($data['meta']['next_cursor'] ?? '');
                if (!$items || $nextCursor === '' || $nextCursor === $cursor) {
                    break;
                }
                $cursor = $nextCursor;
                usleep(Config::openAlexApiKey() ? 350000 : 900000);
            } while (true);
        }
        return $papers;
    }

    public static function paperFromWork(array $work, int $minimumScore = 1): ?array
    {
        $title = clean_scientific_text((string)($work['display_name'] ?? $work['title'] ?? ''));
        $abstract = clean_scientific_text(self::abstractFromInvertedIndex($work['abstract_inverted_index'] ?? null));
        $concepts = [];
        foreach ((array)($work['concepts'] ?? []) as $concept) {
            if (!empty($concept['display_name'])) {
                $concepts[] = (string)$concept['display_name'];
            }
        }
        foreach ((array)($work['topics'] ?? []) as $topic) {
            if (!empty($topic['display_name'])) {
                $concepts[] = (string)$topic['display_name'];
            }
        }
        foreach ((array)($work['keywords'] ?? []) as $keyword) {
            if (!empty($keyword['display_name'])) {
                $concepts[] = (string)$keyword['display_name'];
            }
        }
        $concepts = array_values(array_unique($concepts));
        $relevance = self::relevanceScore($title, $abstract, $concepts, $work);
        if ($relevance['score'] < $minimumScore || !$relevance['direct_signal']) {
            return null;
        }

        $authors = [];
        $authorRaw = [];
        foreach ((array)($work['authorships'] ?? []) as $authorship) {
            $author = $authorship['author'] ?? [];
            $name = clean_scientific_text((string)($author['display_name'] ?? ''));
            if ($name !== '') {
                $authors[] = $name;
            }
            $authorRaw[] = [
                'id' => $author['id'] ?? null,
                'display_name' => $name ?: null,
                'orcid' => $author['orcid'] ?? null,
            ];
        }

        $doi = normalize_doi($work['doi'] ?? ($work['ids']['doi'] ?? null));
        $pmid = self::pubmedIdFromOpenAlex($work['ids']['pmid'] ?? null);
        $openAlexId = PublicationRepository::normalizeOpenAlexId($work['id'] ?? null);
        $primaryLocation = $work['primary_location'] ?? [];
        $source = $primaryLocation['source'] ?? [];
        $journal = clean_scientific_text((string)($source['display_name'] ?? ''));
        $landingPage = $primaryLocation['landing_page_url'] ?? null;
        $sourceUrl = is_string($landingPage) && $landingPage !== ''
            ? $landingPage
            : ($doi ? 'https://doi.org/' . $doi : ($work['id'] ?? null));

        return [
            'title' => $title,
            'authors' => implode(', ', array_values(array_unique($authors))),
            'abstract' => $abstract,
            'journal' => $journal !== '' ? $journal : null,
            'publication_date' => $work['publication_date'] ?? null,
            'doi' => $doi,
            'pubmed_id' => $pmid,
            'openalex_id' => $openAlexId,
            'source_url' => $sourceUrl,
            'keywords' => implode(', ', $concepts),
            'source_name' => 'OpenAlex',
            'raw' => [
                'openalex_id' => $work['id'] ?? null,
                'openalex_url' => $work['id'] ?? null,
                'openalex_relevance_score' => $relevance['score'],
                'openalex_relevance_reasons' => $relevance['reasons'],
                'openalex_type' => $work['type'] ?? null,
                'openalex_work_type' => $work['type_crossref'] ?? null,
                'cited_by_count' => $work['cited_by_count'] ?? null,
                'referenced_works' => array_values(array_filter((array)($work['referenced_works'] ?? []), 'is_string')),
                'authorships' => $authorRaw,
                'primary_location' => [
                    'source_id' => $source['id'] ?? null,
                    'source_display_name' => $source['display_name'] ?? null,
                    'landing_page_url' => $landingPage,
                    'is_oa' => $primaryLocation['is_oa'] ?? null,
                ],
            ],
        ];
    }

    /** @return array{score:int,reasons:array<int,string>,direct_signal:bool} */
    public static function relevanceScore(string $title, string $abstract, array $concepts, array $work = []): array
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $abstractLower = mb_strtolower($abstract, 'UTF-8');
        $conceptLower = mb_strtolower(implode(' ', $concepts), 'UTF-8');
        $primaryText = $titleLower . ' ' . $abstractLower;
        $all = $titleLower . ' ' . $abstractLower . ' ' . $conceptLower;
        $score = 0;
        $reasons = [];
        $directSignal = false;
        foreach (['psilocybin', 'psilocin', '4-ho-dmt', '4 hydroxy dmt'] as $term) {
            if (str_contains($titleLower, $term)) {
                $score += 4;
                $reasons[] = 'title:' . $term;
                $directSignal = true;
            }
            if (str_contains($abstractLower, $term)) {
                $score += 3;
                $reasons[] = 'abstract:' . $term;
                $directSignal = true;
            }
            if (str_contains($conceptLower, $term)) {
                $score += 2;
                $reasons[] = 'metadata:' . $term;
            }
        }
        if (preg_match('/\b(psilocybe|magic mushroom|psychedelic mushroom)s?\b/u', $primaryText)
            && preg_match('/\b(psilocybin|psilocin|tryptamine)\b/u', $all)) {
            $score += 2;
            $reasons[] = 'contextual-mushroom-match';
            $directSignal = true;
        }
        foreach (['comp360', 'cyb003', 'ele-101'] as $compound) {
            if (str_contains($all, $compound) && preg_match('/\b(psilocybin|psilocin|psychedelic|tryptamine)\b/u', $all)) {
                $score += 2;
                $reasons[] = 'compound:' . $compound;
                $directSignal = true;
            }
        }
        return ['score' => $score, 'reasons' => array_values(array_unique($reasons)), 'direct_signal' => $directSignal];
    }

    private static function abstractFromInvertedIndex(mixed $index): string
    {
        if (!is_array($index)) {
            return '';
        }
        $words = [];
        foreach ($index as $word => $positions) {
            foreach ((array)$positions as $position) {
                if (is_int($position) || ctype_digit((string)$position)) {
                    $words[(int)$position] = (string)$word;
                }
            }
        }
        ksort($words);
        return implode(' ', $words);
    }

    private static function pubmedIdFromOpenAlex(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/\b(\d{5,})\b/', $value, $match)) {
            return $match[1];
        }
        return null;
    }
}
