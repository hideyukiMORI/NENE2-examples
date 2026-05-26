<?php

declare(strict_types=1);

namespace Audit\AuditLog;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class AuditRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function record(int $actorId, string $action, string $resourceType, int $resourceId, array $payload): AuditEntry
    {
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->executor->execute(
            'INSERT INTO audit_log (actor_id, action, resource_type, resource_id, occurred_at, payload) VALUES (?, ?, ?, ?, ?, ?)',
            [$actorId, $action, $resourceType, $resourceId, $now, $payloadJson],
        );

        return $this->findById((int) $this->executor->lastInsertId())
            ?? throw new \RuntimeException('Failed to record audit entry.');
    }

    public function findById(int $id): ?AuditEntry
    {
        $rows = $this->executor->fetchAll('SELECT * FROM audit_log WHERE id = ?', [$id]);

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    /**
     * @return list<AuditEntry>
     */
    public function findByResource(string $resourceType, int $resourceId, int $limit = 50): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM audit_log WHERE resource_type = ? AND resource_id = ? ORDER BY id DESC LIMIT ?',
            [$resourceType, $resourceId, $limit],
        );

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    /**
     * @return list<AuditEntry>
     */
    public function search(?int $actorId, ?string $action, ?string $resourceType, int $limit, int $offset): array
    {
        $conditions = [];
        $bindings   = [];

        if ($actorId !== null) {
            $conditions[] = 'actor_id = ?';
            $bindings[]   = $actorId;
        }
        if ($action !== null) {
            $conditions[] = 'action = ?';
            $bindings[]   = $action;
        }
        if ($resourceType !== null) {
            $conditions[] = 'resource_type = ?';
            $bindings[]   = $resourceType;
        }

        $where      = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $bindings[] = $limit;
        $bindings[] = $offset;

        $rows = $this->executor->fetchAll(
            "SELECT * FROM audit_log {$where} ORDER BY id DESC LIMIT ? OFFSET ?",
            $bindings,
        );

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AuditEntry
    {
        return new AuditEntry(
            (int) $row['id'],
            (int) $row['actor_id'],
            (string) $row['action'],
            (string) $row['resource_type'],
            (int) $row['resource_id'],
            (string) $row['occurred_at'],
            (string) $row['payload'],
        );
    }
}
