<?php

declare(strict_types=1);

namespace Version\Shared;

final readonly class Note
{
    /** @param list<string> $tags */
    public function __construct(
        public int    $id,
        public string $title,
        public string $body,
        public array  $tags,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
