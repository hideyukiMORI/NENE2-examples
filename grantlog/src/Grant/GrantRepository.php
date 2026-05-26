<?php

declare(strict_types=1);

namespace Grantlog\Grant;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class GrantRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $db,
    ) {
    }

    // ── Create ────────────────────────────────────────────────────────────

    /**
     * Creates a new delegated access grant.
     *
     * @throws \RuntimeException when a duplicate grant already exists
     */
    public function create(
        int        $grantorId,
        int        $granteeId,
        string     $resource,
        GrantScope $scope,
        string     $expiresAt,
        string     $now,
    ): Grant {
        $id = $this->db->insert(
            'INSERT INTO grants (grantor_id, grantee_id, resource, scope, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$grantorId, $granteeId, $resource, $scope->value, $expiresAt, $now],
        );

        return new Grant($id, $grantorId, $granteeId, $resource, $scope, $expiresAt, null, 0, $now);
    }

    // ── Find ──────────────────────────────────────────────────────────────

    public function find(int $id): ?Grant
    {
        $row = $this->db->fetchOne('SELECT * FROM grants WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * Returns all grants issued by the grantor, newest first.
     *
     * @return list<Grant>
     */
    public function findByGrantor(int $grantorId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM grants WHERE grantor_id = ? ORDER BY id DESC',
            [$grantorId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Returns all grants received by the grantee, newest first.
     *
     * @return list<Grant>
     */
    public function findByGrantee(int $granteeId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM grants WHERE grantee_id = ? ORDER BY id DESC',
            [$granteeId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    // ── Revoke ────────────────────────────────────────────────────────────

    /**
     * Revokes an active grant.
     *
     * @throws \RuntimeException if the grant is already revoked
     */
    public function revoke(int $id, string $now): Grant
    {
        $grant = $this->find($id);

        if ($grant === null) {
            throw new \RuntimeException("Grant #{$id} not found.");
        }

        if ($grant->isRevoked()) {
            throw new \RuntimeException("Grant #{$id} is already revoked.");
        }

        $this->db->execute(
            'UPDATE grants SET revoked_at = ? WHERE id = ?',
            [$now, $id],
        );

        return new Grant(
            $grant->id,
            $grant->grantorId,
            $grant->granteeId,
            $grant->resource,
            $grant->scope,
            $grant->expiresAt,
            $now,
            $grant->usedCount,
            $grant->createdAt,
        );
    }

    // ── Use ───────────────────────────────────────────────────────────────

    /**
     * Records one usage of the grant and returns the updated grant.
     * Caller must verify isActive() before calling this.
     */
    public function recordUse(int $id): void
    {
        $this->db->execute(
            'UPDATE grants SET used_count = used_count + 1 WHERE id = ?',
            [$id],
        );
    }

    // ── Hydration ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Grant
    {
        return new Grant(
            (int) $row['id'],
            (int) $row['grantor_id'],
            (int) $row['grantee_id'],
            (string) $row['resource'],
            GrantScope::from((string) $row['scope']),
            (string) $row['expires_at'],
            isset($row['revoked_at']) ? (string) $row['revoked_at'] : null,
            (int) $row['used_count'],
            (string) $row['created_at'],
        );
    }
}
