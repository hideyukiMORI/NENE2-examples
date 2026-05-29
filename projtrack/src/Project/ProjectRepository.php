<?php

declare(strict_types=1);

namespace ProjTrack\Project;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ProjectRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name, description, created_at, updated_at FROM projects WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function list(int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, name, description, created_at, updated_at FROM projects ORDER BY id ASC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    public function count(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM projects');
        return $row === null ? 0 : (int) $row['c'];
    }

    public function create(string $name, string $description, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO projects (name, description, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, $description, $now, $now],
        );
    }

    public function delete(int $id): bool
    {
        // ON DELETE CASCADE removes the project's tasks.
        return $this->db->execute('DELETE FROM projects WHERE id = ?', [$id]) > 0;
    }
}
