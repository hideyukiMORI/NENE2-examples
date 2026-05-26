<?php

declare(strict_types=1);

namespace CollectionLog\Collection;

use Nene2\Database\DatabaseQueryExecutorInterface;

class CollectionRepository
{
    private const int MAX_ITEMS = 50;

    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findUserById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findArticleById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM articles WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findCollectionById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM collections WHERE id = ?', [$id]);
    }

    public function createCollection(int $userId, string $name, bool $isPublic, string $now): int
    {
        return $this->executor->insert(
            'INSERT INTO collections (user_id, name, is_public, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $name, $isPublic ? 1 : 0, $now, $now]
        );
    }

    public function updateCollection(int $id, string $name, bool $isPublic, string $now): void
    {
        $this->executor->execute(
            'UPDATE collections SET name = ?, is_public = ?, updated_at = ? WHERE id = ?',
            [$name, $isPublic ? 1 : 0, $now, $id]
        );
    }

    public function deleteCollection(int $id): void
    {
        $this->executor->execute('DELETE FROM collection_items WHERE collection_id = ?', [$id]);
        $this->executor->execute('DELETE FROM collections WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listItems(int $collectionId): array
    {
        return $this->executor->fetchAll(
            'SELECT ci.*, a.title as article_title FROM collection_items ci JOIN articles a ON ci.article_id = a.id WHERE ci.collection_id = ? ORDER BY ci.position ASC',
            [$collectionId]
        );
    }

    public function countItems(int $collectionId): int
    {
        $row = $this->executor->fetchOne('SELECT COUNT(*) as c FROM collection_items WHERE collection_id = ?', [$collectionId]);
        return (int) ($row['c'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    public function findItem(int $collectionId, int $articleId): ?array
    {
        return $this->executor->fetchOne(
            'SELECT * FROM collection_items WHERE collection_id = ? AND article_id = ?',
            [$collectionId, $articleId]
        );
    }

    public function addItem(int $collectionId, int $articleId, string $now): int
    {
        $maxRow = $this->executor->fetchOne(
            'SELECT COALESCE(MAX(position), 0) as m FROM collection_items WHERE collection_id = ?',
            [$collectionId]
        );
        $nextPosition = (int) ($maxRow['m'] ?? 0) + 1;
        return $this->executor->insert(
            'INSERT INTO collection_items (collection_id, article_id, position, added_at) VALUES (?, ?, ?, ?)',
            [$collectionId, $articleId, $nextPosition, $now]
        );
    }

    public function removeItem(int $collectionId, int $articleId): void
    {
        $item = $this->findItem($collectionId, $articleId);
        if ($item === null) {
            return;
        }
        $removedPosition = (int) $item['position'];
        $this->executor->execute(
            'DELETE FROM collection_items WHERE collection_id = ? AND article_id = ?',
            [$collectionId, $articleId]
        );
        $this->executor->execute(
            'UPDATE collection_items SET position = position - 1 WHERE collection_id = ? AND position > ?',
            [$collectionId, $removedPosition]
        );
    }

    public static function maxItems(): int
    {
        return self::MAX_ITEMS;
    }
}
