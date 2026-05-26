<?php

declare(strict_types=1);

namespace Relatedlog\Article;

final readonly class Article
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public string $createdAt,
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
        ];
    }
}
