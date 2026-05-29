<?php

declare(strict_types=1);

namespace ProjTrack\Task;

use Nene2\Database\DatabaseQueryExecutorInterface;

class TaskRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null project-scoped: cross-project access returns null */
    public function findByProjectAndId(int $projectId, int $taskId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, project_id, title, status, priority, created_at, updated_at
             FROM tasks WHERE id = ? AND project_id = ?',
            [$taskId, $projectId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByProject(int $projectId, ?string $status, int $limit, int $offset): array
    {
        $where = ['project_id = ?'];
        $params = [$projectId];
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $sql = 'SELECT id, project_id, title, status, priority, created_at, updated_at FROM tasks WHERE '
            . implode(' AND ', $where)
            . ' ORDER BY priority DESC, created_at ASC, id ASC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll($sql, $params);
    }

    public function countByProject(int $projectId, ?string $status): int
    {
        $where = ['project_id = ?'];
        $params = [$projectId];
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM tasks WHERE ' . implode(' AND ', $where), $params);
        return $row === null ? 0 : (int) $row['c'];
    }

    public function create(int $projectId, string $title, string $status, int $priority, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO tasks (project_id, title, status, priority, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$projectId, $title, $status, $priority, $now, $now],
        );
    }

    /**
     * PATCH-merge: null arguments mean "keep existing".
     *
     * @param array<string, mixed> $existing
     */
    public function update(int $projectId, int $taskId, array $existing, ?string $title, ?string $status, ?int $priority, string $now): void
    {
        $this->db->execute(
            'UPDATE tasks SET title = ?, status = ?, priority = ?, updated_at = ? WHERE id = ? AND project_id = ?',
            [
                $title ?? (string) $existing['title'],
                $status ?? (string) $existing['status'],
                $priority ?? (int) $existing['priority'],
                $now,
                $taskId,
                $projectId,
            ],
        );
    }

    public function delete(int $projectId, int $taskId): void
    {
        $this->db->execute('DELETE FROM tasks WHERE id = ? AND project_id = ?', [$taskId, $projectId]);
    }
}
