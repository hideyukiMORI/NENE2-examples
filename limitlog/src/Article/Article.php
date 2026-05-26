<?php

declare(strict_types=1);

namespace Limitlog\Article;

final readonly class Article
{
    public function __construct(
        public int    $id,
        public int    $authorId,
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
            'author_id'  => $this->authorId,
            'title'      => $this->title,
            'body'       => $this->body,
            'created_at' => $this->createdAt,
        ];
    }
}
