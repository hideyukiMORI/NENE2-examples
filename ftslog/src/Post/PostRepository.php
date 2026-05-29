<?php

declare(strict_types=1);

namespace FtsLog\Post;

use Nene2\Database\DatabaseQueryExecutorInterface;

class PostRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function create(string $title, string $body, string $tags, string $now): int
    {
        // The posts_ai trigger mirrors the row into posts_fts automatically.
        return $this->db->insert(
            'INSERT INTO posts (title, body, tags, created_at) VALUES (?, ?, ?, ?)',
            [$title, $body, $tags, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM posts WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listAll(int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT * FROM posts ORDER BY id DESC LIMIT ? OFFSET ?', [$limit, $offset]);
    }

    /**
     * Full-text search via FTS5 MATCH, relevance-ranked (lower rank = better).
     * The query string is a parameter — it cannot alter the SQL structure, but
     * malformed FTS syntax (e.g. an unclosed quote) raises a DB exception the
     * caller maps to 400.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT p.*, fts.rank AS rank
             FROM posts_fts fts
             JOIN posts p ON p.id = fts.rowid
             WHERE posts_fts MATCH ?
             ORDER BY fts.rank
             LIMIT ? OFFSET ?',
            [$query, $limit, $offset],
        );
    }
}
