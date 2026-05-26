<?php

declare(strict_types=1);

namespace TotpLog\Totp;

use Nene2\Database\DatabaseQueryExecutorInterface;

class TotpRepository
{
    private const int MAX_FAILED = 3;
    private const int LOCK_MINUTES = 15;

    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    public function createUser(string $name, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO users (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function findUser(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name, created_at FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findSecret(int $userId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, user_id, secret, is_enabled, failed_attempts, locked_until FROM totp_secrets WHERE user_id = ?',
            [$userId],
        );
    }

    public function upsertSecret(int $userId, string $secret, string $now): void
    {
        $existing = $this->findSecret($userId);
        if ($existing !== null) {
            $this->db->insert(
                'UPDATE totp_secrets SET secret = ?, is_enabled = 0, failed_attempts = 0, locked_until = NULL WHERE user_id = ?',
                [$secret, $userId],
            );
            // Also clear used steps for this user (old secret invalidated)
            $this->db->insert('DELETE FROM used_totp_steps WHERE user_id = ?', [$userId]);
        } else {
            $this->db->insert(
                'INSERT INTO totp_secrets (user_id, secret, is_enabled, failed_attempts, created_at) VALUES (?, ?, 0, 0, ?)',
                [$userId, $secret, $now],
            );
        }
    }

    public function enable(int $userId): void
    {
        $this->db->insert(
            'UPDATE totp_secrets SET is_enabled = 1, failed_attempts = 0, locked_until = NULL WHERE user_id = ?',
            [$userId],
        );
    }

    public function disable(int $userId): void
    {
        $this->db->insert('DELETE FROM totp_secrets WHERE user_id = ?', [$userId]);
        $this->db->insert('DELETE FROM used_totp_steps WHERE user_id = ?', [$userId]);
    }

    public function recordFailure(int $userId, string $now): void
    {
        $row = $this->findSecret($userId);
        if ($row === null) {
            return;
        }
        $attempts = (int) $row['failed_attempts'] + 1;
        if ($attempts >= self::MAX_FAILED) {
            $lockedUntil = date('Y-m-d\TH:i:s\Z', strtotime($now) + self::LOCK_MINUTES * 60);
            $this->db->insert(
                'UPDATE totp_secrets SET failed_attempts = ?, locked_until = ? WHERE user_id = ?',
                [$attempts, $lockedUntil, $userId],
            );
        } else {
            $this->db->insert(
                'UPDATE totp_secrets SET failed_attempts = ? WHERE user_id = ?',
                [$attempts, $userId],
            );
        }
    }

    public function resetFailures(int $userId): void
    {
        $this->db->insert(
            'UPDATE totp_secrets SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?',
            [$userId],
        );
    }

    public function isLocked(int $userId, string $now): bool
    {
        $row = $this->findSecret($userId);
        if ($row === null || $row['locked_until'] === null) {
            return false;
        }
        return (string) $row['locked_until'] > $now;
    }

    public function isStepUsed(int $userId, int $timeStep): bool
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM used_totp_steps WHERE user_id = ? AND time_step = ?',
            [$userId, $timeStep],
        );
        return $row !== null;
    }

    public function markStepUsed(int $userId, int $timeStep, string $now): void
    {
        try {
            $this->db->insert(
                'INSERT INTO used_totp_steps (user_id, time_step, used_at) VALUES (?, ?, ?)',
                [$userId, $timeStep, $now],
            );
        } catch (\Throwable) {
            // UNIQUE constraint: already marked, that's fine
        }
    }
}
