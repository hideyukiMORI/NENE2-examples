<?php

declare(strict_types=1);

namespace DraftLog\Article;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ArticleRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function create(int $authorId, string $title, string $body, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO articles (author_id, title, body, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$authorId, $title, $body, ArticleStatus::Draft->value, $now, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM articles WHERE id = ?', [$id]);
    }

    public function updateContent(int $id, string $title, string $body, string $now): void
    {
        $this->db->execute(
            'UPDATE articles SET title = ?, body = ?, updated_at = ? WHERE id = ?',
            [$title, $body, $now, $id],
        );
    }

    public function publish(int $id, string $now): void
    {
        $this->db->execute(
            'UPDATE articles SET status = ?, published_at = ?, updated_at = ? WHERE id = ?',
            [ArticleStatus::Published->value, $now, $now, $id],
        );
    }

    public function archive(int $id, string $now): void
    {
        $this->db->execute(
            'UPDATE articles SET status = ?, archived_at = ?, updated_at = ? WHERE id = ?',
            [ArticleStatus::Archived->value, $now, $now, $id],
        );
    }

    /**
     * Published articles only, newest first, with id as a same-second tiebreaker.
     *
     * @return list<array<string, mixed>>
     */
    public function listPublished(int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT * FROM articles WHERE status = ? ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?',
            [ArticleStatus::Published->value, $limit, $offset],
        );
    }
}
