<?php

declare(strict_types=1);

namespace ContentVLog\Version;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ArticleRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    public function create(string $title, string $body, string $now): int
    {
        $id = $this->db->insert(
            'INSERT INTO articles (title, body, current_version, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
            [$title, $body, $now, $now],
        );
        $this->db->insert(
            'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, 1, ?, ?, ?)',
            [$id, $title, $body, $now],
        );
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT * FROM articles WHERE id = ?', [$id]);
    }

    public function update(int $id, string $title, string $body, string $now): bool
    {
        $article = $this->find($id);
        if ($article === null) {
            return false;
        }
        $nextVersion = (int) $article['current_version'] + 1;

        $this->db->insert(
            'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
            [$title, $body, $nextVersion, $now, $id],
        );
        $this->db->insert(
            'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $nextVersion, $title, $body, $now],
        );
        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(int $articleId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, article_id, version, title, created_at FROM article_versions WHERE article_id = ? ORDER BY version ASC',
            [$articleId],
        );
    }

    /** @return array<string, mixed>|null */
    public function findVersion(int $articleId, int $version): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT * FROM article_versions WHERE article_id = ? AND version = ?',
            [$articleId, $version],
        );
    }

    public function rollback(int $id, int $version, string $now): bool
    {
        $target = $this->findVersion($id, $version);
        if ($target === null) {
            return false;
        }
        $article = $this->find($id);
        if ($article === null) {
            return false;
        }

        $nextVersion = (int) $article['current_version'] + 1;
        $title       = (string) $target['title'];
        $body        = (string) $target['body'];

        $this->db->insert(
            'UPDATE articles SET title = ?, body = ?, current_version = ?, updated_at = ? WHERE id = ?',
            [$title, $body, $nextVersion, $now, $id],
        );
        $this->db->insert(
            'INSERT INTO article_versions (article_id, version, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $nextVersion, $title, $body, $now],
        );
        return true;
    }
}
