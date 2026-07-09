<?php
declare(strict_types=1);

final class PsyArXivFetcher implements FetcherInterface
{
    private const BASE = 'https://api.osf.io/v2/preprints/';

    public function __construct(private HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'PsyArXiv';
    }

    public function fetch(?string $from = null, ?string $to = null, int $limit = 0): array
    {
        $papers = [];
        $seen = [];
        foreach (['title', 'description'] as $field) {
            foreach (['psilocybin', 'psilocin', '4-HO-DMT'] as $term) {
                $url = self::BASE . '?' . http_build_query([
                    'filter[provider]' => 'psyarxiv',
                    'filter[' . $field . ']' => $term,
                    'page[size]' => '100',
                ]);
                do {
                    $data = json_decode($this->http->get($url), true);
                    foreach (($data['data'] ?? []) as $item) {
                        $paper = self::paperFromPreprint($item);
                        if ($paper === null || !$this->dateInRange($paper['publication_date'] ?? null, $from, $to)) {
                            continue;
                        }
                        $key = strtolower((string)($paper['doi'] ?: normalize_title((string)$paper['title'])));
                        if ($key !== '' && isset($seen[$key])) {
                            continue;
                        }
                        if ($key !== '') {
                            $seen[$key] = true;
                        }
                        $papers[] = $paper;
                        if ($limit > 0 && count($papers) >= $limit) {
                            return $papers;
                        }
                    }
                    $url = (string)($data['links']['next'] ?? '');
                    usleep(250000);
                } while ($url !== '');
            }
        }

        return $papers;
    }

    public static function paperFromPreprint(array $item): ?array
    {
        $attributes = $item['attributes'] ?? [];
        $title = clean_scientific_text((string)($attributes['title'] ?? ''));
        $abstract = clean_scientific_text((string)($attributes['description'] ?? ''));
        $tags = array_map('strval', (array)($attributes['tags'] ?? []));
        $subjects = [];
        foreach ((array)($attributes['subjects'] ?? []) as $path) {
            foreach ((array)$path as $subject) {
                if (!empty($subject['text'])) {
                    $subjects[] = (string)$subject['text'];
                }
            }
        }
        if (PublicationRepository::detectSubstances($title . ' ' . $abstract . ' ' . implode(' ', $tags) . ' ' . implode(' ', $subjects)) === '') {
            return null;
        }
        $doi = normalize_doi($attributes['doi'] ?? null);
        $date = $attributes['date_published'] ?? $attributes['original_publication_date'] ?? $attributes['date_created'] ?? null;
        return [
            'title' => $title,
            'authors' => '',
            'abstract' => $abstract,
            'journal' => 'PsyArXiv',
            'publication_date' => $date ? substr((string)$date, 0, 10) : null,
            'doi' => $doi,
            'pubmed_id' => null,
            'source_url' => 'https://osf.io/preprints/psyarxiv/' . rawurlencode((string)($item['id'] ?? '')),
            'keywords' => implode(', ', array_values(array_unique(array_merge($tags, $subjects)))),
            'source_name' => 'PsyArXiv',
            'publication_status' => 'preprint',
            'raw' => [
                'osf_id' => $item['id'] ?? null,
                'version' => $attributes['version'] ?? null,
                'reviews_state' => $attributes['reviews_state'] ?? null,
            ],
        ];
    }

    private function dateInRange(?string $date, ?string $from, ?string $to): bool
    {
        $date = parse_date_or_null($date);
        if ($date === null) {
            return true;
        }
        return (!$from || $date >= $from) && (!$to || $date <= $to);
    }
}
