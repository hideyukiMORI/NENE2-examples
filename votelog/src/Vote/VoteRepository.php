<?php

declare(strict_types=1);

namespace Vote\Vote;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class VoteRepository
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

    public function getCurrentVote(int $userId, int $itemId): ?VoteDirection
    {
        $row = $this->executor->fetchOne(
            'SELECT direction FROM votes WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId],
        );

        if ($row === null) {
            return null;
        }

        $arr = (array) $row;
        $dir = isset($arr['direction']) && is_string($arr['direction']) ? $arr['direction'] : '';

        return VoteDirection::tryFrom($dir);
    }

    /**
     * Cast or toggle a vote.
     * - Same direction as existing vote → delete (toggle off), return null
     * - Different direction → update to new direction, return new direction
     * - No existing vote → insert, return new direction
     */
    public function castVote(int $userId, int $itemId, VoteDirection $direction, string $now): ?VoteDirection
    {
        $current = $this->getCurrentVote($userId, $itemId);

        if ($current === $direction) {
            $this->executor->execute(
                'DELETE FROM votes WHERE user_id = ? AND item_id = ?',
                [$userId, $itemId],
            );

            return null;
        }

        if ($current !== null) {
            $this->executor->execute(
                'UPDATE votes SET direction = ?, created_at = ? WHERE user_id = ? AND item_id = ?',
                [$direction->value, $now, $userId, $itemId],
            );
        } else {
            $this->executor->execute(
                'INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)',
                [$userId, $itemId, $direction->value, $now],
            );
        }

        return $direction;
    }

    public function getScore(int $itemId): ItemScore
    {
        $upRow = $this->executor->fetchOne(
            "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'up'",
            [$itemId],
        );
        $downRow = $this->executor->fetchOne(
            "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'down'",
            [$itemId],
        );

        $upArr   = $upRow !== null ? (array) $upRow : [];
        $downArr = $downRow !== null ? (array) $downRow : [];

        return new ItemScore(
            itemId: $itemId,
            upvotes: isset($upArr['cnt']) ? (int) $upArr['cnt'] : 0,
            downvotes: isset($downArr['cnt']) ? (int) $downArr['cnt'] : 0,
        );
    }
}
