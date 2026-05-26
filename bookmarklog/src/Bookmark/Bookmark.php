<?php

declare(strict_types=1);

namespace Bookmark\Bookmark;

final readonly class Bookmark
{
    public function __construct(
        public int $id,
        public int $userId,
        public int $itemId,
        public string $collection,
        public string $createdAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'user_id'    => $this->userId,
            'item_id'    => $this->itemId,
            'collection' => $this->collection,
            'created_at' => $this->createdAt,
        ];
    }
}
