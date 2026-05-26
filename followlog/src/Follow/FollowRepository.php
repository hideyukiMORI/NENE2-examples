<?php

declare(strict_types=1);

namespace Follow\Follow;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class FollowRepository
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

    public function isFollowing(int $followerId, int $followeeId): bool
    {
        return $this->executor->fetchOne(
            'SELECT id FROM follows WHERE follower_id = ? AND followee_id = ?',
            [$followerId, $followeeId],
        ) !== null;
    }

    /**
     * Follow a user. Idempotent — following an already-followed user is not an error.
     */
    public function follow(int $followerId, int $followeeId, string $now): bool
    {
        if ($this->isFollowing($followerId, $followeeId)) {
            return false; // already following
        }

        $this->executor->execute(
            'INSERT INTO follows (follower_id, followee_id, created_at) VALUES (?, ?, ?)',
            [$followerId, $followeeId, $now],
        );

        return true;
    }

    public function unfollow(int $followerId, int $followeeId): bool
    {
        $count = $this->executor->execute(
            'DELETE FROM follows WHERE follower_id = ? AND followee_id = ?',
            [$followerId, $followeeId],
        );

        return $count > 0;
    }

    public function followerCount(int $userId): int
    {
        return $this->count('SELECT COUNT(*) as cnt FROM follows WHERE followee_id = ?', $userId);
    }

    public function followingCount(int $userId): int
    {
        return $this->count('SELECT COUNT(*) as cnt FROM follows WHERE follower_id = ?', $userId);
    }

    /** @return array<int, array{id: int, name: string}> */
    public function listFollowers(int $userId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT u.id, u.name FROM users u
             INNER JOIN follows f ON f.follower_id = u.id
             WHERE f.followee_id = ?
             ORDER BY f.id DESC',
            [$userId],
        );

        return array_map(fn(mixed $row) => $this->hydrateUser((array) $row), $rows);
    }

    /** @return array<int, array{id: int, name: string}> */
    public function listFollowing(int $userId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT u.id, u.name FROM users u
             INNER JOIN follows f ON f.followee_id = u.id
             WHERE f.follower_id = ?
             ORDER BY f.id DESC',
            [$userId],
        );

        return array_map(fn(mixed $row) => $this->hydrateUser((array) $row), $rows);
    }

    private function count(string $sql, int $userId): int
    {
        $row = $this->executor->fetchOne($sql, [$userId]);

        if ($row === null) {
            return 0;
        }

        $arr = (array) $row;

        return isset($arr['cnt']) ? (int) $arr['cnt'] : 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string}
     */
    private function hydrateUser(array $row): array
    {
        return [
            'id'   => isset($row['id']) ? (int) $row['id'] : 0,
            'name' => isset($row['name']) && is_string($row['name']) ? $row['name'] : '',
        ];
    }
}
