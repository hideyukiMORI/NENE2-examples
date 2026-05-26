<?php

declare(strict_types=1);

namespace Meterlog\Meter;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class MeterRepository
{
    /** Default daily limit when no quota row exists. */
    public const int DEFAULT_DAILY_LIMIT = 1000;

    public function __construct(
        private DatabaseQueryExecutorInterface $db,
    ) {
    }

    // ── Quota management ─────────────────────────────────────────────────

    /**
     * Creates or updates a user's daily quota limit.
     */
    public function upsertQuota(int $userId, int $dailyLimit, string $now): Quota
    {
        $existing = $this->db->fetchOne('SELECT * FROM quotas WHERE user_id = ?', [$userId]);

        if ($existing === null) {
            $id = $this->db->insert(
                'INSERT INTO quotas (user_id, daily_limit, created_at, updated_at) VALUES (?, ?, ?, ?)',
                [$userId, $dailyLimit, $now, $now],
            );

            return new Quota($id, $userId, $dailyLimit, $now, $now);
        }

        $this->db->execute(
            'UPDATE quotas SET daily_limit = ?, updated_at = ? WHERE user_id = ?',
            [$dailyLimit, $now, $userId],
        );

        return new Quota(
            (int) $existing['id'],
            $userId,
            $dailyLimit,
            (string) $existing['created_at'],
            $now,
        );
    }

    public function findQuota(int $userId): ?Quota
    {
        $row = $this->db->fetchOne('SELECT * FROM quotas WHERE user_id = ?', [$userId]);

        return $row !== null ? $this->hydrateQuota($row) : null;
    }

    // ── Usage recording ──────────────────────────────────────────────────

    /**
     * Records a single API usage event.
     */
    public function record(int $userId, string $endpoint, string $now): void
    {
        $dayKey = substr($now, 0, 10); // YYYY-MM-DD from ISO 8601

        $this->db->insert(
            'INSERT INTO usage_events (user_id, endpoint, day_key, recorded_at) VALUES (?, ?, ?, ?)',
            [$userId, $endpoint, $dayKey, $now],
        );
    }

    // ── Usage queries ────────────────────────────────────────────────────

    /**
     * Returns the total call count for a user on the given day.
     */
    public function countForDay(int $userId, string $dayKey): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM usage_events WHERE user_id = ? AND day_key = ?',
            [$userId, $dayKey],
        );

        return $row !== null ? (int) $row['cnt'] : 0;
    }

    /**
     * Returns per-endpoint call counts for a user on the given day.
     *
     * @return list<array{endpoint: string, count: int}>
     */
    public function breakdownForDay(int $userId, string $dayKey): array
    {
        $rows = $this->db->fetchAll(
            'SELECT endpoint, COUNT(*) AS cnt
             FROM usage_events
             WHERE user_id = ? AND day_key = ?
             GROUP BY endpoint
             ORDER BY cnt DESC',
            [$userId, $dayKey],
        );

        return array_map(
            static fn (array $r): array => [
                'endpoint' => (string) $r['endpoint'],
                'count'    => (int) $r['cnt'],
            ],
            $rows,
        );
    }

    /**
     * Returns a quota status summary for today.
     *
     * @return array{user_id: int, day: string, daily_limit: int, used: int, remaining: int, allowed: bool}
     */
    public function statusForToday(int $userId, string $today): array
    {
        $quota = $this->findQuota($userId);
        $limit = $quota !== null ? $quota->dailyLimit : self::DEFAULT_DAILY_LIMIT;
        $used  = $this->countForDay($userId, $today);

        return [
            'user_id'     => $userId,
            'day'         => $today,
            'daily_limit' => $limit,
            'used'        => $used,
            'remaining'   => max(0, $limit - $used),
            'allowed'     => $used < $limit,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrateQuota(array $row): Quota
    {
        return new Quota(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['daily_limit'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
