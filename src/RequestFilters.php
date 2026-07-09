<?php
declare(strict_types=1);

final class RequestFilters
{
    public static function fromGlobals(): array
    {
        $range = request_value('range', 'all');
        $from = request_value('from');
        $to = request_value('to');
        if (!$from && $range !== 'custom') {
            $from = match ($range) {
                'month' => gmdate('Y-m-d', strtotime('-1 month')),
                'year' => gmdate('Y-m-d', strtotime('-1 year')),
                'all' => null,
                '5y' => gmdate('Y-m-d', strtotime('-5 years')),
                default => null,
            };
        }
        return [
            'q' => request_value('search', request_value('q')),
            'author' => request_value('author'),
            'substances' => request_array('substances') ?: ['psilocybin', 'psilocin'],
            'topic' => request_value('topic'),
            'study_type' => request_value('study_type'),
            'cited_doi' => request_value('cited_doi'),
            'sources' => request_array('sources'),
            'publication_statuses' => request_array('publication_statuses'),
            'year' => request_value('year'),
            'journal' => request_value('journal'),
            'from' => $from,
            'to' => $to,
            'added_from' => request_value('added_from'),
            'added_to' => request_value('added_to'),
            'sort' => request_value('sort', 'newest'),
            'page' => (int)request_value('page', '1'),
            'per_page' => request_value('per_page', '20'),
        ];
    }
}
