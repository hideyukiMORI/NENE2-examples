<?php

declare(strict_types=1);

namespace Rbac\Blog;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class PostRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    /** @return list<Post> */
    public function findAll(): array
    {
        /** @var list<array{id: int, title: string, body: string, author_id: int, created_at: string}> $rows */
        $rows = $this->executor->fetchAll('SELECT id, title, body, author_id, created_at FROM posts ORDER BY id DESC', []);

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?Post
    {
        /** @var array{id: int, title: string, body: string, author_id: int, created_at: string}|null $row */
        $row = $this->executor->fetchOne('SELECT id, title, body, author_id, created_at FROM posts WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function create(string $title, string $body, int $authorId): Post
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $id  = $this->executor->insert(
            'INSERT INTO posts (title, body, author_id, created_at) VALUES (?, ?, ?, ?)',
            [$title, $body, $authorId, $now],
        );

        return new Post(id: $id, title: $title, body: $body, authorId: $authorId, createdAt: $now);
    }

    public function delete(int $id): void
    {
        $this->executor->execute('DELETE FROM posts WHERE id = ?', [$id]);
    }

    /** @param array{id: int, title: string, body: string, author_id: int, created_at: string} $row */
    private function hydrate(array $row): Post
    {
        return new Post(
            id: $row['id'],
            title: $row['title'],
            body: $row['body'],
            authorId: $row['author_id'],
            createdAt: $row['created_at'],
        );
    }
}
