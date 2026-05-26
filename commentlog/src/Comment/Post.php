<?php

declare(strict_types=1);

namespace Comment\Comment;

final readonly class Post
{
    public function __construct(
        public int    $id,
        public string $title,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'created_at' => $this->createdAt,
        ];
    }
}
