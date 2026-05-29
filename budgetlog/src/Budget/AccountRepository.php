<?php

declare(strict_types=1);

namespace BudgetLog\Budget;

use Nene2\Database\DatabaseQueryExecutorInterface;

class AccountRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null owner-scoped: another user's account returns null */
    public function findOwned(int $id, int $ownerId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, owner_id, name, balance, created_at FROM accounts WHERE id = ? AND owner_id = ?',
            [$id, $ownerId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listOwned(int $ownerId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, owner_id, name, balance, created_at FROM accounts WHERE owner_id = ? ORDER BY id ASC',
            [$ownerId],
        );
    }

    public function create(int $ownerId, string $name, int $initialBalance, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO accounts (owner_id, name, balance, created_at) VALUES (?, ?, ?, ?)',
            [$ownerId, $name, $initialBalance, $now],
        );
    }

    public function credit(int $id, int $ownerId, int $amount): void
    {
        $this->db->execute(
            'UPDATE accounts SET balance = balance + ? WHERE id = ? AND owner_id = ?',
            [$amount, $id, $ownerId],
        );
    }

    /** Atomic debit guarded by sufficiency; returns false when funds are short. */
    public function debit(int $id, int $ownerId, int $amount): bool
    {
        return $this->db->execute(
            'UPDATE accounts SET balance = balance - ? WHERE id = ? AND owner_id = ? AND balance >= ?',
            [$amount, $id, $ownerId, $amount],
        ) > 0;
    }
}
