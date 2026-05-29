<?php

declare(strict_types=1);

namespace DeadLetterLog\Queue;

use Nene2\Database\DatabaseQueryExecutorInterface;

class QueueRepository
{
    private const int MAX_BACKOFF_SECONDS = 3600;

    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function enqueue(string $queue, string $payload, int $maxRetries, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO messages (queue, payload, status, retry_count, max_retries, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, ?, ?)',
            [$queue, $payload, MessageStatus::Pending->value, $maxRetries, $now, $now],
        );
    }

    public function find(int $id, string $queue): ?Message
    {
        $row = $this->db->fetchOne('SELECT * FROM messages WHERE id = ? AND queue = ?', [$id, $queue]);
        return $row !== null ? Message::fromRow($row) : null;
    }

    /**
     * @return list<Message>
     */
    public function list(string $queue, ?string $status, int $limit, int $offset): array
    {
        if ($status !== null) {
            $rows = $this->db->fetchAll(
                'SELECT * FROM messages WHERE queue = ? AND status = ? ORDER BY created_at ASC, id ASC LIMIT ? OFFSET ?',
                [$queue, $status, $limit, $offset],
            );
        } else {
            $rows = $this->db->fetchAll(
                'SELECT * FROM messages WHERE queue = ? ORDER BY created_at ASC, id ASC LIMIT ? OFFSET ?',
                [$queue, $limit, $offset],
            );
        }
        return array_map(Message::fromRow(...), $rows);
    }

    /**
     * Atomic claim of the next available pending message. Must run inside a
     * transaction (the caller wraps it) so the SELECT + UPDATE can't race another
     * worker. `retry_after <= now` skips messages waiting between retries.
     */
    public function claimInTransaction(string $queue, string $now): ?Message
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM messages
             WHERE queue = ? AND status = 'pending'
               AND (retry_after IS NULL OR retry_after <= ?)
             ORDER BY created_at ASC, id ASC LIMIT 1",
            [$queue, $now],
        );
        if ($row === null) {
            return null;
        }
        $id = (int) $row['id'];
        $this->db->execute(
            "UPDATE messages SET status = 'processing', updated_at = ? WHERE id = ?",
            [$now, $id],
        );
        return $this->find($id, $queue);
    }

    public function succeed(int $id, string $queue, string $now): ?Message
    {
        $msg = $this->find($id, $queue);
        if ($msg === null || $msg->status !== MessageStatus::Processing) {
            return null;
        }
        $this->db->execute(
            "UPDATE messages SET status = 'succeeded', updated_at = ? WHERE id = ?",
            [$now, $id],
        );
        return $this->find($id, $queue);
    }

    /**
     * Report failure: retry with exponential backoff, or promote to the DLQ once
     * retries are exhausted.
     */
    public function fail(int $id, string $queue, string $error, string $now): ?Message
    {
        $msg = $this->find($id, $queue);
        if ($msg === null || $msg->status !== MessageStatus::Processing) {
            return null;
        }
        $newRetryCount = $msg->retryCount + 1;
        if ($newRetryCount >= $msg->maxRetries) {
            $this->db->execute(
                "UPDATE messages SET status = 'dead', retry_count = ?, last_error = ?, updated_at = ? WHERE id = ?",
                [$newRetryCount, $error, $now, $id],
            );
        } else {
            $backoffSeconds = min(2 ** $newRetryCount, self::MAX_BACKOFF_SECONDS);
            $retryAfter = (new \DateTimeImmutable($now))
                ->modify("+{$backoffSeconds} seconds")
                ->format('Y-m-d H:i:s');
            $this->db->execute(
                "UPDATE messages SET status = 'pending', retry_count = ?, last_error = ?,
                 retry_after = ?, updated_at = ? WHERE id = ?",
                [$newRetryCount, $error, $retryAfter, $now, $id],
            );
        }
        return $this->find($id, $queue);
    }

    /** Reset a dead message back to pending with a fresh retry budget. */
    public function replay(int $id, string $queue, string $now): ?Message
    {
        $msg = $this->find($id, $queue);
        if ($msg === null || $msg->status !== MessageStatus::Dead) {
            return null;
        }
        $this->db->execute(
            "UPDATE messages SET status = 'pending', retry_count = 0,
             last_error = NULL, retry_after = NULL, updated_at = ? WHERE id = ?",
            [$now, $id],
        );
        return $this->find($id, $queue);
    }
}
