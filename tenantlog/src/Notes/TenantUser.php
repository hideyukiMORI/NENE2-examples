<?php

declare(strict_types=1);

namespace Tenant\Notes;

final readonly class TenantUser
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $email,
        public string $passwordHash,
        public string $createdAt,
    ) {
    }
}
