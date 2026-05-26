<?php

declare(strict_types=1);

namespace Throttle\Notes;

final readonly class Note
{
    public function __construct(
        public int $id,
        public string $content,
        public string $createdAt,
    ) {}
}
