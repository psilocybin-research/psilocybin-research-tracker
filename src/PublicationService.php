<?php
declare(strict_types=1);

final class PublicationService
{
    /** @param array<int,FetcherInterface> $fetchers */
    public function __construct(
        private PublicationRepository $publications,
        private FetchRunRepository $runs,
        private array $fetchers
    ) {
    }

    public static function create(Database $db): self
    {
        $http = new HttpClient();
        return new self(
            new PublicationRepository($db),
            new FetchRunRepository($db),
            [
                new PubMedFetcher($http),
                new CrossrefFetcher($http),
                new EuropePmcFetcher($http),
                new OpenAlexFetcher($http),
                new BioMedRxivFetcher($http, 'medrxiv'),
                new BioMedRxivFetcher($http, 'biorxiv'),
                new PsyArXivFetcher($http),
                new ClinicalTrialsFetcher($http),
            ]
        );
    }

    /** @param array<int,string> $sources */
    public function refresh(?string $from = null, ?string $to = null, int $limit = 0, array $sources = []): array
    {
        $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
        $sourceFilter = array_map(
            fn(string $source): string => self::normalizeSourceName($source),
            array_filter(array_map('strval', $sources), fn(string $source): bool => trim($source) !== '')
        );
        foreach ($this->fetchers as $fetcher) {
            if ($sourceFilter && !in_array(self::normalizeSourceName($fetcher->name()), $sourceFilter, true)) {
                continue;
            }
            $runId = $this->runs->start($fetcher->name());
            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;
            try {
                foreach ($fetcher->fetch($from, $to, $limit) as $paper) {
                    try {
                        $result = $this->publications->upsert($paper);
                        if ($result === 'inserted') {
                            $inserted++;
                        } elseif ($result === 'updated') {
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    } catch (Throwable $e) {
                        $errors++;
                        $title = isset($paper['title']) ? ' [' . mb_substr((string)$paper['title'], 0, 160) . ']' : '';
                        $this->runs->error($runId, $fetcher->name(), $e->getMessage() . $title);
                    }
                }
                $this->runs->finish($runId, $errors ? 'partial' : 'ok', $inserted, $updated, $skipped, $errors, 'Refresh completed');
            } catch (Throwable $e) {
                $errors++;
                $this->runs->error($runId, $fetcher->name(), $e->getMessage());
                $this->runs->finish($runId, 'error', $inserted, $updated, $skipped, $errors, $e->getMessage());
            }
            $summary['inserted'] += $inserted;
            $summary['updated'] += $updated;
            $summary['skipped'] += $skipped;
            $summary['errors'] += $errors;
            $summary['messages'][] = $fetcher->name() . ': +' . $inserted . ' / updated ' . $updated . ' / skipped ' . $skipped . ($errors ? ' / errors ' . $errors : '');
        }
        if ($sourceFilter && $summary['messages'] === []) {
            $summary['errors']++;
            $summary['messages'][] = 'No fetcher matched --source=' . implode(',', $sources);
        }
        return $summary;
    }

    private static function normalizeSourceName(string $source): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($source), 'UTF-8')) ?? '';
    }
}
