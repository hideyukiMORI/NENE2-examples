<?php

declare(strict_types=1);

namespace Rank\Rank;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class RankRepository
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

    public function createLeaderboard(string $name, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO leaderboards (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    /** @return array{id: int, name: string, created_at: string}|null */
    public function findLeaderboardById(int $id): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, name, created_at FROM leaderboards WHERE id = ?',
            [$id],
        );

        return $row !== null ? $this->hydrateLeaderboard((array) $row) : null;
    }

    /** @return array{id: int, leaderboard_id: int, user_id: int, score: int, submitted_at: string}|null */
    public function findScore(int $leaderboardId, int $userId): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, leaderboard_id, user_id, score, submitted_at FROM scores WHERE leaderboard_id = ? AND user_id = ?',
            [$leaderboardId, $userId],
        );

        return $row !== null ? $this->hydrateScore((array) $row) : null;
    }

    /**
     * Submit or update score (keep best score only).
     * Returns true if this is a new personal best.
     */
    public function submitScore(int $leaderboardId, int $userId, int $score, string $now): bool
    {
        $existing = $this->findScore($leaderboardId, $userId);

        if ($existing === null) {
            $this->executor->execute(
                'INSERT INTO scores (leaderboard_id, user_id, score, submitted_at) VALUES (?, ?, ?, ?)',
                [$leaderboardId, $userId, $score, $now],
            );

            return true;
        }

        if ($score > $existing['score']) {
            $this->executor->execute(
                'UPDATE scores SET score = ?, submitted_at = ? WHERE leaderboard_id = ? AND user_id = ?',
                [$score, $now, $leaderboardId, $userId],
            );

            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{rank: int, user_id: int, score: int, submitted_at: string}>
     */
    public function getRankings(int $leaderboardId, int $limit): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT user_id, score, submitted_at FROM scores WHERE leaderboard_id = ? ORDER BY score DESC, submitted_at ASC LIMIT ?',
            [$leaderboardId, $limit],
        );

        $rankings = [];
        $rank     = 1;

        foreach ($rows as $row) {
            $arr        = (array) $row;
            $rankings[] = [
                'rank'         => $rank,
                'user_id'      => isset($arr['user_id']) ? (int) $arr['user_id'] : 0,
                'score'        => isset($arr['score']) ? (int) $arr['score'] : 0,
                'submitted_at' => isset($arr['submitted_at']) && is_string($arr['submitted_at']) ? $arr['submitted_at'] : '',
            ];
            ++$rank;
        }

        return $rankings;
    }

    /**
     * Get a user's rank in a leaderboard.
     * Returns null if user has no score.
     */
    public function getUserRank(int $leaderboardId, int $userId): ?int
    {
        $score = $this->findScore($leaderboardId, $userId);

        if ($score === null) {
            return null;
        }

        $row = $this->executor->fetchOne(
            'SELECT COUNT(*) as cnt FROM scores WHERE leaderboard_id = ? AND score > ?',
            [$leaderboardId, $score['score']],
        );

        $ahead = isset($row['cnt']) ? (int) $row['cnt'] : 0;

        return $ahead + 1;
    }

    public function deleteScore(int $leaderboardId, int $userId): bool
    {
        $count = $this->executor->execute(
            'DELETE FROM scores WHERE leaderboard_id = ? AND user_id = ?',
            [$leaderboardId, $userId],
        );

        return $count > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, created_at: string}
     */
    private function hydrateLeaderboard(array $row): array
    {
        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : 0,
            'name'       => isset($row['name']) && is_string($row['name']) ? $row['name'] : '',
            'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, leaderboard_id: int, user_id: int, score: int, submitted_at: string}
     */
    private function hydrateScore(array $row): array
    {
        return [
            'id'             => isset($row['id']) ? (int) $row['id'] : 0,
            'leaderboard_id' => isset($row['leaderboard_id']) ? (int) $row['leaderboard_id'] : 0,
            'user_id'        => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'score'          => isset($row['score']) ? (int) $row['score'] : 0,
            'submitted_at'   => isset($row['submitted_at']) && is_string($row['submitted_at']) ? $row['submitted_at'] : '',
        ];
    }
}
