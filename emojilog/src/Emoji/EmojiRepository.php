<?php

declare(strict_types=1);

namespace Emoji\Emoji;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

final class EmojiRepository
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

    public function createPost(int $authorId, string $content, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO posts (author_id, content, created_at) VALUES (?, ?, ?)',
            [$authorId, $content, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    public function findPostById(int $id): bool
    {
        return $this->executor->fetchOne('SELECT id FROM posts WHERE id = ?', [$id]) !== null;
    }

    /**
     * @return bool true=added, false=already exists (duplicate)
     */
    public function addReaction(int $postId, int $userId, string $emoji, string $now): bool
    {
        try {
            $this->executor->execute(
                'INSERT INTO reactions (post_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?)',
                [$postId, $userId, $emoji, $now],
            );

            return true;
        } catch (DatabaseConstraintException) {
            return false;
        }
    }

    public function removeReaction(int $postId, int $userId, string $emoji): bool
    {
        $count = $this->executor->execute(
            'DELETE FROM reactions WHERE post_id = ? AND user_id = ? AND emoji = ?',
            [$postId, $userId, $emoji],
        );

        return $count > 0;
    }

    /**
     * @return array<string, int> emoji => count
     */
    public function getReactionCounts(int $postId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT emoji, COUNT(*) as cnt FROM reactions WHERE post_id = ? GROUP BY emoji ORDER BY cnt DESC, emoji ASC',
            [$postId],
        );

        $counts = [];

        foreach ($rows as $row) {
            $arr = (array) $row;

            if (isset($arr['emoji']) && is_string($arr['emoji'])) {
                $counts[$arr['emoji']] = isset($arr['cnt']) ? (int) $arr['cnt'] : 0;
            }
        }

        return $counts;
    }

    /**
     * @return list<string> list of emojis the user has reacted with on this post
     */
    public function getUserReactions(int $postId, int $userId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT emoji FROM reactions WHERE post_id = ? AND user_id = ? ORDER BY emoji ASC',
            [$postId, $userId],
        );

        $result = [];

        foreach ($rows as $row) {
            $arr = (array) $row;

            if (isset($arr['emoji']) && is_string($arr['emoji'])) {
                $result[] = $arr['emoji'];
            }
        }

        return $result;
    }
}
