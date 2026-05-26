<?php

declare(strict_types=1);

namespace Export\Export;

final readonly class User
{
    public function __construct(
        public int    $id,
        public string $email,
        public string $name,
        public string $phone,
        public string $passwordHash,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id'         => $this->id,
            'email'      => $this->email,
            'name'       => $this->name,
            'created_at' => $this->createdAt,
            // phone and password_hash intentionally excluded from public view
        ];
    }
}
