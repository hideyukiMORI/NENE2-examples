<?php

declare(strict_types=1);

namespace Limitlog\Article;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class ArticleRepository
{
    /** Hard cap: callers may never request more than this many rows at once. */
    public const int MAX_LIMIT  = 100;
    public const int MIN_LIMIT  = 1;
    public const int DEFAULT_LIMIT = 20;

    public function __construct(
        private DatabaseQueryExecutorInterface $db,
    ) {
    }

    public function create(int $authorId, string $title, string $body, string $now): Article
    {
        $id = $this->db->insert(
            'INSERT INTO articles (author_id, title, body, created_at) VALUES (?, ?, ?, ?)',
            [$authorId, $title, $body, $now],
        );

        return new Article($id, $authorId, $title, $body, $now);
    }

    // ── Offset-based pagination ───────────────────────────────────────────

    /**
     * Returns a page of articles using OFFSET pagination.
     *
     * @return array{data: list<Article>, total: int, page: int, limit: int, has_more: bool}
     */
    public function listByOffset(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        $rows = $this->db->fetchAll(
            'SELECT * FROM articles ORDER BY id DESC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );

        $totalRow = $this->db->fetchOne('SELECT COUNT(*) AS cnt FROM articles', []);
        $total    = $totalRow !== null ? (int) $totalRow['cnt'] : 0;

        $data = array_map($this->hydrate(...), $rows);

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit,
            'has_more' => ($offset + count($data)) < $total,
        ];
    }

    // ── Cursor-based pagination ───────────────────────────────────────────

    /**
     * Returns articles after the given cursor id (exclusive), newest-first.
     * Uses fetch+1 to determine has_more without a COUNT query.
     *
     * @return array{data: list<Article>, next_cursor: int|null, has_more: bool, limit: int}
     */
    public function listByCursor(int $afterId, int $limit): array
    {
        // Fetch one extra to detect has_more
        $rows = $this->db->fetchAll(
            'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterId, $limit + 1],
        );

        $hasMore = count($rows) > $limit;

        if ($hasMore) {
            array_pop($rows); // remove the extra sentinel row
        }

        $data       = array_map($this->hydrate(...), $rows);
        $nextCursor = $hasMore && count($data) > 0 ? end($data)->id : null;

        return [
            'data'        => $data,
            'next_cursor' => $nextCursor,
            'has_more'    => $hasMore,
            'limit'       => $limit,
        ];
    }

    // ── Author filter ─────────────────────────────────────────────────────

    /**
     * Returns articles by author, using cursor pagination.
     *
     * @return array{data: list<Article>, next_cursor: int|null, has_more: bool}
     */
    public function listByAuthor(int $authorId, int $afterId, int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM articles WHERE author_id = ? AND id < ? ORDER BY id DESC LIMIT ?',
            [$authorId, $afterId, $limit + 1],
        );

        $hasMore = count($rows) > $limit;

        if ($hasMore) {
            array_pop($rows);
        }

        $data       = array_map($this->hydrate(...), $rows);
        $nextCursor = $hasMore && count($data) > 0 ? end($data)->id : null;

        return [
            'data'        => $data,
            'next_cursor' => $nextCursor,
            'has_more'    => $hasMore,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Article
    {
        return new Article(
            (int) $row['id'],
            (int) $row['author_id'],
            (string) $row['title'],
            (string) $row['body'],
            (string) $row['created_at'],
        );
    }
}
