<?php
declare(strict_types=1);

final class DepressionEvidence
{
    public static function collection(Database $db): array
    {
        return ResearchEvidence::collection($db, 'depression');
    }
}
