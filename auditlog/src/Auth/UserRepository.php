<?php

declare(strict_types=1);

namespace Audit\Auth;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class UserRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function create(string $email, string $passwordHash): User
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->executor->execute(
            'INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)',
            [$email, $passwordHash, $now],
        );

        return $this->findById((int) $this->executor->lastInsertId())
            ?? throw new \RuntimeException('Failed to create user.');
    }

    public function findByEmail(string $email): ?User
    {
        $rows = $this->executor->fetchAll('SELECT * FROM users WHERE email = ?', [$email]);

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    public function findById(int $id): ?User
    {
        $rows = $this->executor->fetchAll('SELECT * FROM users WHERE id = ?', [$id]);

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['password_hash'],
        );
    }
}
