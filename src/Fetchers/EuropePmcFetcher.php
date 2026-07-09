<?php
declare(strict_types=1);

final class EuropePmcFetcher implements FetcherInterface
{
    private const BASE = 'https://www.ebi.ac.uk/europepmc/webservices/rest/search';

    public function __construct(private HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'Europe PMC';
    }

    public function fetch(?string $from = null, ?string $to = null, int $limit = 0): array
    {
        $query = '(TITLE:"psilocybin" OR ABSTRACT:"psilocybin" OR TITLE:"psilocin" OR ABSTRACT:"psilocin" OR TITLE:"4-HO-DMT" OR ABSTRACT:"4-HO-DMT")';
        if ($from || $to) {
            $query .= ' AND FIRST_PDATE:[' . ($from ?: '1900-01-01') . ' TO ' . ($to ?: gmdate('Y-m-d')) . ']';
        }

        $papers = [];
        $seen = [];
        $cursor = '*';
        do {
            $remaining = $limit > 0 ? $limit - count($papers) : 100;
            if ($limit > 0 && $remaining <= 0) {
                break;
            }
            $url = self::BASE . '?' . http_build_query([
                'query' => $query,
                'format' => 'json',
                'resultType' => 'core',
                'pageSize' => (string)min(100, $remaining),
                'cursorMark' => $cursor,
            ]);
            $data = json_decode($this->http->get($url), true);
            $items = $data['resultList']['result'] ?? [];
            foreach ($items as $item) {
                $paper = self::paperFromResult($item);
                if ($paper === null) {
                    continue;
                }
                $key = strtolower((string)($paper['doi'] ?: $paper['pubmed_id'] ?: normalize_title((string)$paper['title'])));
                if ($key !== '' && isset($seen[$key])) {
                    continue;
                }
                if ($key !== '') {
                    $seen[$key] = true;
                }
                $papers[] = $paper;
            }
            $nextCursor = (string)($data['nextCursorMark'] ?? '');
            if (!$items || $nextCursor === '' || $nextCursor === $cursor) {
                break;
            }
            $cursor = $nextCursor;
            usleep(250000);
        } while (true);

        return $papers;
    }

    public static function paperFromResult(array $item): ?array
    {
        $title = clean_scientific_text((string)($item['title'] ?? ''));
        $abstract = clean_scientific_text((string)($item['abstractText'] ?? ''));
        $keywords = [];
        foreach ((array)($item['meshHeadingList']['meshHeading'] ?? []) as $heading) {
            if (!empty($heading['descriptorName'])) {
                $keywords[] = (string)$heading['descriptorName'];
            }
        }
        $haystack = $title . ' ' . $abstract . ' ' . implode(' ', $keywords);
        if (PublicationRepository::detectSubstances($haystack) === '') {
            return null;
        }

        $pubType = mb_strtolower((string)($item['pubType'] ?? ''), 'UTF-8');
        $publisher = trim((string)($item['bookOrReportDetails']['publisher'] ?? ''));
        $sourceName = self::sourceNameForResult((string)($item['source'] ?? ''), $publisher);
        $status = 'published';
        if (str_contains($pubType, 'preprint') || ($item['source'] ?? '') === 'PPR') {
            $status = 'preprint';
        } elseif (str_contains($pubType, 'clinical trial')) {
            $status = 'clinical trial';
        } elseif (str_contains($pubType, 'protocol')) {
            $status = 'protocol';
        } elseif (str_contains($pubType, 'review')) {
            $status = 'review';
        }

        $doi = normalize_doi($item['doi'] ?? null);
        $pmid = !empty($item['pmid']) ? (string)$item['pmid'] : null;
        return [
            'title' => $title,
            'authors' => clean_scientific_text((string)($item['authorString'] ?? '')),
            'abstract' => $abstract,
            'journal' => $item['journalTitle'] ?? $item['bookOrReportDetails']['publisher'] ?? null,
            'publication_date' => $item['firstPublicationDate'] ?? $item['pubYear'] ?? null,
            'doi' => $doi,
            'pubmed_id' => $pmid,
            'source_url' => $doi ? 'https://doi.org/' . $doi : 'https://europepmc.org/article/' . rawurlencode((string)($item['source'] ?? '')) . '/' . rawurlencode((string)($item['id'] ?? '')),
            'keywords' => implode(', ', array_values(array_unique($keywords))),
            'source_name' => $sourceName,
            'publication_status' => $status,
            'raw' => [
                'europe_pmc_id' => $item['id'] ?? null,
                'source' => $item['source'] ?? null,
                'pub_type' => $item['pubType'] ?? null,
                'publisher' => $publisher ?: null,
                'importer' => 'Europe PMC',
            ],
        ];
    }

    private static function sourceNameForResult(string $source, string $publisher): string
    {
        $publisherLower = mb_strtolower($publisher, 'UTF-8');
        if ($source === 'PPR') {
            if (str_contains($publisherLower, 'biorxiv')) {
                return 'bioRxiv';
            }
            if (str_contains($publisherLower, 'medrxiv')) {
                return 'medRxiv';
            }
            if (str_contains($publisherLower, 'psyarxiv')) {
                return 'PsyArXiv';
            }
        }
        return 'Europe PMC';
    }
}
