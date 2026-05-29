<?php

declare(strict_types=1);

namespace ProductLog\Product;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ProductRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function create(string $sku, string $name, string $description, int $priceCents, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO products (sku, name, description, price_cents, active, created_at)
             VALUES (?, ?, ?, ?, 1, ?)',
            [$sku, $name, $description, $priceCents, $now],
        );
    }

    /** @return array<string, mixed>|null active product only */
    public function findActive(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM products WHERE id = ? AND active = 1', [$id]);
    }

    /** Soft delete; returns affected rows (0 if already deleted / absent). */
    public function softDelete(int $id): int
    {
        return $this->db->execute('UPDATE products SET active = 0 WHERE id = ? AND active = 1', [$id]);
    }

    /**
     * Parameterized LIKE search over active products. `%`/`_` in the keyword are
     * literal LIKE wildcards (never interpolated into SQL).
     *
     * @return list<array<string, mixed>>
     */
    public function search(?string $keyword, int $limit, int $offset): array
    {
        if ($keyword === null || $keyword === '') {
            /** @var list<array<string, mixed>> */
            return $this->db->fetchAll(
                'SELECT * FROM products WHERE active = 1 ORDER BY id DESC LIMIT ? OFFSET ?',
                [$limit, $offset],
            );
        }
        $like = '%' . $keyword . '%';
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT * FROM products
             WHERE active = 1 AND (name LIKE ? OR description LIKE ? OR sku LIKE ?)
             ORDER BY id DESC LIMIT ? OFFSET ?',
            [$like, $like, $like, $limit, $offset],
        );
    }
}
