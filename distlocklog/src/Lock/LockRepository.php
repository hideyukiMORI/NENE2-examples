<?php

declare(strict_types=1);

namespace Lock\Lock;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class LockRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function findByResource(string $resource): ?LockRecord
    {
        $row = $this->executor->fetchOne(
            'SELECT * FROM distributed_locks WHERE resource = ?',
            [$resource],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * Try to acquire the lock for the given resource.
     *
     * Returns the LockRecord on success, null if held by another owner.
     */
    public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
    {
        $existing = $this->findByResource($resource);

        if ($existing === null) {
            // No lock exists — insert
            try {
                $this->executor->execute(
                    'INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at) VALUES (?, ?, ?, ?)',
                    [$resource, $owner, $expiresAt, $now],
                );
            } catch (\RuntimeException) {
                // Race: another process inserted concurrently — re-read
                return null;
            }

            return $this->findByResource($resource);
        }

        if ($existing->isExpired($now) || $existing->owner === $owner) {
            // Expired or same owner — claim/re-acquire
            $this->executor->execute(
                'UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?',
                [$owner, $expiresAt, $now, $resource],
            );

            return $this->findByResource($resource);
        }

        // Held by another owner and not expired
        return null;
    }

    /**
     * Release a lock. Returns true if released, false if owner mismatch or not found.
     */
    public function release(string $resource, string $owner, string $now): ReleaseResult
    {
        $existing = $this->findByResource($resource);

        if ($existing === null) {
            return ReleaseResult::NotFound;
        }

        if ($existing->owner !== $owner) {
            return ReleaseResult::Forbidden;
        }

        $this->executor->execute(
            'DELETE FROM distributed_locks WHERE resource = ?',
            [$resource],
        );

        return ReleaseResult::Released;
    }

    /**
     * Renew the TTL of a lock. Only the current owner may renew.
     */
    public function renew(string $resource, string $owner, string $newExpiresAt, string $now): ?LockRecord
    {
        $existing = $this->findByResource($resource);

        if ($existing === null || $existing->isExpired($now)) {
            return null;
        }

        if ($existing->owner !== $owner) {
            return null;
        }

        $this->executor->execute(
            'UPDATE distributed_locks SET expires_at = ? WHERE resource = ?',
            [$newExpiresAt, $resource],
        );

        return $this->findByResource($resource);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): LockRecord
    {
        return new LockRecord(
            id:         (int) $row['id'],
            resource:   (string) $row['resource'],
            owner:      (string) $row['owner'],
            expiresAt:  (string) $row['expires_at'],
            acquiredAt: (string) $row['acquired_at'],
        );
    }
}
