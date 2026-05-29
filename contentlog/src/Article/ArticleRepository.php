<?php

declare(strict_types=1);

namespace ContentLog\Article;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ArticleRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function create(string $title, string $body, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO articles (title, body, created_at) VALUES (?, ?, ?)',
            [$title, $body, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM articles WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT * FROM articles ORDER BY id DESC', []);
    }
}
