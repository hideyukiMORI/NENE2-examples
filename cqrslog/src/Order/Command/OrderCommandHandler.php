<?php

declare(strict_types=1);

namespace CqrsLog\Order\Command;

use Nene2\Database\DatabaseQueryExecutorInterface;

/**
 * The write side: owns every mutation against the normalised write model.
 * Returns primitives only (never read-model objects) — the controller re-queries
 * the read side for the response shape.
 */
final readonly class OrderCommandHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor)
    {
    }

    public function place(PlaceOrderCommand $command, string $now): int
    {
        $orderId = $this->executor->insert(
            'INSERT INTO orders (customer, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$command->customer, 'pending', $now, $now],
        );
        foreach ($command->items as $item) {
            $this->executor->insert(
                'INSERT INTO order_items (order_id, product, quantity, unit_price) VALUES (?, ?, ?, ?)',
                [$orderId, $item['product'], $item['quantity'], $item['unit_price']],
            );
        }
        return $orderId;
    }

    public function updateStatus(UpdateOrderStatusCommand $command, string $now): bool
    {
        $affected = $this->executor->execute(
            'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
            [$command->newStatus, $now, $command->orderId],
        );
        return $affected > 0;
    }
}
