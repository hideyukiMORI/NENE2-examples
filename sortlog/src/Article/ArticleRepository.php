<?php

declare(strict_types=1);

namespace SortLog\Article;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ArticleRepository
{
    /** The ONLY column names that may be interpolated into ORDER BY. */
    public const array SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];

    /** The ONLY directions that may be interpolated into ORDER BY. */
    public const array SORT_DIRECTIONS = ['asc', 'desc'];

    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function create(string $title, string $status, string $now): int
    {
        return $this->db->insert('INSERT INTO articles (title, status, created_at) VALUES (?, ?, ?)', [$title, $status, $now]);
    }

    /**
     * @param string $sortCol  MUST be one of SORT_COLUMNS (caller-validated)
     * @param string $sortDir  MUST be one of SORT_DIRECTIONS (caller-validated)
     * @return list<array<string, mixed>>
     */
    public function list(string $sortCol, string $sortDir, ?string $status, int $limit, int $offset): array
    {
        // Defense in depth: refuse to build SQL unless the values are allow-listed,
        // even though the route layer already validated them. ORDER BY cannot be a
        // bound parameter, so this is the only safe way to interpolate.
        if (!in_array($sortCol, self::SORT_COLUMNS, true) || !in_array($sortDir, self::SORT_DIRECTIONS, true)) {
            throw new \InvalidArgumentException('non-allowlisted sort column/direction');
        }

        $where = '';
        $params = [];
        if ($status !== null) {
            $where = ' WHERE status = ?';
            $params[] = $status;
        }
        $params[] = $limit;
        $params[] = $offset;

        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            "SELECT id, title, status, created_at FROM articles{$where} ORDER BY {$sortCol} {$sortDir} LIMIT ? OFFSET ?",
            $params,
        );
    }
}
