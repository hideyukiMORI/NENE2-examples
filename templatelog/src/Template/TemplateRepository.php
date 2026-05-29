<?php

declare(strict_types=1);

namespace TemplateLog\Template;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

class TemplateRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, name, body, created_at, updated_at FROM templates WHERE id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> name/metadata only — `body` excluded to keep the list light */
    public function listAll(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, name, created_at, updated_at FROM templates ORDER BY name ASC',
        );
    }

    /**
     * Create a template. Returns the new id, or null when the name already
     * exists (caught from the UNIQUE(name) constraint).
     */
    public function create(string $name, string $body, string $now): ?int
    {
        try {
            return $this->db->insert(
                'INSERT INTO templates (name, body, created_at, updated_at) VALUES (?, ?, ?, ?)',
                [$name, $body, $now, $now],
            );
        } catch (DatabaseConstraintException) {
            return null;
        }
    }

    public function updateBody(int $id, string $body, string $now): bool
    {
        return $this->db->execute(
            'UPDATE templates SET body = ?, updated_at = ? WHERE id = ?',
            [$body, $now, $id],
        ) > 0;
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM templates WHERE id = ?', [$id]) > 0;
    }
}
