<?php
declare(strict_types=1);

final class ExportService
{
    public const TRACKER_SITE_URL = 'https://psilocybin-research.com';

    public static function csv(array $papers): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['title', 'authors', 'journal', 'publication_date', 'doi', 'pubmed_id', 'source_url', 'source_name', 'publication_status', 'keywords', 'substance_tags', 'topic_tags', 'study_type', 'tracker_site_url']);
        foreach ($papers as $paper) {
            fputcsv($stream, [
                $paper['title'], $paper['authors'], $paper['journal'], $paper['publication_date'], $paper['doi'], $paper['pubmed_id'],
                $paper['source_url'], $paper['source_name'] ?? '', $paper['publication_status'] ?? 'published', $paper['keywords'], $paper['substance_tags'], $paper['topic_tags'] ?? '', $paper['study_type'] ?? '', self::TRACKER_SITE_URL,
            ]);
        }
        rewind($stream);
        return stream_get_contents($stream) ?: '';
    }

    public static function json(array $papers): string
    {
        return json_encode([
            'meta' => [
                'tracker_site_url' => self::TRACKER_SITE_URL,
                'publication_tracker_url' => Config::publicBaseUrl(),
                'generated_at_utc' => current_utc(),
                'record_count' => count($papers),
            ],
            'papers' => $papers,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"papers":[]}';
    }

    public static function bibtex(array $papers): string
    {
        $entries = [
            '% Exported from Psilocybin Research Publication Tracker',
            '% Tracker site: ' . self::TRACKER_SITE_URL,
            '% Publication tracker: ' . Config::publicBaseUrl(),
            '',
        ];
        foreach ($papers as $paper) {
            $key = self::citationKey($paper);
            $fields = [
                'title' => $paper['title'] ?? '',
                'author' => str_replace(',', ' and', (string)($paper['authors'] ?? '')),
                'journal' => $paper['journal'] ?? '',
                'year' => $paper['publication_year'] ?? substr((string)($paper['publication_date'] ?? ''), 0, 4),
                'doi' => $paper['doi'] ?? '',
                'url' => $paper['source_url'] ?? '',
                'pmid' => $paper['pubmed_id'] ?? '',
            ];
            $lines = ['@article{' . $key . ','];
            foreach ($fields as $field => $value) {
                if ((string)$value !== '') {
                    $lines[] = '  ' . $field . ' = {' . self::escapeBib((string)$value) . '},';
                }
            }
            $lines[] = '}';
            $entries[] = implode("\n", $lines);
        }
        return implode("\n\n", $entries) . "\n";
    }

    public static function ris(array $papers): string
    {
        $entries = [];
        foreach ($papers as $paper) {
            $lines = ['TY  - JOUR'];
            $lines[] = 'N1  - Exported from Psilocybin Research Publication Tracker: ' . self::TRACKER_SITE_URL;
            foreach (array_filter(array_map('trim', explode(',', (string)($paper['authors'] ?? '')))) as $author) {
                $lines[] = 'AU  - ' . $author;
            }
            $lines[] = 'TI  - ' . ($paper['title'] ?? '');
            if (!empty($paper['journal'])) $lines[] = 'JO  - ' . $paper['journal'];
            if (!empty($paper['publication_date'])) $lines[] = 'PY  - ' . substr((string)$paper['publication_date'], 0, 4);
            if (!empty($paper['doi'])) $lines[] = 'DO  - ' . $paper['doi'];
            if (!empty($paper['pubmed_id'])) $lines[] = 'AN  - ' . $paper['pubmed_id'];
            if (!empty($paper['source_url'])) $lines[] = 'UR  - ' . $paper['source_url'];
            $lines[] = 'ER  -';
            $entries[] = implode("\n", $lines);
        }
        return implode("\n\n", $entries) . "\n";
    }

    public static function latex(array $papers): string
    {
        $lines = [
            '% Exported from Psilocybin Research Publication Tracker',
            '% Tracker site: ' . self::TRACKER_SITE_URL,
            '% Publication tracker: ' . Config::publicBaseUrl(),
            '\\documentclass[11pt]{article}',
            '\\usepackage[T1]{fontenc}',
            '\\usepackage[utf8]{inputenc}',
            '\\usepackage{enumitem}',
            '\\usepackage[hidelinks]{hyperref}',
            '\\usepackage[margin=1in]{geometry}',
            '\\title{Psilocybin and Psilocin Research Export}',
            '\\author{Psilocybin Research Publication Tracker}',
            '\\date{' . self::escapeLatex(gmdate('Y-m-d')) . '}',
            '\\begin{document}',
            '\\maketitle',
            '\\noindent Exported from \\href{' . self::escapeLatex(self::TRACKER_SITE_URL) . '}{psilocybin-research.com}. Records: ' . count($papers) . '.',
            '',
            '\\begin{enumerate}[leftmargin=*]',
        ];

        foreach ($papers as $paper) {
            $year = substr((string)($paper['publication_date'] ?? ''), 0, 4) ?: 'n.d.';
            $authors = trim((string)($paper['authors'] ?? '')) ?: 'Unknown authors';
            $journal = trim((string)($paper['journal'] ?? '')) ?: 'Unknown journal';
            $status = trim((string)($paper['publication_status'] ?? 'published')) ?: 'published';
            $sourceName = trim((string)($paper['source_name'] ?? '')) ?: 'unknown source';
            $doi = trim((string)($paper['doi'] ?? ''));
            $url = trim((string)($paper['source_url'] ?? ''));
            $identifier = $doi !== ''
                ? '\\href{https://doi.org/' . self::escapeLatex($doi) . '}{doi:' . self::escapeLatex($doi) . '}'
                : ($url !== '' ? '\\href{' . self::escapeLatex($url) . '}{source link}' : '');
            $tail = $identifier !== '' ? ' ' . $identifier . '.' : '';
            $lines[] = '\\item ' . self::escapeLatex($authors) . ' (' . self::escapeLatex($year) . '). \\textit{' . self::escapeLatex((string)($paper['title'] ?? 'Untitled publication')) . '}. ' . self::escapeLatex($journal) . '. [' . self::escapeLatex($status) . '; ' . self::escapeLatex($sourceName) . '].' . $tail;
        }

        $lines[] = '\\end{enumerate}';
        $lines[] = '\\end{document}';
        return implode("\n", $lines) . "\n";
    }

    public static function citationText(array $paper): string
    {
        $year = substr((string)($paper['publication_date'] ?? ''), 0, 4) ?: 'n.d.';
        $authors = $paper['authors'] ?: 'Unknown authors';
        $journal = $paper['journal'] ?: 'Unknown journal';
        $doi = $paper['doi'] ? ' https://doi.org/' . $paper['doi'] : '';
        return $authors . ' (' . $year . '). ' . $paper['title'] . '. ' . $journal . '.' . $doi;
    }

    private static function citationKey(array $paper): string
    {
        $author = preg_split('/[\s,]+/', (string)($paper['authors'] ?? 'paper'))[0] ?? 'paper';
        $year = substr((string)($paper['publication_date'] ?? '0000'), 0, 4);
        return preg_replace('/[^A-Za-z0-9_:-]/', '', $author . $year . 'p' . ($paper['id'] ?? 'x')) ?: 'paper';
    }

    private static function escapeBib(string $value): string
    {
        return str_replace(['\\', '{', '}'], ['\\\\', '\\{', '\\}'], $value);
    }

    private static function escapeLatex(string $value): string
    {
        return str_replace(
            ['\\', '&', '%', '$', '#', '_', '{', '}', '~', '^'],
            ['\\textbackslash{}', '\\&', '\\%', '\\$', '\\#', '\\_', '\\{', '\\}', '\\textasciitilde{}', '\\textasciicircum{}'],
            $value
        );
    }
}
