<?php

declare(strict_types=1);

namespace CqrsLog\Order\Query;

use CqrsLog\Order\OrderSummary;
use Nene2\Database\DatabaseQueryExecutorInterface;

/**
 * The read side: reads from the `order_summary` view only. It never touches the
 * normalised tables, so write-model structure changes are absorbed by the view.
 */
final readonly class OrderQueryHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor)
    {
    }

    public function get(GetOrderSummaryQuery $query): ?OrderSummary
    {
        $row = $this->executor->fetchOne('SELECT * FROM order_summary WHERE id = ?', [$query->orderId]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<OrderSummary> */
    public function list(ListOrderSummariesQuery $query): array
    {
        if ($query->status !== null) {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary WHERE status = ? ORDER BY created_at DESC, id DESC',
                [$query->status],
            );
        } else {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary ORDER BY created_at DESC, id DESC',
                [],
            );
        }
        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): OrderSummary
    {
        return new OrderSummary(
            id: (int) $row['id'],
            customer: (string) $row['customer'],
            status: (string) $row['status'],
            createdAt: (string) $row['created_at'],
            itemCount: (int) $row['item_count'],
            totalCents: (int) ($row['total_cents'] ?? 0),
        );
    }
}
