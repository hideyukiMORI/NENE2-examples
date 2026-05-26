<?php

declare(strict_types=1);

namespace Tenant\Notes;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class NoteRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    /** @return list<Note> */
    public function findAllForTenant(int $tenantId): array
    {
        /** @var list<array{id: int, tenant_id: int, title: string, body: string, created_at: string}> $rows */
        $rows = $this->executor->fetchAll(
            'SELECT id, tenant_id, title, body, created_at FROM notes WHERE tenant_id = ? ORDER BY id DESC',
            [$tenantId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    // The tenant_id filter is NOT optional — omitting it would expose all tenants' notes.
    public function findByIdForTenant(int $id, int $tenantId): ?Note
    {
        /** @var array{id: int, tenant_id: int, title: string, body: string, created_at: string}|null $row */
        $row = $this->executor->fetchOne(
            'SELECT id, tenant_id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function create(int $tenantId, string $title, string $body): Note
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $id  = $this->executor->insert(
            'INSERT INTO notes (tenant_id, title, body, created_at) VALUES (?, ?, ?, ?)',
            [$tenantId, $title, $body, $now],
        );

        return new Note(id: $id, tenantId: $tenantId, title: $title, body: $body, createdAt: $now);
    }

    public function delete(int $id, int $tenantId): bool
    {
        $note = $this->findByIdForTenant($id, $tenantId);

        if ($note === null) {
            return false;
        }

        $this->executor->execute('DELETE FROM notes WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);

        return true;
    }

    /** @param array{id: int, tenant_id: int, title: string, body: string, created_at: string} $row */
    private function hydrate(array $row): Note
    {
        return new Note(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            title: $row['title'],
            body: $row['body'],
            createdAt: $row['created_at'],
        );
    }
}
