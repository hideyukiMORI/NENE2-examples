<?php

declare(strict_types=1);

namespace Audit\Task;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class TaskRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function create(string $title, string $body, int $actorId): Task
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->executor->execute(
            'INSERT INTO tasks (title, body, status, actor_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$title, $body, 'open', $actorId, $now, $now],
        );

        return $this->findById((int) $this->executor->lastInsertId())
            ?? throw new \RuntimeException('Failed to create task.');
    }

    /** @return list<Task> */
    public function findByActor(int $actorId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM tasks WHERE actor_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
            [$actorId, $limit, $offset],
        );

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function findById(int $id): ?Task
    {
        $rows = $this->executor->fetchAll('SELECT * FROM tasks WHERE id = ?', [$id]);

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    public function update(int $id, string $title, string $body, string $status): ?Task
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->executor->execute(
            'UPDATE tasks SET title = ?, body = ?, status = ?, updated_at = ? WHERE id = ?',
            [$title, $body, $status, $now, $id],
        );

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $this->executor->execute('DELETE FROM tasks WHERE id = ?', [$id]);

        return true;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Task
    {
        return new Task(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['body'],
            (string) $row['status'],
            (int) $row['actor_id'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
