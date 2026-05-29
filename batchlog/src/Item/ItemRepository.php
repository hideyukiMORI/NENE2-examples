<?php

declare(strict_types=1);

namespace BatchLog\Item;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ItemRepository
{
    /** DoS guard: a single batch may not exceed this many items. */
    public const int MAX_BATCH = 50;

    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, user_id, name, quantity, price_cents, created_at FROM items WHERE id = ?',
            [$id],
        );
    }

    public function create(int $userId, string $name, int $quantity, int $priceCents, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO items (user_id, name, quantity, price_cents, created_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $name, $quantity, $priceCents, $now],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listOwned(int $userId, int $limit): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, user_id, name, quantity, price_cents, created_at FROM items WHERE user_id = ? ORDER BY id ASC LIMIT ?',
            [$userId, $limit],
        );
    }
}
