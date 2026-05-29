<?php

declare(strict_types=1);

namespace InventoryLog\Item;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

class ItemRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, sku, name, quantity, price_cents, created_at, updated_at FROM items WHERE id = ?',
            [$id],
        );
    }

    /** Create an item. Returns the new id, or null on duplicate SKU. */
    public function create(string $sku, string $name, int $quantity, int $priceCents, string $now): ?int
    {
        try {
            return $this->db->insert(
                'INSERT INTO items (sku, name, quantity, price_cents, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$sku, $name, $quantity, $priceCents, $now, $now],
            );
        } catch (DatabaseConstraintException) {
            return null;
        }
    }

    /**
     * Apply a signed stock delta atomically. The `quantity + ? >= 0` guard in
     * the WHERE clause makes over-drain impossible even under concurrency:
     * an insufficient adjustment updates 0 rows and is rejected.
     *
     * @return 'not_found'|'insufficient_stock'|'ok'
     */
    public function adjust(int $id, int $delta, string $reason, string $now): string
    {
        if ($this->findById($id) === null) {
            return 'not_found';
        }

        $affected = $this->db->execute(
            'UPDATE items SET quantity = quantity + ?, updated_at = ? WHERE id = ? AND quantity + ? >= 0',
            [$delta, $now, $id, $delta],
        );
        if ($affected === 0) {
            return 'insufficient_stock';
        }

        $item = (array) $this->findById($id);
        $quantityAfter = (int) ($item['quantity'] ?? 0);
        $this->db->execute(
            'INSERT INTO stock_logs (item_id, delta, reason, quantity_after, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $delta, $reason, $quantityAfter, $now],
        );

        return 'ok';
    }

    /** @return list<array<string, mixed>> */
    public function history(int $id): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, delta, reason, quantity_after, created_at FROM stock_logs WHERE item_id = ? ORDER BY id ASC',
            [$id],
        );
    }
}
