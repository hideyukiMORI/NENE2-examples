<?php

declare(strict_types=1);

namespace Mass\User;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class UserRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function create(CreateUserInput $input): User
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $id = $this->executor->insert(
            'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
            [$input->name, $input->email, 'user', 1, $now],
        );

        return new User(
            id:        $id,
            name:      $input->name,
            email:     $input->email,
            role:      'user',
            isActive:  true,
            createdAt: $now,
        );
    }

    public function findById(int $id): ?User
    {
        /** @var array{id: int, name: string, email: string, role: string, is_active: int, created_at: string}|null $row */
        $row = $this->executor->fetchOne(
            'SELECT id, name, email, role, is_active, created_at FROM users WHERE id = ?',
            [$id],
        );

        if ($row === null) {
            return null;
        }

        return new User(
            id:        $row['id'],
            name:      $row['name'],
            email:     $row['email'],
            role:      $row['role'],
            isActive:  (bool) $row['is_active'],
            createdAt: $row['created_at'],
        );
    }

    /** @return list<User> */
    public function findAll(): array
    {
        /** @var list<array{id: int, name: string, email: string, role: string, is_active: int, created_at: string}> $rows */
        $rows = $this->executor->fetchAll(
            'SELECT id, name, email, role, is_active, created_at FROM users ORDER BY id',
        );

        return array_map(
            fn (array $row) => new User(
                id:        $row['id'],
                name:      $row['name'],
                email:     $row['email'],
                role:      $row['role'],
                isActive:  (bool) $row['is_active'],
                createdAt: $row['created_at'],
            ),
            $rows,
        );
    }

    public function seedAdmin(string $name, string $email): User
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $id = $this->executor->insert(
            'INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?)',
            [$name, $email, 'admin', 1, $now],
        );

        return new User(id: $id, name: $name, email: $email, role: 'admin', isActive: true, createdAt: $now);
    }
}
