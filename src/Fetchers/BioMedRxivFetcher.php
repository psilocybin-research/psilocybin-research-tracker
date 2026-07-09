<?php
declare(strict_types=1);

final class BioMedRxivFetcher implements FetcherInterface
{
    private const BASE = 'https://api.biorxiv.org/details/';

    public function __construct(private HttpClient $http, private string $server)
    {
        if (!in_array($server, ['medrxiv', 'biorxiv'], true)) {
            throw new InvalidArgumentException('Unsupported bioRxiv API server: ' . $server);
        }
    }

    public function name(): string
    {
        return $this->server === 'medrxiv' ? 'medRxiv' : 'bioRxiv';
    }

    public function fetch(?string $from = null, ?string $to = null, int $limit = 0): array
    {
        if ($from === null && $to === null) {
            return [];
        }
        $from = $from ?: gmdate('Y-m-d', strtotime('-30 days'));
        $to = $to ?: gmdate('Y-m-d');
        $papers = [];
        $seen = [];
        $cursor = 0;

        do {
            $url = self::BASE . $this->server . '/' . rawurlencode($from) . '/' . rawurlencode($to) . '/' . $cursor . '/json';
            $data = json_decode($this->http->get($url), true);
            $items = $data['collection'] ?? [];
            foreach ($items as $item) {
                $paper = $this->paperFromItem($item);
                if ($paper === null) {
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

            $returned = count($items);
            $total = (int)($data['messages'][0]['total'] ?? $returned);
            $cursor += $returned > 0 ? $returned : 100;
            if ($returned < 1 || $cursor >= $total) {
                break;
            }
            usleep(250000);
        } while (true);

        return $papers;
    }

    public function paperFromItem(array $item): ?array
    {
        $title = clean_scientific_text((string)($item['title'] ?? ''));
        $abstract = clean_scientific_text((string)($item['abstract'] ?? ''));
        $keywords = clean_scientific_text((string)($item['category'] ?? ''));
        if (PublicationRepository::detectSubstances($title . ' ' . $abstract . ' ' . $keywords) === '') {
            return null;
        }
        $doi = normalize_doi($item['doi'] ?? null);
        $sourceUrl = $doi ? 'https://doi.org/' . $doi : ($item['jatsxml'] ?? null);
        return [
            'title' => $title,
            'authors' => clean_scientific_text((string)($item['authors'] ?? '')),
            'abstract' => $abstract,
            'journal' => $this->name(),
            'publication_date' => $item['date'] ?? null,
            'doi' => $doi,
            'pubmed_id' => null,
            'source_url' => $sourceUrl,
            'keywords' => $keywords,
            'source_name' => $this->name(),
            'publication_status' => 'preprint',
            'raw' => [
                'server' => $this->server,
                'version' => $item['version'] ?? null,
                'category' => $item['category'] ?? null,
                'type' => $item['type'] ?? null,
            ],
        ];
    }
}
