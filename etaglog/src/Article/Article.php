<?php

declare(strict_types=1);

namespace Etag\Article;

final readonly class Article
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public string $updatedAt,
    ) {
    }

    /** Returns a strong ETag derived from content hash. */
    public function etag(): string
    {
        return '"' . md5($this->title . $this->body . $this->updatedAt) . '"';
    }
}
