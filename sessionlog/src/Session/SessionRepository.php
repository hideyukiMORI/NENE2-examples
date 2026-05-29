<?php

declare(strict_types=1);

namespace SessionLog\Session;

use Nene2\Database\DatabaseQueryExecutorInterface;

class SessionRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, user_id, token, device_name, ip_address, revoked_at, created_at FROM sessions WHERE token = ?',
            [$token],
        );
    }

    /** @return list<array<string, mixed>> active sessions for a user */
    public function listActive(int $userId, int $limit): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT id, token, device_name, ip_address, created_at FROM sessions
             WHERE user_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT ?',
            [$userId, $limit],
        );
    }

    public function create(int $userId, string $token, string $deviceName, string $ipAddress, string $now): void
    {
        // token / created_at / revoked_at are server-managed — never from the body.
        $this->db->execute(
            'INSERT INTO sessions (user_id, token, device_name, ip_address, revoked_at, created_at) VALUES (?, ?, ?, ?, NULL, ?)',
            [$userId, $token, $deviceName, $ipAddress, $now],
        );
    }

    /**
     * IDOR-safe revoke: scoped by token AND user_id AND still-active. Returns
     * false for not-found / wrong-user / already-revoked alike (no oracle).
     */
    public function revokeForUser(string $token, int $userId, string $now): bool
    {
        return $this->db->execute(
            'UPDATE sessions SET revoked_at = ? WHERE token = ? AND user_id = ? AND revoked_at IS NULL',
            [$now, $token, $userId],
        ) > 0;
    }

    /** Revoke all of a user's active sessions except $exceptToken; returns count. */
    public function revokeAllExcept(int $userId, string $exceptToken, string $now): int
    {
        return $this->db->execute(
            'UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND token != ?',
            [$now, $userId, $exceptToken],
        );
    }
}
