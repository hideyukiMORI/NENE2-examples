<?php

declare(strict_types=1);

namespace ExpenseLog\Expense;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ExpenseRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, date, amount, category, note, created_at, updated_at FROM expenses WHERE id = ?',
            [$id],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $from, ?string $to, ?string $category, int $limit, int $offset): array
    {
        [$where, $params] = $this->filter($from, $to, $category);
        $sql = 'SELECT id, date, amount, category, note, created_at, updated_at FROM expenses'
            . $where . ' ORDER BY date DESC, id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll($sql, $params);
    }

    public function count(?string $from, ?string $to, ?string $category): int
    {
        [$where, $params] = $this->filter($from, $to, $category);
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM expenses' . $where, $params);
        return $row === null ? 0 : (int) $row['c'];
    }

    /** @return list<array<string, mixed>> per month + category aggregation */
    public function summary(?string $from, ?string $to): array
    {
        [$where, $params] = $this->filter($from, $to, null);
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            "SELECT strftime('%Y-%m', date) AS month, category, SUM(amount) AS total, COUNT(*) AS count
             FROM expenses" . $where . '
             GROUP BY month, category ORDER BY month DESC, category ASC',
            $params,
        );
    }

    public function create(string $date, int $amount, string $category, string $note, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO expenses (date, amount, category, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$date, $amount, $category, $note, $now, $now],
        );
    }

    /**
     * PATCH-merge against the existing row; null means "keep".
     *
     * @param array<string, mixed> $existing
     */
    public function update(int $id, array $existing, ?string $date, ?int $amount, ?string $category, ?string $note, string $now): void
    {
        $this->db->execute(
            'UPDATE expenses SET date = ?, amount = ?, category = ?, note = ?, updated_at = ? WHERE id = ?',
            [
                $date ?? (string) $existing['date'],
                $amount ?? (int) $existing['amount'],
                $category ?? (string) $existing['category'],
                $note ?? (string) $existing['note'],
                $now,
                $id,
            ],
        );
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM expenses WHERE id = ?', [$id]) > 0;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function filter(?string $from, ?string $to, ?string $category): array
    {
        $conds = [];
        $params = [];
        if ($from !== null) {
            $conds[] = 'date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $conds[] = 'date <= ?';
            $params[] = $to;
        }
        if ($category !== null) {
            $conds[] = 'category = ?';
            $params[] = $category;
        }
        $where = $conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds);
        return [$where, $params];
    }
}
