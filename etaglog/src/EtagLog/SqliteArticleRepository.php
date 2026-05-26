<?php

declare(strict_types=1);

namespace EtagLog\EtagLog;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class SqliteArticleRepository
{
    public function __construct(private DatabaseQueryExecutorInterface $executor)
    {
    }

    public function create(string $title, string $content, string $now): Article
    {
        $id = $this->executor->insert(
            'INSERT INTO articles (title, content, updated_at, created_at) VALUES (?, ?, ?, ?)',
            [$title, $content, $now, $now],
        );

        return new Article($id, $title, $content, $now, $now);
    }

    public function findById(int $id): ?Article
    {
        $rows = $this->executor->fetchAll('SELECT * FROM articles WHERE id = ?', [$id]);

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    public function update(int $id, string $title, string $content, string $now): ?Article
    {
        $affected = $this->executor->execute(
            'UPDATE articles SET title = ?, content = ?, updated_at = ? WHERE id = ?',
            [$title, $content, $now, $id],
        );

        if ($affected === 0) {
            return null;
        }

        $rows = $this->executor->fetchAll('SELECT * FROM articles WHERE id = ?', [$id]);

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    /**
     * @return list<Article>
     */
    public function findAll(): array
    {
        $rows = $this->executor->fetchAll('SELECT * FROM articles ORDER BY updated_at DESC', []);

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Article
    {
        return new Article(
            id:        (int) $row['id'],
            title:     (string) $row['title'],
            content:   (string) $row['content'],
            updatedAt: (string) $row['updated_at'],
            createdAt: (string) $row['created_at'],
        );
    }
}
