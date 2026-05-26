<?php

declare(strict_types=1);

namespace AbLog\Ab;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ExperimentRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    public function create(string $name, string $description, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO experiments (name, description, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$name, $description, 'draft', $now, $now],
        );
    }

    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT * FROM experiments ORDER BY id ASC', []);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT * FROM experiments WHERE id = ?', [$id]);
    }

    public function updateStatus(int $id, string $status, string $now): bool
    {
        if ($this->find($id) === null) {
            return false;
        }
        $this->db->insert(
            'UPDATE experiments SET status = ?, updated_at = ? WHERE id = ?',
            [$status, $now, $id],
        );
        return true;
    }

    public function addVariant(int $experimentId, string $name, int $weight): int
    {
        return $this->db->insert(
            'INSERT INTO experiment_variants (experiment_id, name, weight) VALUES (?, ?, ?)',
            [$experimentId, $name, $weight],
        );
    }

    /** @return list<array<string, mixed>> */
    public function findVariants(int $experimentId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT * FROM experiment_variants WHERE experiment_id = ? ORDER BY id ASC',
            [$experimentId],
        );
    }

    /** @return array<string, mixed>|null */
    public function findAssignment(int $experimentId, string $userId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT ea.*, ev.name AS variant_name FROM experiment_assignments ea
             JOIN experiment_variants ev ON ea.variant_id = ev.id
             WHERE ea.experiment_id = ? AND ea.user_id = ?',
            [$experimentId, $userId],
        );
    }

    public function createAssignment(int $experimentId, string $userId, int $variantId, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO experiment_assignments (experiment_id, user_id, variant_id, assigned_at) VALUES (?, ?, ?, ?)',
            [$experimentId, $userId, $variantId, $now],
        );
    }

    public function recordEvent(int $experimentId, int $assignmentId, string $eventType, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO experiment_events (experiment_id, assignment_id, event_type, created_at) VALUES (?, ?, ?, ?)',
            [$experimentId, $assignmentId, $eventType, $now],
        );
    }

    /** @return list<array<string, mixed>> */
    public function getResults(int $experimentId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT ev.id AS variant_id, ev.name AS variant_name, ev.weight,
                    COUNT(DISTINCT ea.id) AS assignments,
                    COUNT(ee.id) AS events
             FROM experiment_variants ev
             LEFT JOIN experiment_assignments ea ON ea.variant_id = ev.id
             LEFT JOIN experiment_events ee ON ee.assignment_id = ea.id
             WHERE ev.experiment_id = ?
             GROUP BY ev.id, ev.name, ev.weight
             ORDER BY ev.id ASC',
            [$experimentId],
        );
    }
}
