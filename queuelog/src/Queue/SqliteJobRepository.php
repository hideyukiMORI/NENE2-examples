<?php

declare(strict_types=1);

namespace Queue;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class SqliteJobRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    /**
     * Create a new job. If idempotency_key is provided and a job with that key
     * already exists, the existing job is returned without creating a duplicate.
     */
    public function create(
        string     $type,
        string     $payload,
        JobPriority $priority,
        string     $now,
        ?string    $idempotencyKey = null,
        int        $maxRetries = 3,
    ): Job {
        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        $this->executor->execute(
            'INSERT INTO jobs (type, payload, priority, status, retry_count, max_retries, idempotency_key, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)',
            [$type, $payload, $priority->value, JobStatus::Pending->value, $maxRetries, $idempotencyKey, $now, $now],
        );
        $id = (int) $this->executor->lastInsertId();

        return new Job(
            $id,
            $type,
            $payload,
            $priority,
            JobStatus::Pending,
            0,
            $maxRetries,
            $idempotencyKey,
            null,
            null,
            null,
            $now,
            $now,
        );
    }

    public function findById(int $id): ?Job
    {
        $rows = $this->executor->fetchAll('SELECT * FROM jobs WHERE id = ?', [$id]);
        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    public function findByIdempotencyKey(string $key): ?Job
    {
        $rows = $this->executor->fetchAll('SELECT * FROM jobs WHERE idempotency_key = ?', [$key]);
        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * Claim the highest-priority pending job.
     * Priority DESC, created_at ASC — highest priority first, oldest first on tie.
     */
    public function claim(string $workerId, string $now): ?Job
    {
        $rows = $this->executor->fetchAll(
            "SELECT * FROM jobs WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 1",
            [],
        );
        if ($rows === []) {
            return null;
        }

        $id = (int) $rows[0]['id'];
        $this->executor->execute(
            "UPDATE jobs SET status = 'running', claimed_at = ?, worker_id = ?, updated_at = ? WHERE id = ?",
            [$now, $workerId, $now, $id],
        );

        return $this->findById($id);
    }

    public function complete(int $id, string $now): ?Job
    {
        $job = $this->findById($id);
        if ($job === null || $job->status !== JobStatus::Running) {
            return null;
        }

        $this->executor->execute(
            "UPDATE jobs SET status = 'completed', updated_at = ? WHERE id = ?",
            [$now, $id],
        );

        return $this->findById($id);
    }

    /**
     * Fail a running job. If retry_count < max_retries, the job is requeued to
     * pending with an incremented retry_count. Otherwise it transitions to failed.
     */
    public function fail(int $id, string $error, string $now): ?Job
    {
        $job = $this->findById($id);
        if ($job === null || $job->status !== JobStatus::Running) {
            return null;
        }

        if ($job->retryCount < $job->maxRetries) {
            $this->executor->execute(
                "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1, error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
                [$error, $now, $id],
            );
        } else {
            $this->executor->execute(
                "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
                [$error, $now, $id],
            );
        }

        return $this->findById($id);
    }

    /**
     * @return list<Job>
     */
    public function list(?JobStatus $status): array
    {
        if ($status !== null) {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM jobs WHERE status = ? ORDER BY priority DESC, created_at ASC',
                [$status->value],
            );
        } else {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM jobs ORDER BY priority DESC, created_at ASC',
                [],
            );
        }

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Job
    {
        return new Job(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['payload'],
            JobPriority::from((int) $row['priority']),
            JobStatus::from((string) $row['status']),
            (int) $row['retry_count'],
            (int) $row['max_retries'],
            isset($row['idempotency_key']) ? (string) $row['idempotency_key'] : null,
            isset($row['claimed_at']) ? (string) $row['claimed_at'] : null,
            isset($row['worker_id']) ? (string) $row['worker_id'] : null,
            isset($row['error']) ? (string) $row['error'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
