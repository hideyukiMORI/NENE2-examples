<?php

declare(strict_types=1);

namespace SearchLog\Search;

use Nene2\Database\DatabaseQueryExecutorInterface;

class SearchRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(string $query, ?string $category, int $limit, int $offset): array
    {
        $lq = strtolower($query);
        $escaped = $this->escapeLike($lq);
        $pattern = '%' . $escaped . '%';
        $prefix = $escaped . '%';

        $whereConditions = [
            'LOWER(name) LIKE ? ESCAPE \'!\'',
            'LOWER(description) LIKE ? ESCAPE \'!\'',
            'LOWER(category) LIKE ? ESCAPE \'!\'',
        ];
        $whereParams = [$pattern, $pattern, $pattern];
        $whereClause = 'WHERE (' . implode(' OR ', $whereConditions) . ')';

        if ($category !== null) {
            $whereClause .= ' AND LOWER(category) = ?';
            $whereParams[] = strtolower($category);
        }

        /** @var array<string, mixed> $row */
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM products ' . $whereClause,
            $whereParams
        ) ?? ['cnt' => 0];
        $total = (int) $row['cnt'];

        // Relevance: 0 = exact name, 1 = name starts with query, 2 = contains anywhere
        $selectParams = [$lq, $prefix, ...$whereParams, $limit, $offset];
        /** @var list<array<string, mixed>> $items */
        $items = $this->db->fetchAll(
            'SELECT id, name, description, category, price_cents, created_at,
                    CASE WHEN LOWER(name) = ? THEN 0
                         WHEN LOWER(name) LIKE ? ESCAPE \'!\' THEN 1
                         ELSE 2
                    END AS relevance
             FROM products ' . $whereClause . '
             ORDER BY relevance ASC, id ASC
             LIMIT ? OFFSET ?',
            $selectParams
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<string>
     */
    public function autocomplete(string $prefix, int $limit): array
    {
        $escaped = $this->escapeLike(strtolower($prefix));
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT name FROM products WHERE LOWER(name) LIKE ? ESCAPE '!' ORDER BY name ASC LIMIT ?",
            [$escaped . '%', $limit]
        );
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    private function escapeLike(string $value): string
    {
        // Use ! as escape char to avoid backslash confusion in SQL string literals
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
