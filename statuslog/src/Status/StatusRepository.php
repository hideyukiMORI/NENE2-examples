<?php

declare(strict_types=1);

namespace StatusLog\Status;

use Nene2\Database\DatabaseQueryExecutorInterface;

class StatusRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listComponents(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT id, name, status, created_at, updated_at FROM components ORDER BY id ASC');
    }

    /** @return array<string, mixed>|null */
    public function findComponent(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name, status, created_at, updated_at FROM components WHERE id = ?', [$id]);
    }

    public function createComponent(string $name, string $status, string $now): int
    {
        return $this->db->insert('INSERT INTO components (name, status, created_at, updated_at) VALUES (?, ?, ?, ?)', [$name, $status, $now, $now]);
    }

    public function updateComponentStatus(int $id, string $status, string $now): void
    {
        $this->db->execute('UPDATE components SET status = ?, updated_at = ? WHERE id = ?', [$status, $now, $id]);
    }

    /** @return array<string, mixed>|null */
    public function findIncident(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, title, status, impact, resolved_at, created_at, updated_at FROM incidents WHERE id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listIncidents(bool $openOnly): array
    {
        $where = $openOnly ? " WHERE status != 'resolved'" : '';
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, title, status, impact, resolved_at, created_at, updated_at FROM incidents' . $where . ' ORDER BY id DESC',
        );
    }

    public function createIncident(string $title, string $status, string $impact, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO incidents (title, status, impact, resolved_at, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, ?)',
            [$title, $status, $impact, $now, $now],
        );
    }

    public function updateIncidentStatus(int $id, string $status, ?string $resolvedAt, string $now): void
    {
        $this->db->execute(
            'UPDATE incidents SET status = ?, resolved_at = ?, updated_at = ? WHERE id = ?',
            [$status, $resolvedAt, $now, $id],
        );
    }

    public function addUpdate(int $incidentId, string $status, string $message, string $now): void
    {
        $this->db->execute(
            'INSERT INTO incident_updates (incident_id, status, message, created_at) VALUES (?, ?, ?, ?)',
            [$incidentId, $status, $message, $now],
        );
    }

    /** @return list<array<string, mixed>> */
    public function updates(int $incidentId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, status, message, created_at FROM incident_updates WHERE incident_id = ? ORDER BY id ASC',
            [$incidentId],
        );
    }
}
