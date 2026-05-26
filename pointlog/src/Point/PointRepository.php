<?php

declare(strict_types=1);

namespace PointLog\Point;

use Nene2\Database\DatabaseQueryExecutorInterface;

class PointRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    /** @return array<string, mixed>|null */
    public function findUserById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function getBalance(int $userId): int
    {
        $row = $this->executor->fetchOne(
            'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );
        return $row !== null ? (int) $row['balance_after'] : 0;
    }

    /** @return list<array<string, mixed>> */
    public function listTransactions(int $userId, int $limit = 20): array
    {
        return $this->executor->fetchAll(
            'SELECT * FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT ?',
            [$userId, $limit]
        );
    }

    public function addTransaction(int $userId, string $type, int $amount, int $balanceAfter, string $description, ?string $referenceId, string $now): int
    {
        return $this->executor->insert(
            'INSERT INTO point_transactions (user_id, type, amount, balance_after, description, reference_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $type, $amount, $balanceAfter, $description, $referenceId, $now]
        );
    }

    /** @return array<string, mixed>|null */
    public function findTransactionById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM point_transactions WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByReferenceId(int $userId, string $referenceId): ?array
    {
        return $this->executor->fetchOne(
            'SELECT * FROM point_transactions WHERE user_id = ? AND reference_id = ?',
            [$userId, $referenceId]
        );
    }
}
