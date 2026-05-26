<?php

declare(strict_types=1);

namespace Hierarchylog\Category;

final readonly class Category
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $parentId,
        public string $path,
        public int $depth,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'parent_id'  => $this->parentId,
            'path'       => $this->path,
            'depth'      => $this->depth,
            'created_at' => $this->createdAt,
        ];
    }
}
