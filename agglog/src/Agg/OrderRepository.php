<?php

declare(strict_types=1);

namespace AggLog\Agg;

use Nene2\Database\DatabaseQueryExecutorInterface;

class OrderRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function create(string $customerId, string $itemName, int $amount, string $status, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO orders (customer_id, item_name, amount, status, created_at) VALUES (?, ?, ?, ?, ?)',
            [$customerId, $itemName, $amount, $status, $now],
        );
    }

    /** @return array<string, mixed> */
    public function summary(?string $from, ?string $to): array
    {
        [$where, $params] = $this->dateFilter($from, $to);
        /** @var array<string, mixed>|null */
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS total_orders,
                    COALESCE(SUM(amount), 0) AS total_revenue,
                    COALESCE(AVG(amount), 0) AS avg_order_value,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
             FROM orders {$where}",
            $params,
        );
        return $row ?? ['total_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0, 'completed_orders' => 0];
    }

    /** @return list<array<string, mixed>> */
    public function dailyBreakdown(?string $from, ?string $to): array
    {
        [$where, $params] = $this->dateFilter($from, $to);
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            "SELECT substr(created_at, 1, 10) AS date,
                    COUNT(*) AS order_count,
                    SUM(amount) AS revenue
             FROM orders {$where}
             GROUP BY date
             ORDER BY date ASC",
            $params,
        );
    }

    /** @return list<array<string, mixed>> */
    public function byStatus(?string $from, ?string $to): array
    {
        [$where, $params] = $this->dateFilter($from, $to);
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            "SELECT status, COUNT(*) AS order_count, SUM(amount) AS revenue
             FROM orders {$where}
             GROUP BY status
             ORDER BY order_count DESC",
            $params,
        );
    }

    /** @return list<array<string, mixed>> */
    public function topItems(?string $from, ?string $to, int $limit): array
    {
        [$where, $params] = $this->dateFilter($from, $to);
        $params[] = $limit;
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            "SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
             FROM orders {$where}
             GROUP BY item_name
             ORDER BY revenue DESC
             LIMIT ?",
            $params,
        );
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function dateFilter(?string $from, ?string $to): array
    {
        $conditions = [];
        $params     = [];
        if ($from !== null) {
            $conditions[] = 'created_at >= ?';
            $params[]     = $from;
        }
        if ($to !== null) {
            $conditions[] = 'created_at <= ?';
            $params[]     = $to;
        }
        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $params];
    }
}
