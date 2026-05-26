<?php

declare(strict_types=1);

namespace Page;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class SqliteArticleRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function create(string $title, string $author, string $category = 'general'): Article
    {
        $this->executor->insert(
            'INSERT INTO articles (title, author, category, body, created_at) VALUES (?, ?, ?, ?, ?)',
            [$title, $author, $category, '', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
        );
        $row = $this->executor->fetchOne(
            'SELECT * FROM articles WHERE id = ?',
            [$this->executor->lastInsertId()],
        );
        assert($row !== null);
        return Article::fromRow($row);
    }

    /**
     * OFFSET-based pagination — SELECT with LIMIT ? OFFSET ?
     * Simple to implement but performance degrades as offset grows: the DB must
     * scan and discard all preceding rows.
     *
     * @return list<Article>
     */
    public function listByOffset(int $limit, int $offset): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles ORDER BY id DESC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
        return array_map(Article::fromRow(...), $rows);
    }

    /**
     * Cursor-based pagination — SELECT WHERE id < cursor LIMIT ?
     * Constant-time regardless of page depth: the index seek jumps directly to
     * the cursor position without scanning prior rows.
     *
     * @return list<Article>
     */
    public function listByCursor(int $limit, ?int $afterId): array
    {
        if ($afterId === null) {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM articles ORDER BY id DESC LIMIT ?',
                [$limit],
            );
        } else {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
                [$afterId, $limit],
            );
        }
        return array_map(Article::fromRow(...), $rows);
    }

    public function count(): int
    {
        $row = $this->executor->fetchOne('SELECT COUNT(*) as cnt FROM articles', []);
        return (int) ($row['cnt'] ?? 0);
    }

    /** Bulk-insert N rows for performance seeding. */
    public function seed(int $count): void
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        for ($i = 1; $i <= $count; $i++) {
            $this->executor->insert(
                'INSERT INTO articles (title, author, category, body, created_at) VALUES (?, ?, ?, ?, ?)',
                ["Article {$i}", "Author " . ($i % 100 + 1), $i % 5 === 0 ? 'tech' : 'general', '', $now],
            );
        }
    }
}
