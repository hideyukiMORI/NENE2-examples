<?php

declare(strict_types=1);

namespace OAuthLog\OAuth;

use Nene2\Database\DatabaseQueryExecutorInterface;

class OAuthRepository
{
    private const int STATE_TTL_SECONDS  = 300;  // 5 minutes
    private const int SESSION_TTL_SECONDS = 3600; // 1 hour

    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    // -------------------------------------------------------------------------
    // States

    public function createState(string $state, string $now): void
    {
        $expiresAt = $this->addSeconds($now, self::STATE_TTL_SECONDS);
        $this->db->insert(
            'INSERT INTO oauth_states (state, created_at, expires_at) VALUES (?, ?, ?)',
            [$state, $now, $expiresAt],
        );
    }

    /** @return array<string, mixed>|null */
    public function findState(string $state): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, state, expires_at, used_at FROM oauth_states WHERE state = ?',
            [$state],
        );
    }

    public function markStateUsed(string $state, string $now): void
    {
        $this->db->insert(
            'UPDATE oauth_states SET used_at = ? WHERE state = ?',
            [$now, $state],
        );
    }

    public function isStateValid(string $state, string $now): bool
    {
        $row = $this->findState($state);
        if ($row === null) {
            return false;
        }
        if ($row['used_at'] !== null) {
            return false; // already used
        }
        return (string) $row['expires_at'] > $now;
    }

    // -------------------------------------------------------------------------
    // Code replay prevention

    public function isCodeUsed(string $code): bool
    {
        $row = $this->db->fetchOne('SELECT id FROM used_oauth_codes WHERE code = ?', [$code]);
        return $row !== null;
    }

    public function markCodeUsed(string $code, string $now): void
    {
        try {
            $this->db->insert(
                'INSERT INTO used_oauth_codes (code, used_at) VALUES (?, ?)',
                [$code, $now],
            );
        } catch (\Throwable) {
            // UNIQUE constraint: already marked
        }
    }

    // -------------------------------------------------------------------------
    // Users

    /**
     * @param array{provider: string, subject: string, name: string, email: string} $info
     */
    public function upsertUser(array $info, string $now): int
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM users WHERE provider = ? AND subject = ?',
            [$info['provider'], $info['subject']],
        );
        if ($row !== null) {
            $this->db->insert(
                'UPDATE users SET name = ?, email = ? WHERE id = ?',
                [$info['name'], $info['email'], $row['id']],
            );
            return (int) $row['id'];
        }
        return $this->db->insert(
            'INSERT INTO users (provider, subject, name, email, created_at) VALUES (?, ?, ?, ?, ?)',
            [$info['provider'], $info['subject'], $info['name'], $info['email'], $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function findUser(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, provider, subject, name, email, created_at FROM users WHERE id = ?',
            [$id],
        );
    }

    // -------------------------------------------------------------------------
    // Sessions

    public function createSession(int $userId, string $token, string $now): void
    {
        $expiresAt = $this->addSeconds($now, self::SESSION_TTL_SECONDS);
        $this->db->insert(
            'INSERT INTO sessions (user_id, token, created_at, expires_at) VALUES (?, ?, ?, ?)',
            [$userId, $token, $now, $expiresAt],
        );
    }

    /** @return array<string, mixed>|null */
    public function findSession(string $token, string $now): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT id, user_id, expires_at, revoked_at FROM sessions WHERE token = ? AND expires_at > ? AND revoked_at IS NULL',
            [$token, $now],
        );
    }

    public function revokeSession(string $token, string $now): void
    {
        $this->db->insert(
            'UPDATE sessions SET revoked_at = ? WHERE token = ?',
            [$now, $token],
        );
    }

    // -------------------------------------------------------------------------

    private function addSeconds(string $iso, int $seconds): string
    {
        return (new \DateTimeImmutable($iso))->modify("+{$seconds} seconds")->format('Y-m-d\TH:i:s\Z');
    }
}
