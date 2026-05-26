<?php

declare(strict_types=1);

namespace PinLog\Pin;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

final class PinRepository
{
    private const int MAX_PINS = 10;

    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function findUserById(int $userId): ?array
    {
        return $this->executor->fetchOne('SELECT id, name FROM users WHERE id = ?', [$userId]) ?: null;
    }

    public function findArticleById(int $articleId): ?array
    {
        return $this->executor->fetchOne('SELECT id, title FROM articles WHERE id = ?', [$articleId]) ?: null;
    }

    public function findPin(int $userId, int $articleId): ?array
    {
        return $this->executor->fetchOne(
            'SELECT id, article_id, position, pinned_at FROM pins WHERE user_id = ? AND article_id = ?',
            [$userId, $articleId]
        ) ?: null;
    }

    public function countPins(int $userId): int
    {
        $row = $this->executor->fetchOne('SELECT COUNT(*) as cnt FROM pins WHERE user_id = ?', [$userId]);
        return isset($row['cnt']) ? (int) $row['cnt'] : 0;
    }

    public function maxPosition(int $userId): int
    {
        $row = $this->executor->fetchOne('SELECT MAX(position) as mx FROM pins WHERE user_id = ?', [$userId]);
        return isset($row['mx']) && $row['mx'] !== null ? (int) $row['mx'] : 0;
    }

    /** @return bool true = created (201), false = already exists (200) */
    public function pin(int $userId, int $articleId, string $now): bool
    {
        $existing = $this->findPin($userId, $articleId);
        if ($existing !== null) {
            return false;
        }

        $nextPosition = $this->maxPosition($userId) + 1;
        $this->executor->execute(
            'INSERT INTO pins (user_id, article_id, position, pinned_at) VALUES (?, ?, ?, ?)',
            [$userId, $articleId, $nextPosition, $now]
        );
        return true;
    }

    public function unpin(int $userId, int $articleId): bool
    {
        $existing = $this->findPin($userId, $articleId);
        if ($existing === null) {
            return false;
        }
        $removedPosition = (int) $existing['position'];
        $this->executor->execute(
            'DELETE FROM pins WHERE user_id = ? AND article_id = ?',
            [$userId, $articleId]
        );
        // Compact positions: shift down pins above the removed one
        $this->executor->execute(
            'UPDATE pins SET position = position - 1 WHERE user_id = ? AND position > ?',
            [$userId, $removedPosition]
        );
        return true;
    }

    public function listPins(int $userId): array
    {
        return $this->executor->fetchAll(
            'SELECT p.article_id, a.title, p.position, p.pinned_at
             FROM pins p
             JOIN articles a ON a.id = p.article_id
             WHERE p.user_id = ?
             ORDER BY p.position ASC',
            [$userId]
        );
    }

    /** @param list<int> $orderedArticleIds */
    public function reorder(int $userId, array $orderedArticleIds): bool
    {
        $currentPins = $this->listPins($userId);
        $currentIds = array_map(fn (array $p) => (int) $p['article_id'], $currentPins);
        sort($currentIds);
        $sortedInput = $orderedArticleIds;
        sort($sortedInput);
        if ($currentIds !== $sortedInput) {
            return false;
        }
        foreach ($orderedArticleIds as $position => $articleId) {
            $this->executor->execute(
                'UPDATE pins SET position = ? WHERE user_id = ? AND article_id = ?',
                [$position + 1, $userId, $articleId]
            );
        }
        return true;
    }

    public function maxPins(): int
    {
        return self::MAX_PINS;
    }
}
