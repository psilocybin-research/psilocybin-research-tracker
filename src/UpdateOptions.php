<?php
declare(strict_types=1);

final class UpdateOptions
{
    /** @return array{from:?string,to:?string,limit:int,mode:string,sources:array<int,string>} */
    public static function parse(array $argv): array
    {
        $from = gmdate('Y-m-d', strtotime('-7 days'));
        $to = null;
        $limit = 0;
        $mode = 'daily';
        $sources = [];

        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--from=')) {
                $from = substr($arg, 7);
                $mode = 'custom';
            } elseif (str_starts_with($arg, '--to=')) {
                $to = substr($arg, 5);
            } elseif (str_starts_with($arg, '--limit=')) {
                $limit = max(1, (int)substr($arg, 8));
            } elseif (str_starts_with($arg, '--max=')) {
                $limit = max(1, (int)substr($arg, 6));
            } elseif ($arg === '--daily') {
                $from = gmdate('Y-m-d', strtotime('-7 days'));
                $to = gmdate('Y-m-d');
                $mode = 'daily';
            } elseif ($arg === '--backfill') {
                $from = null;
                $to = null;
                $mode = 'backfill';
            } elseif (str_starts_with($arg, '--source=')) {
                $source = trim(substr($arg, 9));
                if ($source !== '') {
                    $sources[] = $source;
                }
            }
        }

        return ['from' => $from, 'to' => $to, 'limit' => $limit, 'mode' => $mode, 'sources' => array_values(array_unique($sources))];
    }
}
