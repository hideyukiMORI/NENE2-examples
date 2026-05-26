<?php

declare(strict_types=1);

namespace Tag\Tag;

final readonly class Post
{
    /** @param Tag[] $tags */
    public function __construct(
        public int    $id,
        public string $title,
        public string $body,
        public string $createdAt,
        public array  $tags = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'body'       => $this->body,
            'created_at' => $this->createdAt,
            'tags'       => array_map(static fn (Tag $t) => $t->toArray(), $this->tags),
        ];
    }
}
