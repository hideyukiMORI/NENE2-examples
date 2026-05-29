<?php

declare(strict_types=1);

namespace ConsentLog\Consent;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ConsentRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /**
     * Idempotent UPSERT of the current state + an append-only history row.
     * UNIQUE(user_id, purpose) makes concurrent grants atomic.
     */
    public function record(int $userId, string $purpose, bool $granted, string $now): void
    {
        $this->db->execute(
            'INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(user_id, purpose) DO UPDATE SET granted = excluded.granted, updated_at = excluded.updated_at',
            [$userId, $purpose, $granted ? 1 : 0, $now, $now],
        );
        $this->db->execute(
            'INSERT INTO consent_history (user_id, purpose, granted, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $purpose, $granted ? 1 : 0, $now],
        );
    }

    /** @return list<array<string, mixed>> current consents for a user (IDOR-scoped) */
    public function listCurrent(int $userId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT purpose, granted, updated_at FROM consents WHERE user_id = ? ORDER BY purpose ASC',
            [$userId],
        );
    }

    /** @return list<array<string, mixed>> append-only history for a (user, purpose) */
    public function history(int $userId, string $purpose): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT granted, created_at FROM consent_history WHERE user_id = ? AND purpose = ? ORDER BY id ASC',
            [$userId, $purpose],
        );
    }
}
