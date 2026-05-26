<?php

declare(strict_types=1);

namespace Rbac\Blog;

final readonly class Post
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public int $authorId,
        public string $createdAt,
    ) {
    }
}
