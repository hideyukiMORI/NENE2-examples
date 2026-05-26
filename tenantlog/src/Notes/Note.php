<?php

declare(strict_types=1);

namespace Tenant\Notes;

final readonly class Note
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $title,
        public string $body,
        public string $createdAt,
    ) {
    }
}
