<?php

declare(strict_types=1);

namespace Lockout\Lockout;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class LockoutRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    public function createUser(string $email, string $password, string $now): User
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        $this->executor->execute(
            'INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)',
            [$email, $hash, $now],
        );

        $row = $this->executor->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);

        return $this->hydrateUser((array) $row);
    }

    public function findUserByEmail(string $email): ?User
    {
        $row = $this->executor->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);

        return $row !== null ? $this->hydrateUser($row) : null;
    }

    public function findOrCreateAccountState(string $email, string $now): AccountState
    {
        $row = $this->executor->fetchOne('SELECT * FROM account_states WHERE email = ?', [$email]);

        if ($row === null) {
            $this->executor->execute(
                'INSERT INTO account_states (email, failed_count, locked_until, updated_at) VALUES (?, 0, NULL, ?)',
                [$email, $now],
            );
            $row = $this->executor->fetchOne('SELECT * FROM account_states WHERE email = ?', [$email]);
        }

        return $this->hydrateAccountState((array) $row);
    }

    public function recordFailure(string $email, string $now): AccountState
    {
        $state = $this->findOrCreateAccountState($email, $now);

        $newCount    = $state->failedCount + 1;
        $lockedUntil = null;

        if ($newCount >= AccountState::MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', strtotime($now) + AccountState::LOCKOUT_MINUTES * 60);
        }

        $this->executor->execute(
            'UPDATE account_states SET failed_count = ?, locked_until = ?, updated_at = ? WHERE email = ?',
            [$newCount, $lockedUntil, $now, $email],
        );

        $row = $this->executor->fetchOne('SELECT * FROM account_states WHERE email = ?', [$email]);

        return $this->hydrateAccountState((array) $row);
    }

    public function resetState(string $email, string $now): void
    {
        $this->executor->execute(
            'UPDATE account_states SET failed_count = 0, locked_until = NULL, updated_at = ? WHERE email = ?',
            [$now, $email],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateUser(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            createdAt: (string) $row['created_at'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateAccountState(array $row): AccountState
    {
        return new AccountState(
            id: (int) $row['id'],
            email: (string) $row['email'],
            failedCount: (int) $row['failed_count'],
            lockedUntil: isset($row['locked_until']) ? (string) $row['locked_until'] : null,
            updatedAt: (string) $row['updated_at'],
        );
    }
}
