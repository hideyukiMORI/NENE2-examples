<?php

declare(strict_types=1);

namespace Reset\Reset;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class ResetRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function createUser(string $email, string $name, string $passwordHash, string $now): ?User
    {
        try {
            $this->executor->execute(
                'INSERT INTO users (email, name, password_hash, created_at) VALUES (?, ?, ?, ?)',
                [$email, $name, $passwordHash, $now],
            );
        } catch (\RuntimeException) {
            return null;
        }

        return $this->findUserByEmail($email);
    }

    public function findUserByEmail(string $email): ?User
    {
        $row = $this->executor->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);

        return $row !== null ? $this->hydrateUser($row) : null;
    }

    public function findUserById(int $id): ?User
    {
        $row = $this->executor->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrateUser($row) : null;
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $this->executor->execute(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [$passwordHash, $userId],
        );
    }

    /**
     * Create a reset token. Invalidates all previous unused tokens for this user first.
     */
    public function createReset(int $userId, string $tokenHash, string $expiresAt, string $now): PasswordReset
    {
        // Invalidate any prior unused tokens for this user
        $this->executor->execute(
            "UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
            [$now, $userId],
        );

        $this->executor->execute(
            'INSERT INTO password_resets (user_id, token_hash, used_at, expires_at, created_at) VALUES (?, ?, NULL, ?, ?)',
            [$userId, $tokenHash, $expiresAt, $now],
        );

        return $this->findByTokenHash($tokenHash);
    }

    public function findByTokenHash(string $tokenHash): PasswordReset
    {
        $row = $this->executor->fetchOne(
            'SELECT * FROM password_resets WHERE token_hash = ?',
            [$tokenHash],
        );

        if ($row === null) {
            throw new \RuntimeException('Password reset not found');
        }

        return $this->hydrateReset($row);
    }

    public function findByTokenHashOrNull(string $tokenHash): ?PasswordReset
    {
        $row = $this->executor->fetchOne(
            'SELECT * FROM password_resets WHERE token_hash = ?',
            [$tokenHash],
        );

        return $row !== null ? $this->hydrateReset($row) : null;
    }

    public function markUsed(string $tokenHash, string $now): void
    {
        $this->executor->execute(
            'UPDATE password_resets SET used_at = ? WHERE token_hash = ?',
            [$now, $tokenHash],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateUser(array $row): User
    {
        return new User(
            id:           (int) $row['id'],
            email:        (string) $row['email'],
            name:         (string) $row['name'],
            passwordHash: (string) $row['password_hash'],
            createdAt:    (string) $row['created_at'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateReset(array $row): PasswordReset
    {
        return new PasswordReset(
            id:        (int) $row['id'],
            userId:    (int) $row['user_id'],
            tokenHash: (string) $row['token_hash'],
            usedAt:    isset($row['used_at']) ? (string) $row['used_at'] : null,
            expiresAt: (string) $row['expires_at'],
            createdAt: (string) $row['created_at'],
        );
    }
}
