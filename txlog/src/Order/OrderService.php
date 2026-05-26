<?php

declare(strict_types=1);

namespace Tx\Order;

use Nene2\Database\DatabaseTransactionManagerInterface;

/**
 * Demonstrates the correct pattern for NENE2's transactional() API.
 *
 * Key rule: repositories MUST be instantiated inside the callback using the
 * executor supplied by transactional(). Repositories injected at construction
 * time use a different DB connection and their writes are NOT rolled back.
 */
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {
    }

    /**
     * @param list<array{product_id: int, quantity: int}> $items
     * @throws InsufficientStockException if any item lacks sufficient stock
     */
    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // Repositories are instantiated INSIDE the callback with the
            // transaction-scoped executor — this is the correct pattern.
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // Throws InsufficientStockException → triggers rollback
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
