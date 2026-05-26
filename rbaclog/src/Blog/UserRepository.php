<?php

declare(strict_types=1);

namespace Rbac\Blog;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class UserRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function findByEmail(string $email): ?User
    {
        /** @var array{id: int, email: string, password_hash: string, role: string, created_at: string}|null $row */
        $row = $this->executor->fetchOne(
            'SELECT id, email, password_hash, role, created_at FROM users WHERE email = ?',
            [$email],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function create(string $email, string $passwordHash, Role $role = Role::User): User
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $id  = $this->executor->insert(
            'INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, ?, ?)',
            [$email, $passwordHash, $role->value, $now],
        );

        return new User(id: $id, email: $email, passwordHash: $passwordHash, role: $role, createdAt: $now);
    }

    /** @param array{id: int, email: string, password_hash: string, role: string, created_at: string} $row */
    private function hydrate(array $row): User
    {
        return new User(
            id: $row['id'],
            email: $row['email'],
            passwordHash: $row['password_hash'],
            role: Role::from($row['role']),
            createdAt: $row['created_at'],
        );
    }
}
