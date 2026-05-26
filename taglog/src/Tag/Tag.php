<?php

declare(strict_types=1);

namespace Tag\Tag;

final readonly class Tag
{
    public function __construct(
        public int    $id,
        public string $name,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'created_at' => $this->createdAt,
        ];
    }
}
