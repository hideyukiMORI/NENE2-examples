<?php

declare(strict_types=1);

namespace Invitation\Invitation;

final readonly class User
{
    public function __construct(
        public int    $id,
        public string $email,
        public string $name,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'email'      => $this->email,
            'name'       => $this->name,
            'created_at' => $this->createdAt,
        ];
    }
}
