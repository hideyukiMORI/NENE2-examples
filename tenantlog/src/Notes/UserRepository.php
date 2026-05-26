<?php

declare(strict_types=1);

namespace Tenant\Notes;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class UserRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function findByEmail(string $email): ?TenantUser
    {
        /** @var array{id: int, tenant_id: int, email: string, password_hash: string, created_at: string}|null $row */
        $row = $this->executor->fetchOne(
            'SELECT id, tenant_id, email, password_hash, created_at FROM users WHERE email = ?',
            [$email],
        );

        if ($row === null) {
            return null;
        }

        return new TenantUser(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            email: $row['email'],
            passwordHash: $row['password_hash'],
            createdAt: $row['created_at'],
        );
    }

    public function create(int $tenantId, string $email, string $passwordHash): TenantUser
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $id  = $this->executor->insert(
            'INSERT INTO users (tenant_id, email, password_hash, created_at) VALUES (?, ?, ?, ?)',
            [$tenantId, $email, $passwordHash, $now],
        );

        return new TenantUser(id: $id, tenantId: $tenantId, email: $email, passwordHash: $passwordHash, createdAt: $now);
    }

    public function createTenant(string $name): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

        return $this->executor->insert(
            'INSERT INTO tenants (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );
    }
}
