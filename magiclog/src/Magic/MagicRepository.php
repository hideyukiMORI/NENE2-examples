<?php

declare(strict_types=1);

namespace MagicLog\Magic;

use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\DatabaseConstraintException;

final class MagicRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function findUserByEmail(string $email): ?array
    {
        return $this->executor->fetchOne('SELECT id, email FROM users WHERE email = ?', [$email]) ?: null;
    }

    public function findOrCreateUser(string $email, string $now): int
    {
        $user = $this->findUserByEmail($email);
        if ($user !== null) {
            return (int) $user['id'];
        }
        $this->executor->execute('INSERT INTO users (email, created_at) VALUES (?, ?)', [$email, $now]);
        return (int) $this->executor->lastInsertId();
    }

    public function createMagicLink(int $userId, string $tokenHash, string $expiresAt, string $now): void
    {
        $this->executor->execute(
            'INSERT INTO magic_links (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $tokenHash, $expiresAt, $now]
        );
    }

    public function findMagicLinkByTokenHash(string $tokenHash): ?array
    {
        return $this->executor->fetchOne(
            'SELECT id, user_id, expires_at, used_at FROM magic_links WHERE token_hash = ?',
            [$tokenHash]
        ) ?: null;
    }

    public function markMagicLinkUsed(int $linkId, string $now): void
    {
        $this->executor->execute('UPDATE magic_links SET used_at = ? WHERE id = ?', [$now, $linkId]);
    }

    public function createSession(int $userId, string $sessionTokenHash, string $expiresAt, string $now): void
    {
        $this->executor->execute(
            'INSERT INTO auth_sessions (user_id, session_token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $sessionTokenHash, $expiresAt, $now]
        );
    }

    public function findSessionByTokenHash(string $tokenHash): ?array
    {
        return $this->executor->fetchOne(
            'SELECT id, user_id, expires_at, revoked_at FROM auth_sessions WHERE session_token_hash = ?',
            [$tokenHash]
        ) ?: null;
    }

    public function revokeSession(int $sessionId, string $now): void
    {
        $this->executor->execute('UPDATE auth_sessions SET revoked_at = ? WHERE id = ?', [$now, $sessionId]);
    }

    public function findUserById(int $userId): ?array
    {
        return $this->executor->fetchOne('SELECT id, email, created_at FROM users WHERE id = ?', [$userId]) ?: null;
    }
}
