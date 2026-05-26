<?php

declare(strict_types=1);

namespace Refresh\Auth;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class RefreshTokenRepository
{
    private const int TTL_DAYS = 7;

    public function __construct(private readonly DatabaseQueryExecutorInterface $executor) {}

    public function issue(int $userId): string
    {
        $raw       = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $raw);
        $now       = new \DateTimeImmutable();
        $expiresAt = $now->modify('+' . self::TTL_DAYS . ' days')->format('Y-m-d\TH:i:s\Z');
        $createdAt = $now->format('Y-m-d\TH:i:s\Z');

        $this->executor->insert(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, revoked, created_at) VALUES (?, ?, ?, 0, ?)',
            [$userId, $hash, $expiresAt, $createdAt],
        );

        // Return the raw token — the hash is stored, never the raw value
        return $raw;
    }

    public function findByRaw(string $raw): ?RefreshToken
    {
        $hash = hash('sha256', $raw);

        /** @var array{id: int, user_id: int, token_hash: string, expires_at: string, revoked: int, created_at: string}|null $row */
        $row = $this->executor->fetchOne(
            'SELECT id, user_id, token_hash, expires_at, revoked, created_at FROM refresh_tokens WHERE token_hash = ?',
            [$hash],
        );

        if ($row === null) {
            return null;
        }

        return new RefreshToken(
            id: $row['id'],
            userId: $row['user_id'],
            tokenHash: $row['token_hash'],
            expiresAt: $row['expires_at'],
            revoked: $row['revoked'] === 1,
            createdAt: $row['created_at'],
        );
    }

    public function revoke(int $id): void
    {
        $this->executor->execute(
            'UPDATE refresh_tokens SET revoked = 1 WHERE id = ?',
            [$id],
        );
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->executor->execute(
            'UPDATE refresh_tokens SET revoked = 1 WHERE user_id = ?',
            [$userId],
        );
    }
}
