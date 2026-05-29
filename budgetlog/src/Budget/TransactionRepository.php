<?php

declare(strict_types=1);

namespace BudgetLog\Budget;

use Nene2\Database\DatabaseQueryExecutorInterface;

class TransactionRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function create(int $accountId, int $amount, string $type, string $category, string $description, bool $recurring, string $now): void
    {
        $this->db->execute(
            'INSERT INTO transactions (account_id, amount, type, category, description, recurring, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$accountId, $amount, $type, $category, $description, $recurring ? 1 : 0, $now],
        );
    }

    /**
     * @param array{category?: ?string, min?: ?int, max?: ?int, recurring?: ?bool} $filters
     * @return list<array<string, mixed>>
     */
    public function listForAccount(int $accountId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($accountId, $filters);
        $params[] = $limit;
        $params[] = $offset;
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, amount, type, category, description, recurring, created_at FROM transactions'
            . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            $params,
        );
    }

    /**
     * @param array{category?: ?string, min?: ?int, max?: ?int, recurring?: ?bool} $filters
     */
    public function countForAccount(int $accountId, array $filters): int
    {
        [$where, $params] = $this->buildWhere($accountId, $filters);
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM transactions' . $where, $params);
        return $row === null ? 0 : (int) $row['c'];
    }

    /** @return list<array<string, mixed>> category totals for a given type */
    public function categoryTotals(int $accountId, string $type): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT category, SUM(amount) AS total FROM transactions
             WHERE account_id = ? AND type = ? GROUP BY category ORDER BY total DESC',
            [$accountId, $type],
        );
    }

    /**
     * @param array{category?: ?string, min?: ?int, max?: ?int, recurring?: ?bool} $filters
     * @return array{0: string, 1: list<int|string>}
     */
    private function buildWhere(int $accountId, array $filters): array
    {
        $conds = ['account_id = ?'];
        $params = [$accountId];
        if (($filters['category'] ?? null) !== null) {
            $conds[] = 'category = ?';
            $params[] = $filters['category'];
        }
        if (($filters['min'] ?? null) !== null) {
            $conds[] = 'amount >= ?';
            $params[] = $filters['min'];
        }
        if (($filters['max'] ?? null) !== null) {
            $conds[] = 'amount <= ?';
            $params[] = $filters['max'];
        }
        if (($filters['recurring'] ?? null) !== null) {
            $conds[] = 'recurring = ?';
            $params[] = $filters['recurring'] ? 1 : 0;
        }
        return [' WHERE ' . implode(' AND ', $conds), $params];
    }
}
