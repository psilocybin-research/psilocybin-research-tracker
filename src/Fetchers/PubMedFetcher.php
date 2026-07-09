<?php
declare(strict_types=1);

final class PubMedFetcher implements FetcherInterface
{
    private const BASE = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/';

    public function __construct(private HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'PubMed';
    }

    public function fetch(?string $from = null, ?string $to = null, int $limit = 0): array
    {
        $terms = '("psilocybin"[Title/Abstract] OR "psilocin"[Title/Abstract] OR "4-HO-DMT"[Title/Abstract] OR ("magic mushroom"[Title/Abstract] AND (psychedelic[Title/Abstract] OR hallucinogenic[Title/Abstract] OR psilocybe[Title/Abstract])))';
        $params = [
            'db' => 'pubmed',
            'term' => $terms,
            'retmode' => 'json',
            'retmax' => '0',
            'sort' => 'pub date',
        ];
        if ($from || $to) {
            $params['datetype'] = 'pdat';
            if ($from) {
                $params['mindate'] = $from;
            }
            if ($to) {
                $params['maxdate'] = $to;
            }
        }
        if (Config::ncbiEmail()) {
            $params['email'] = Config::ncbiEmail();
        }
        if (Config::ncbiApiKey()) {
            $params['api_key'] = Config::ncbiApiKey();
        }

        $search = json_decode($this->http->get(self::BASE . 'esearch.fcgi?' . http_build_query($params)), true);
        $count = (int)($search['esearchresult']['count'] ?? 0);
        if ($count < 1) {
            return [];
        }

        $papers = [];
        $pageSize = 100;
        $target = $limit > 0 ? min($count, $limit) : $count;
        for ($start = 0; $start < $target; $start += $pageSize) {
            $pageParams = $params;
            $pageParams['retmax'] = (string)min($pageSize, $target - $start);
            $pageParams['retstart'] = (string)$start;
            $pageSearch = json_decode($this->http->get(self::BASE . 'esearch.fcgi?' . http_build_query($pageParams)), true);
            $ids = $pageSearch['esearchresult']['idlist'] ?? [];
            if (!$ids) {
                break;
            }

            $xml = $this->http->get(self::BASE . 'efetch.fcgi?' . http_build_query([
                'db' => 'pubmed',
                'id' => implode(',', $ids),
                'retmode' => 'xml',
            ]));
            $doc = new SimpleXMLElement($xml);
            foreach ($doc->PubmedArticle as $article) {
                $papers[] = $this->paperFromArticle($article);
            }
            usleep(350000);
        }
        return $papers;
    }

    /** @param array<int,string> $ids */
    public function fetchByIds(array $ids): array
    {
        $papers = [];
        $ids = array_values(array_filter(array_unique(array_map('strval', $ids))));
        foreach (array_chunk($ids, 100) as $chunk) {
            $xml = $this->http->get(self::BASE . 'efetch.fcgi?' . http_build_query([
                'db' => 'pubmed',
                'id' => implode(',', $chunk),
                'retmode' => 'xml',
            ]));
            $doc = new SimpleXMLElement($xml);
            foreach ($doc->PubmedArticle as $article) {
                $papers[] = $this->paperFromArticle($article);
            }
            usleep(350000);
        }
        return $papers;
    }

    private function paperFromArticle(SimpleXMLElement $article): array
    {
        $medline = $article->MedlineCitation;
        $pubmedId = (string)$medline->PMID;
        $journal = (string)($medline->Article->Journal->Title ?? '');
        $title = clean_scientific_text((string)$medline->Article->ArticleTitle);
        $abstractParts = [];
        foreach ($medline->Article->Abstract->AbstractText ?? [] as $part) {
            $abstractParts[] = clean_scientific_text((string)$part);
        }
        $authors = [];
        foreach ($medline->Article->AuthorList->Author ?? [] as $author) {
            $last = (string)($author->LastName ?? '');
            $initials = (string)($author->Initials ?? '');
            $collective = (string)($author->CollectiveName ?? '');
            $name = trim($collective ?: trim($last . ' ' . $initials));
            if ($name !== '') {
                $authors[] = $name;
            }
        }
        $doi = null;
        foreach ($article->PubmedData->ArticleIdList->ArticleId ?? [] as $id) {
            if ((string)$id['IdType'] === 'doi') {
                $doi = (string)$id;
            }
        }
        $keywords = [];
        foreach ($medline->KeywordList->Keyword ?? [] as $keyword) {
            $keywords[] = (string)$keyword;
        }
        return [
            'title' => $title,
            'authors' => implode(', ', $authors),
            'abstract' => implode("\n", array_filter($abstractParts)),
            'journal' => $journal,
            'publication_date' => $this->dateFromArticle($medline->Article),
            'doi' => $doi,
            'pubmed_id' => $pubmedId,
            'source_url' => 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode($pubmedId) . '/',
            'keywords' => implode(', ', $keywords),
            'source_name' => $this->name(),
            'raw' => ['pubmed_id' => $pubmedId],
        ];
    }

    private function dateFromArticle(SimpleXMLElement $article): ?string
    {
        $date = $article->Journal->JournalIssue->PubDate ?? null;
        if (!$date) {
            return null;
        }
        $year = (string)($date->Year ?? '');
        $month = (string)($date->Month ?? '01');
        $day = (string)($date->Day ?? '01');
        if ($year === '' && isset($date->MedlineDate)) {
            preg_match('/\d{4}/', (string)$date->MedlineDate, $m);
            $year = $m[0] ?? '';
        }
        if ($year === '') {
            return null;
        }
        $monthMap = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'];
        $month = $monthMap[substr($month, 0, 3)] ?? str_pad(preg_replace('/\D/', '', $month) ?: '1', 2, '0', STR_PAD_LEFT);
        $day = str_pad(preg_replace('/\D/', '', $day) ?: '1', 2, '0', STR_PAD_LEFT);
        return $year . '-' . $month . '-' . $day;
    }
}
