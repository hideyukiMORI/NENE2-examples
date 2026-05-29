<?php

declare(strict_types=1);

namespace PriceLog\Price;

use Nene2\Database\DatabaseQueryExecutorInterface;

class PriceRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findProduct(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name, created_at FROM products WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listProducts(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT id, name, created_at FROM products ORDER BY id ASC');
    }

    public function createProduct(string $name, string $now): int
    {
        return $this->db->insert('INSERT INTO products (name, created_at) VALUES (?, ?)', [$name, $now]);
    }

    /** @return list<array<string, mixed>> full timeline, newest first */
    public function history(int $productId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, amount, currency, effective_from, effective_to, created_at
             FROM price_tiers WHERE product_id = ? ORDER BY effective_from DESC, id DESC',
            [$productId],
        );
    }

    /** @return array<string, mixed>|null the tier active at $at */
    public function priceAt(int $productId, string $at): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, amount, currency, effective_from, effective_to, created_at
             FROM price_tiers
             WHERE product_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to > ?)
             ORDER BY effective_from DESC, id DESC LIMIT 1',
            [$productId, $at, $at],
        );
    }
}
