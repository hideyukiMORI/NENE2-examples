<?php

declare(strict_types=1);

namespace Tx\Order;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class OrderRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    /** @param list<array{product_id: int, quantity: int}> $items */
    public function create(array $items): int
    {
        $this->executor->insert(
            'INSERT INTO orders (status, created_at) VALUES (?, ?)',
            ['placed', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
        );
        $orderId = $this->executor->lastInsertId();

        foreach ($items as $item) {
            $this->executor->insert(
                'INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)',
                [$orderId, $item['product_id'], $item['quantity']],
            );
        }

        return $orderId;
    }

    public function count(): int
    {
        $row = $this->executor->fetchOne('SELECT COUNT(*) AS cnt FROM orders', []);
        return (int) ($row['cnt'] ?? 0);
    }
}
