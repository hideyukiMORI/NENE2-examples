<?php

declare(strict_types=1);

namespace Pubschedulelog\Article;

final readonly class Article
{
    public function __construct(
        public int $id,
        public int $authorId,
        public string $title,
        public string $body,
        public ArticleStatus $status,
        public ?string $publishAt,
        public ?string $publishedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'author_id'    => $this->authorId,
            'title'        => $this->title,
            'body'         => $this->body,
            'status'       => $this->status->value,
            'publish_at'   => $this->publishAt,
            'published_at' => $this->publishedAt,
            'created_at'   => $this->createdAt,
            'updated_at'   => $this->updatedAt,
        ];
    }

    /** Returns a redacted view for non-owner access to non-published content. */
    public function isVisibleTo(int $requesterId): bool
    {
        return $this->status === ArticleStatus::Published || $this->authorId === $requesterId;
    }
}
