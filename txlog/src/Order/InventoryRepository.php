<?php

declare(strict_types=1);

namespace Tx\Order;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class InventoryRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function seed(int $productId, string $name, int $stock): void
    {
        $this->executor->execute(
            'INSERT OR REPLACE INTO inventory (product_id, product_name, stock) VALUES (?, ?, ?)',
            [$productId, $name, $stock],
        );
    }

    public function getStock(int $productId): int
    {
        $row = $this->executor->fetchOne(
            'SELECT stock FROM inventory WHERE product_id = ?',
            [$productId],
        );
        return $row !== null ? (int) $row['stock'] : 0;
    }

    /**
     * Decrement stock by $qty. Throws InsufficientStockException if stock would go negative.
     * The CHECK constraint (stock >= 0) in the schema is a second safety net.
     */
    public function decrement(int $productId, int $qty): void
    {
        $current = $this->getStock($productId);
        if ($current < $qty) {
            throw new InsufficientStockException($productId, $qty, $current);
        }
        $this->executor->execute(
            'UPDATE inventory SET stock = stock - ? WHERE product_id = ?',
            [$qty, $productId],
        );
    }
}
