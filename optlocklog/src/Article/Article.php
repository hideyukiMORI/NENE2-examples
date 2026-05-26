<?php

declare(strict_types=1);

namespace Opt\Article;

final readonly class Article
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public int $version,
        public string $updatedAt,
    ) {
    }
}
