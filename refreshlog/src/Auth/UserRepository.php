<?php

declare(strict_types=1);

namespace Refresh\Auth;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class UserRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor) {}

    public function findByEmail(string $email): ?User
    {
        /** @var array{id: int, email: string, password_hash: string, created_at: string}|null $row */
        $row = $this->executor->fetchOne(
            'SELECT id, email, password_hash, created_at FROM users WHERE email = ?',
            [$email],
        );

        if ($row === null) {
            return null;
        }

        return new User(
            id: $row['id'],
            email: $row['email'],
            passwordHash: $row['password_hash'],
            createdAt: $row['created_at'],
        );
    }

    public function findById(int $id): ?User
    {
        /** @var array{id: int, email: string, password_hash: string, created_at: string}|null $row */
        $row = $this->executor->fetchOne(
            'SELECT id, email, password_hash, created_at FROM users WHERE id = ?',
            [$id],
        );

        if ($row === null) {
            return null;
        }

        return new User(
            id: $row['id'],
            email: $row['email'],
            passwordHash: $row['password_hash'],
            createdAt: $row['created_at'],
        );
    }

    public function create(string $email, string $passwordHash): User
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $id  = $this->executor->insert(
            'INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)',
            [$email, $passwordHash, $now],
        );

        return new User(id: $id, email: $email, passwordHash: $passwordHash, createdAt: $now);
    }
}
