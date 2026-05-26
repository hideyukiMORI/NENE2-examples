<?php

declare(strict_types=1);

namespace Opt\Article;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class ArticleRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function create(string $title, string $body): Article
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $id = $this->executor->insert(
            'INSERT INTO articles (title, body, version, updated_at) VALUES (?, ?, 1, ?)',
            [$title, $body, $now],
        );

        return new Article(id: $id, title: $title, body: $body, version: 1, updatedAt: $now);
    }

    public function findById(int $id): ?Article
    {
        /** @var array{id: int, title: string, body: string, version: int, updated_at: string}|null $row */
        $row = $this->executor->fetchOne(
            'SELECT id, title, body, version, updated_at FROM articles WHERE id = ?',
            [$id],
        );

        if ($row === null) {
            return null;
        }

        return new Article(
            id:        $row['id'],
            title:     $row['title'],
            body:      $row['body'],
            version:   $row['version'],
            updatedAt: $row['updated_at'],
        );
    }

    /**
     * Update an article using optimistic locking.
     *
     * The UPDATE only succeeds if the current version in the database matches
     * $expectedVersion. If another writer has incremented the version since the
     * caller last read the record, execute() returns 0 affected rows and a
     * ConflictException is thrown.
     *
     * @throws ConflictException if another writer updated the record first
     * @throws \RuntimeException if the article does not exist
     */
    public function update(int $id, string $title, string $body, int $expectedVersion): Article
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // WHERE version = $expectedVersion is the optimistic lock check
        $affected = $this->executor->execute(
            'UPDATE articles SET title = ?, body = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
            [$title, $body, $now, $id, $expectedVersion],
        );

        if ($affected === 0) {
            // Distinguish "not found" from "version conflict"
            $current = $this->findById($id);
            if ($current === null) {
                throw new \RuntimeException("Article {$id} does not exist.");
            }
            // Record exists but version did not match — concurrent writer detected
            throw new ConflictException($id, $expectedVersion);
        }

        return new Article(
            id:        $id,
            title:     $title,
            body:      $body,
            version:   $expectedVersion + 1,
            updatedAt: $now,
        );
    }
}
