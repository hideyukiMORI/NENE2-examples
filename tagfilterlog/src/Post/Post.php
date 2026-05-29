<?php

declare(strict_types=1);

namespace TagFilterLog\Post;

final readonly class Post
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public array $tags,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'tags' => $this->tags,
            'created_at' => $this->createdAt,
        ];
    }
}
