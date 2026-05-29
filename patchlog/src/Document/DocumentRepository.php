<?php

declare(strict_types=1);

namespace PatchLog\Document;

use Nene2\Database\DatabaseQueryExecutorInterface;

class DocumentRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, owner_id, title, status, version, created_at, updated_at FROM documents WHERE id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listByPage(int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, owner_id, title, status, version, created_at, updated_at FROM documents ORDER BY id ASC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    public function count(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM documents');
        return $row === null ? 0 : (int) $row['c'];
    }

    public function create(int $ownerId, string $title, string $status, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO documents (owner_id, title, status, version, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
            [$ownerId, $title, $status, $now, $now],
        );
    }

    /** Replace mutable fields and bump the version (optimistic-lock token). */
    public function update(int $id, string $title, string $status, string $now): void
    {
        $this->db->execute(
            'UPDATE documents SET title = ?, status = ?, version = version + 1, updated_at = ? WHERE id = ?',
            [$title, $status, $now, $id],
        );
    }

    public function delete(int $id, int $ownerId): bool
    {
        return $this->db->execute('DELETE FROM documents WHERE id = ? AND owner_id = ?', [$id, $ownerId]) > 0;
    }
}
