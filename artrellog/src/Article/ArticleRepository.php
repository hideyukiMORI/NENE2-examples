<?php

declare(strict_types=1);

namespace Relations\Article;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ArticleRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, title, body, created_at FROM articles WHERE id = ?', [$id]);
    }

    public function create(string $title, string $body, string $now): int
    {
        return $this->db->insert('INSERT INTO articles (title, body, created_at) VALUES (?, ?, ?)', [$title, $body, $now]);
    }

    /**
     * Relations originating from $articleId, optionally filtered by type, with
     * the related article embedded.
     *
     * @return list<array<string, mixed>>
     */
    public function relations(int $articleId, ?string $type): array
    {
        $sql = 'SELECT r.relation_type, a.id AS related_id, a.title AS related_title
                FROM article_relations r JOIN articles a ON a.id = r.related_id
                WHERE r.article_id = ?';
        $params = [$articleId];
        if ($type !== null) {
            $sql .= ' AND r.relation_type = ?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY r.id ASC';

        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll($sql, $params);
    }

    public function relationExists(int $articleId, int $relatedId, string $type): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 AS x FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
            [$articleId, $relatedId, $type],
        ) !== null;
    }
}
