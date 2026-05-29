<?php

declare(strict_types=1);

namespace OneTimeLog\Secret;

use Nene2\Database\DatabaseQueryExecutorInterface;

class SecretRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, user_id, token, message, password_hash, expires_at, consumed, created_at FROM secrets WHERE token = ?',
            [$token],
        );
    }

    /** @return list<array<string, mixed>> own metadata (message intentionally omitted) */
    public function listOwned(int $userId, int $limit): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, token, expires_at, consumed, created_at FROM secrets WHERE user_id = ? ORDER BY id DESC LIMIT ?',
            [$userId, $limit],
        );
    }

    public function create(int $userId, string $token, string $message, ?string $passwordHash, ?string $expiresAt, string $now): void
    {
        $this->db->execute(
            'INSERT INTO secrets (user_id, token, message, password_hash, expires_at, consumed, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?)',
            [$userId, $token, $message, $passwordHash, $expiresAt, $now],
        );
    }

    /**
     * Atomically mark a secret consumed. Returns true only for the single
     * caller that flips consumed 0→1 (the race winner); concurrent readers and
     * already-consumed tokens get false.
     */
    public function markConsumed(string $token): bool
    {
        return $this->db->execute('UPDATE secrets SET consumed = 1 WHERE token = ? AND consumed = 0', [$token]) === 1;
    }

    public function deleteOwnedUnconsumed(string $token, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM secrets WHERE token = ? AND user_id = ? AND consumed = 0',
            [$token, $userId],
        ) > 0;
    }
}
