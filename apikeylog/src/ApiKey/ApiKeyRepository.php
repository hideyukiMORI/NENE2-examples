<?php

declare(strict_types=1);

namespace ApiKey\ApiKey;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class ApiKeyRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
        private ApiKeyGenerator $generator,
    ) {
    }

    public function create(
        int         $ownerId,
        ApiKeyScope $scope,
        string      $description,
        string      $now,
        ?string     $expiresAt = null,
    ): ApiKeyCreateResult {
        $rawKey = $this->generator->generate();
        $prefix = $this->generator->extractPrefix($rawKey);
        $hash   = $this->generator->hash($rawKey);

        $this->executor->execute(
            'INSERT INTO api_keys (owner_id, prefix, key_hash, scope, description, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$ownerId, $prefix, $hash, $scope->value, $description, $expiresAt, $now, $now],
        );
        $id = (int) $this->executor->lastInsertId();

        $key = new ApiKey(
            $id,
            $ownerId,
            $prefix,
            $hash,
            $scope,
            $description,
            $expiresAt,
            null,
            $now,
            $now,
        );

        return new ApiKeyCreateResult($key, $rawKey);
    }

    /**
     * Look up a key by its prefix, then verify the hash.
     * Returns null if not found, revoked, expired, or hash mismatch.
     * Uses hash_equals() internally — safe against timing attacks.
     */
    public function authenticate(string $rawKey, string $now): ?ApiKey
    {
        $prefix = $this->generator->extractPrefix($rawKey);

        $rows = $this->executor->fetchAll(
            'SELECT * FROM api_keys WHERE prefix = ?',
            [$prefix],
        );

        foreach ($rows as $row) {
            $key = $this->hydrate($row);
            if ($this->generator->verify($rawKey, $key->keyHash) && $key->isActive($now)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Find all keys belonging to an owner (does not return key hashes in toArray).
     *
     * @return list<ApiKey>
     */
    public function findByOwner(int $ownerId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM api_keys WHERE owner_id = ? ORDER BY created_at DESC',
            [$ownerId],
        );

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function findById(int $id): ?ApiKey
    {
        $rows = $this->executor->fetchAll('SELECT * FROM api_keys WHERE id = ?', [$id]);
        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    public function revoke(int $id, int $ownerId, string $now): ?ApiKey
    {
        $key = $this->findById($id);
        if ($key === null || $key->ownerId !== $ownerId || $key->isRevoked()) {
            return null;
        }

        $this->executor->execute(
            'UPDATE api_keys SET revoked_at = ?, updated_at = ? WHERE id = ?',
            [$now, $now, $id],
        );

        return $this->findById($id);
    }

    /**
     * Rotate a key: create a new one first, then revoke the old one.
     * Creating first ensures the owner is never locked out: if the revoke step fails,
     * both keys are temporarily active (observable via list), which is recoverable.
     * The reverse order (revoke-then-create) risks permanent lockout on CREATE failure.
     */
    public function rotate(int $oldId, int $ownerId, string $now): ?ApiKeyCreateResult
    {
        $old = $this->findById($oldId);
        if ($old === null || $old->ownerId !== $ownerId || $old->isRevoked()) {
            return null;
        }

        $result = $this->create($ownerId, $old->scope, $old->description, $now, $old->expiresAt);

        $this->executor->execute(
            'UPDATE api_keys SET revoked_at = ?, updated_at = ? WHERE id = ?',
            [$now, $now, $oldId],
        );

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ApiKey
    {
        return new ApiKey(
            (int) $row['id'],
            (int) $row['owner_id'],
            (string) $row['prefix'],
            (string) $row['key_hash'],
            ApiKeyScope::from((string) $row['scope']),
            (string) $row['description'],
            isset($row['expires_at']) ? (string) $row['expires_at'] : null,
            isset($row['revoked_at']) ? (string) $row['revoked_at'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
