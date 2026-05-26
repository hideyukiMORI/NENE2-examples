<?php

declare(strict_types=1);

namespace ImportLog\Import;

final class CsvImportResult
{
    /** @param list<array{row: int, value: string, error: string}> $errors */
    public function __construct(
        public readonly int $importJobId,
        public readonly int $totalRows,
        public readonly int $importedRows,
        public readonly int $failedRows,
        public readonly array $errors,
        public readonly string $createdAt,
        public readonly string $completedAt,
    ) {}
}
