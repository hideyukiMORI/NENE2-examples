<?php

declare(strict_types=1);

namespace IsolationLog\Tenant;

use Nene2\Database\DatabaseQueryExecutorInterface;

class IsolationRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findTenant(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name, created_at FROM tenants WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listTenants(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT id, name, created_at FROM tenants ORDER BY id ASC');
    }

    public function createTenant(string $name, string $now): int
    {
        return $this->db->insert('INSERT INTO tenants (name, created_at) VALUES (?, ?)', [$name, $now]);
    }

    /**
     * Always tenant-scoped: a note from another tenant returns null (→ 404),
     * never leaks existence.
     *
     * @return array<string, mixed>|null
     */
    public function findNote(int $id, int $tenantId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, tenant_id, user_id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listNotes(int $tenantId, int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, tenant_id, user_id, title, body, created_at FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
            [$tenantId, $limit, $offset],
        );
    }

    public function createNote(int $tenantId, int $userId, string $title, string $body, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO notes (tenant_id, user_id, title, body, created_at) VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $userId, $title, $body, $now],
        );
    }

    public function deleteNote(int $id, int $tenantId): bool
    {
        return $this->db->execute('DELETE FROM notes WHERE id = ? AND tenant_id = ?', [$id, $tenantId]) > 0;
    }
}
