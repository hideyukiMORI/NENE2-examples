<?php

declare(strict_types=1);

namespace RateLog\Rate;

use Nene2\Database\DatabaseQueryExecutorInterface;

class RateRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /**
     * Count events for (user, endpoint) inside the rolling window — every event
     * with created_at >= $since (= now - WINDOW). Old events fall out naturally.
     */
    public function countInWindow(int $userId, string $endpoint, string $since): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM rate_events
             WHERE user_id = ? AND endpoint = ? AND created_at >= ?',
            [$userId, $endpoint, $since],
        );
        return $row !== null ? (int) $row['c'] : 0;
    }

    public function record(int $userId, string $endpoint, string $now): void
    {
        $this->db->execute(
            'INSERT INTO rate_events (user_id, endpoint, created_at) VALUES (?, ?, ?)',
            [$userId, $endpoint, $now],
        );
    }

    public function resetUser(int $userId): int
    {
        return $this->db->execute('DELETE FROM rate_events WHERE user_id = ?', [$userId]);
    }

    public function resetUserEndpoint(int $userId, string $endpoint): int
    {
        return $this->db->execute(
            'DELETE FROM rate_events WHERE user_id = ? AND endpoint = ?',
            [$userId, $endpoint],
        );
    }
}
