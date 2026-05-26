<?php

declare(strict_types=1);

namespace EtagLog\EtagLog;

final readonly class Article
{
    public function __construct(
        public int    $id,
        public string $title,
        public string $content,
        public string $updatedAt,
        public string $createdAt,
    ) {
    }

    public function etag(): string
    {
        return '"' . md5($this->id . '|' . $this->updatedAt . '|' . $this->title . $this->content) . '"';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'content'    => $this->content,
            'updated_at' => $this->updatedAt,
            'created_at' => $this->createdAt,
        ];
    }
}
