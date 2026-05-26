<?php

declare(strict_types=1);

namespace Page;

final readonly class Article
{
    public function __construct(
        public int $id,
        public string $title,
        public string $author,
        public string $category,
        public string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            title: (string) $row['title'],
            author: (string) $row['author'],
            category: (string) $row['category'],
            createdAt: (string) $row['created_at'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'author'     => $this->author,
            'category'   => $this->category,
            'created_at' => $this->createdAt,
        ];
    }
}
