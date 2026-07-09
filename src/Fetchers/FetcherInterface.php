<?php
declare(strict_types=1);

interface FetcherInterface
{
    public function name(): string;

    /** @return array<int,array<string,mixed>> */
    public function fetch(?string $from = null, ?string $to = null, int $limit = 0): array;
}
