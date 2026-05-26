<?php

declare(strict_types=1);

namespace Etag\Article;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class ArticleRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function create(string $title, string $body): Article
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $id  = $this->executor->insert(
            'INSERT INTO articles (title, body, updated_at) VALUES (?, ?, ?)',
            [$title, $body, $now],
        );

        return new Article(id: $id, title: $title, body: $body, updatedAt: $now);
    }

    public function findById(int $id): ?Article
    {
        /** @var array{id: int, title: string, body: string, updated_at: string}|null $row */
        $row = $this->executor->fetchOne(
            'SELECT id, title, body, updated_at FROM articles WHERE id = ?',
            [$id],
        );

        if ($row === null) {
            return null;
        }

        return new Article(
            id:        $row['id'],
            title:     $row['title'],
            body:      $row['body'],
            updatedAt: $row['updated_at'],
        );
    }

    /** @throws \RuntimeException if article does not exist */
    public function update(int $id, string $title, string $body): Article
    {
        $now      = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $affected = $this->executor->execute(
            'UPDATE articles SET title = ?, body = ?, updated_at = ? WHERE id = ?',
            [$title, $body, $now, $id],
        );

        if ($affected === 0) {
            throw new \RuntimeException("Article {$id} does not exist.");
        }

        return new Article(id: $id, title: $title, body: $body, updatedAt: $now);
    }
}
