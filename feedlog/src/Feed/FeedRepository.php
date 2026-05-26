<?php

declare(strict_types=1);

namespace FeedLog\Feed;

use Nene2\Database\DatabaseQueryExecutorInterface;

class FeedRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    /** @return array<string, mixed>|null */
    public function findUserById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function isFollowing(int $followerId, int $followeeId): bool
    {
        $row = $this->executor->fetchOne(
            'SELECT id FROM follows WHERE follower_id = ? AND followee_id = ?',
            [$followerId, $followeeId]
        );
        return $row !== null;
    }

    public function follow(int $followerId, int $followeeId, string $now): int
    {
        return $this->executor->insert(
            'INSERT INTO follows (follower_id, followee_id, created_at) VALUES (?, ?, ?)',
            [$followerId, $followeeId, $now]
        );
    }

    public function unfollow(int $followerId, int $followeeId): void
    {
        $this->executor->execute(
            'DELETE FROM follows WHERE follower_id = ? AND followee_id = ?',
            [$followerId, $followeeId]
        );
    }

    public function postActivity(int $actorId, string $type, ?int $objectId, ?string $objectType, string $summary, bool $isPublic, string $now): int
    {
        return $this->executor->insert(
            'INSERT INTO activities (actor_id, type, object_id, object_type, summary, is_public, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$actorId, $type, $objectId, $objectType, $summary, $isPublic ? 1 : 0, $now]
        );
    }

    /** @return list<array<string, mixed>> */
    public function getFeed(int $userId, int $limit, ?int $beforeId): array
    {
        if ($beforeId !== null) {
            return $this->executor->fetchAll(
                'SELECT a.*, u.name as actor_name FROM activities a JOIN users u ON a.actor_id = u.id WHERE (a.actor_id IN (SELECT followee_id FROM follows WHERE follower_id = ?) OR a.actor_id = ?) AND a.is_public = 1 AND a.id < ? ORDER BY a.id DESC LIMIT ?',
                [$userId, $userId, $beforeId, $limit]
            );
        }
        return $this->executor->fetchAll(
            'SELECT a.*, u.name as actor_name FROM activities a JOIN users u ON a.actor_id = u.id WHERE (a.actor_id IN (SELECT followee_id FROM follows WHERE follower_id = ?) OR a.actor_id = ?) AND a.is_public = 1 ORDER BY a.id DESC LIMIT ?',
            [$userId, $userId, $limit]
        );
    }

    /** @return list<array<string, mixed>> */
    public function getUserActivities(int $actorId, int $viewerId, int $limit, ?int $beforeId): array
    {
        $isOwner = $actorId === $viewerId;
        $publicFilter = $isOwner ? '' : ' AND a.is_public = 1';

        if ($beforeId !== null) {
            return $this->executor->fetchAll(
                "SELECT a.*, u.name as actor_name FROM activities a JOIN users u ON a.actor_id = u.id WHERE a.actor_id = ?$publicFilter AND a.id < ? ORDER BY a.id DESC LIMIT ?",
                [$actorId, $beforeId, $limit]
            );
        }
        return $this->executor->fetchAll(
            "SELECT a.*, u.name as actor_name FROM activities a JOIN users u ON a.actor_id = u.id WHERE a.actor_id = ?$publicFilter ORDER BY a.id DESC LIMIT ?",
            [$actorId, $limit]
        );
    }

    /** @return array<string, mixed>|null */
    public function findActivityById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM activities WHERE id = ?', [$id]);
    }
}
