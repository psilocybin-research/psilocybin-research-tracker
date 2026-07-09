<?php
declare(strict_types=1);

final class CrossrefFetcher implements FetcherInterface
{
    public function __construct(private HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'Crossref';
    }

    public function fetch(?string $from = null, ?string $to = null, int $limit = 0): array
    {
        $filters = ['type:journal-article'];
        if ($from) {
            $filters[] = 'from-pub-date:' . $from;
        }
        if ($to) {
            $filters[] = 'until-pub-date:' . $to;
        }
        $queries = ['psilocybin', 'psilocin', '4-HO-DMT'];
        $papers = [];
        $seen = [];
        foreach ($queries as $query) {
            $cursor = '*';
            $fetchedForQuery = 0;
            do {
                $pageSize = 100;
                $remaining = $limit > 0 ? $limit - count($papers) : $pageSize;
                if ($limit > 0 && $remaining <= 0) {
                    return $papers;
                }
                $url = 'https://api.crossref.org/works?' . http_build_query([
                    'query.bibliographic' => $query,
                    'filter' => implode(',', $filters),
                    'rows' => (string)min($pageSize, $remaining),
                    'cursor' => $cursor,
                    'sort' => 'published',
                    'order' => 'desc',
                ]);
                $data = json_decode($this->http->get($url), true);
                $items = $data['message']['items'] ?? [];
                $nextCursor = (string)($data['message']['next-cursor'] ?? '');
                foreach ($items as $item) {
                    $key = strtolower((string)($item['DOI'] ?? ($item['title'][0] ?? '')));
                    if ($key !== '' && isset($seen[$key])) {
                        continue;
                    }
                    if ($key !== '') {
                        $seen[$key] = true;
                    }
                    $paper = $this->paperFromItem($item);
                    if ($paper !== null) {
                        $papers[] = $paper;
                    }
                }
                $fetchedForQuery += count($items);
                if (!$items || $nextCursor === '' || $nextCursor === $cursor) {
                    break;
                }
                $cursor = $nextCursor;
                usleep(250000);
            } while (true);
        }
        return $papers;
    }

    private function paperFromItem(array $item): ?array
    {
            $title = clean_scientific_text((string)($item['title'][0] ?? ''));
            $abstract = isset($item['abstract']) ? clean_scientific_text((string)$item['abstract']) : '';
            $haystack = $title . ' ' . $abstract . ' ' . implode(' ', $item['subject'] ?? []);
            if (PublicationRepository::detectSubstances($haystack) === '') {
                return null;
            }
            $authors = [];
            foreach ($item['author'] ?? [] as $author) {
                $name = trim(($author['family'] ?? '') . ' ' . ($author['given'] ?? ''));
                if ($name !== '') {
                    $authors[] = $name;
                }
            }
            $dateParts = $item['published-print']['date-parts'][0] ?? $item['published-online']['date-parts'][0] ?? $item['published']['date-parts'][0] ?? [];
            $date = $this->dateFromParts($dateParts);
            $doi = $item['DOI'] ?? null;
            $referenceDois = [];
            foreach ($item['reference'] ?? [] as $reference) {
                $referenceDoi = normalize_doi($reference['DOI'] ?? null);
                if ($referenceDoi !== null) {
                    $referenceDois[] = $referenceDoi;
                }
            }
            $referenceDois = array_values(array_unique($referenceDois));
            return [
                'title' => $title,
                'authors' => implode(', ', $authors),
                'abstract' => $abstract,
                'journal' => $item['container-title'][0] ?? null,
                'publication_date' => $date,
                'doi' => $doi,
                'pubmed_id' => null,
                'source_url' => isset($item['URL']) ? (string)$item['URL'] : ($doi ? 'https://doi.org/' . $doi : null),
                'keywords' => implode(', ', $item['subject'] ?? []),
                'source_name' => $this->name(),
                'raw' => [
                    'doi' => normalize_doi($doi),
                    'reference_dois' => $referenceDois,
                    'reference_count' => count($item['reference'] ?? []),
                ],
            ];
    }

    private function dateFromParts(array $parts): ?string
    {
        if (!$parts) {
            return null;
        }
        $year = (int)($parts[0] ?? 0);
        if ($year < 1800) {
            return null;
        }
        $month = (int)($parts[1] ?? 1);
        $day = (int)($parts[2] ?? 1);
        return sprintf('%04d-%02d-%02d', $year, max(1, $month), max(1, $day));
    }
}
