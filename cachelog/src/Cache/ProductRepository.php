<?php

declare(strict_types=1);

namespace CacheLog\Cache;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ProductRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT * FROM products ORDER BY id ASC', []);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
    }

    public function create(string $name, float $price, int $stock, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO products (name, price, stock, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$name, $price, $stock, $now, $now],
        );
    }

    public function update(int $id, string $name, float $price, int $stock, string $now): bool
    {
        $this->db->insert(
            'UPDATE products SET name = ?, price = ?, stock = ?, updated_at = ? WHERE id = ?',
            [$name, $price, $stock, $now, $id],
        );
        return $this->find($id) !== null;
    }

    public function delete(int $id): bool
    {
        $row = $this->find($id);
        if ($row === null) {
            return false;
        }
        $this->db->insert('DELETE FROM products WHERE id = ?', [$id]);
        return true;
    }
}
