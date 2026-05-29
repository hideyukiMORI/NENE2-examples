<?php

declare(strict_types=1);

namespace BulkUpdateLog\Task;

use Nene2\Database\DatabaseQueryExecutorInterface;

class TaskRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function create(int $userId, string $title, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO tasks (user_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $title, TaskStatus::Pending->value, $now, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function findOwned(int $id, int $userId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM tasks WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /** @return list<array<string, mixed>> */
    public function listOwned(int $userId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT * FROM tasks WHERE user_id = ? ORDER BY id ASC', [$userId]);
    }

    /**
     * Owner-scoped per-item update — a row belonging to another user (or absent)
     * affects 0 rows and is reported as a failure, never silently mutated.
     */
    public function updateOwnedStatus(int $id, int $userId, TaskStatus $status, string $now): bool
    {
        return $this->db->execute(
            'UPDATE tasks SET status = ?, updated_at = ? WHERE id = ? AND user_id = ?',
            [$status->value, $now, $id, $userId],
        ) > 0;
    }

    /**
     * Owner-scoped homogeneous update. Returns the ids that now hold the target
     * status (i.e. those that existed and belonged to the caller).
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function bulkSetOwnedStatus(array $ids, int $userId, TaskStatus $status, string $now): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->execute(
            "UPDATE tasks SET status = ?, updated_at = ? WHERE user_id = ? AND id IN ({$placeholders})",
            [$status->value, $now, $userId, ...$ids],
        );
        $rows = $this->db->fetchAll(
            "SELECT id FROM tasks WHERE user_id = ? AND status = ? AND id IN ({$placeholders})",
            [$userId, $status->value, ...$ids],
        );
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }
}
