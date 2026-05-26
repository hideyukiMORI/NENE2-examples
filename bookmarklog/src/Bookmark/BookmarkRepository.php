<?php

declare(strict_types=1);

namespace Bookmark\Bookmark;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

final class BookmarkRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {}

    public function createUser(string $name, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO users (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    public function findUserById(int $id): bool
    {
        return $this->executor->fetchOne('SELECT id FROM users WHERE id = ?', [$id]) !== null;
    }

    public function createItem(string $title, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO items (title, created_at) VALUES (?, ?)',
            [$title, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    public function findItemById(int $id): bool
    {
        return $this->executor->fetchOne('SELECT id FROM items WHERE id = ?', [$id]) !== null;
    }

    /**
     * Add a bookmark. Returns null if already bookmarked (idempotent — not an error).
     */
    public function add(int $userId, int $itemId, string $collection, string $now): Bookmark
    {
        $existing = $this->find($userId, $itemId);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $this->executor->execute(
                'INSERT INTO bookmarks (user_id, item_id, collection, created_at) VALUES (?, ?, ?, ?)',
                [$userId, $itemId, $collection, $now],
            );
        } catch (DatabaseConstraintException) {
            // Race condition — another request beat us; return the existing bookmark
            $found = $this->find($userId, $itemId);

            if ($found !== null) {
                return $found;
            }
        }

        $id = (int) $this->executor->lastInsertId();

        return new Bookmark($id, $userId, $itemId, $collection, $now);
    }

    public function remove(int $userId, int $itemId): bool
    {
        $count = $this->executor->execute(
            'DELETE FROM bookmarks WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId],
        );

        return $count > 0;
    }

    public function find(int $userId, int $itemId): ?Bookmark
    {
        $row = $this->executor->fetchOne(
            'SELECT * FROM bookmarks WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId],
        );

        if ($row === null) {
            return null;
        }

        return $this->hydrate((array) $row);
    }

    /** @return Bookmark[] */
    public function listByUser(int $userId, ?string $collection = null): array
    {
        if ($collection !== null) {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM bookmarks WHERE user_id = ? AND collection = ? ORDER BY id DESC',
                [$userId, $collection],
            );
        } else {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM bookmarks WHERE user_id = ? ORDER BY id DESC',
                [$userId],
            );
        }

        return array_map(fn(mixed $row) => $this->hydrate((array) $row), $rows);
    }

    public function countByUser(int $userId): int
    {
        $row = $this->executor->fetchOne(
            'SELECT COUNT(*) as cnt FROM bookmarks WHERE user_id = ?',
            [$userId],
        );

        if ($row === null) {
            return 0;
        }

        $arr = (array) $row;

        return isset($arr['cnt']) ? (int) $arr['cnt'] : 0;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Bookmark
    {
        return new Bookmark(
            id: isset($row['id']) ? (int) $row['id'] : 0,
            userId: isset($row['user_id']) ? (int) $row['user_id'] : 0,
            itemId: isset($row['item_id']) ? (int) $row['item_id'] : 0,
            collection: isset($row['collection']) && is_string($row['collection']) ? $row['collection'] : 'default',
            createdAt: isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
        );
    }
}
