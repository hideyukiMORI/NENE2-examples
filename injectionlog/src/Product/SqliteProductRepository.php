<?php

declare(strict_types=1);

namespace Injection\Product;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class SqliteProductRepository
{
    /** @var list<string> */
    private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'category', 'price'];

    public function __construct(
        private readonly DatabaseQueryExecutorInterface $db,
    ) {
    }

    public function create(string $name, string $category, float $price, string $description): Product
    {
        $id = $this->db->insert(
            'INSERT INTO products (name, category, price, description) VALUES (?, ?, ?, ?)',
            [$name, $category, $price, $description],
        );

        return new Product($id, $name, $category, $price, $description);
    }

    public function findById(int $id): ?Product
    {
        $row = $this->db->fetchOne(
            'SELECT id, name, category, price, description FROM products WHERE id = ?',
            [$id],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * Search products with LIKE on name/description and optional ORDER BY.
     *
     * ORDER BY column is whitelisted — never interpolated from user input directly.
     *
     * @return list<Product>
     */
    public function search(string $query = '', string $sortField = 'id', string $sortDir = 'asc'): array
    {
        if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
            throw new InvalidSortFieldException("Invalid sort field: {$sortField}");
        }

        $sortDir   = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
        $sortClause = $sortField . ' ' . $sortDir;

        if ($query === '') {
            $rows = $this->db->fetchAll(
                "SELECT id, name, category, price, description FROM products ORDER BY {$sortClause}",
            );
        } else {
            // LIKE with parameterized wildcard — safe against injection
            $rows = $this->db->fetchAll(
                "SELECT id, name, category, price, description FROM products
                 WHERE name LIKE '%' || ? || '%' OR description LIKE '%' || ? || '%'
                 ORDER BY {$sortClause}",
                [$query, $query],
            );
        }

        return array_map($this->hydrate(...), $rows);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute(
            'DELETE FROM products WHERE id = ?',
            [$id],
        ) > 0;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Product
    {
        return new Product(
            id:          (int) $row['id'],
            name:        (string) $row['name'],
            category:    (string) $row['category'],
            price:       (float) $row['price'],
            description: (string) $row['description'],
        );
    }
}
