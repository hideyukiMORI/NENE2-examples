<?php

declare(strict_types=1);

namespace ImportLog\Import;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ImportRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    /**
     * @param list<array{row: int, value: string, error: string}> $errors
     */
    public function createJob(
        string $filename,
        int $totalRows,
        int $importedRows,
        int $failedRows,
        array $errors,
        string $now,
    ): int {
        return $this->db->insert(
            'INSERT INTO import_jobs (filename, status, total_rows, imported_rows, failed_rows, errors, created_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $filename,
                'completed',
                $totalRows,
                $importedRows,
                $failedRows,
                json_encode($errors),
                $now,
                $now,
            ],
        );
    }

    public function insertRecord(int $jobId, string $name, string $email, ?int $age, string $now): void
    {
        $this->db->insert(
            'INSERT INTO imported_records (import_job_id, name, email, age, created_at) VALUES (?, ?, ?, ?, ?)',
            [$jobId, $name, $email, $age, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function findJob(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, filename, status, total_rows, imported_rows, failed_rows, errors, created_at, completed_at
             FROM import_jobs WHERE id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listJobs(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, filename, status, total_rows, imported_rows, failed_rows, errors, created_at, completed_at
             FROM import_jobs ORDER BY id DESC',
        );
    }

    /** @return list<array<string, mixed>> */
    public function listRecords(int $jobId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, import_job_id, name, email, age, created_at FROM imported_records WHERE import_job_id = ? ORDER BY id ASC',
            [$jobId],
        );
    }
}
