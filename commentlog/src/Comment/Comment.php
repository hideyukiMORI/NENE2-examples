<?php

declare(strict_types=1);

namespace Comment\Comment;

final readonly class Comment
{
    public const int MAX_DEPTH = 3;

    /** @param Comment[] $children */
    public function __construct(
        public int     $id,
        public int     $postId,
        public ?int    $parentId,
        public string  $authorName,
        public string  $body,
        public string  $status,
        public int     $depth,
        public string  $createdAt,
        public array   $children = [],
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->status === 'deleted';
    }

    public function canHaveReplies(): bool
    {
        return $this->depth < self::MAX_DEPTH;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'post_id'     => $this->postId,
            'parent_id'   => $this->parentId,
            'author_name' => $this->authorName,
            'body'        => $this->body,
            'status'      => $this->status,
            'depth'       => $this->depth,
            'created_at'  => $this->createdAt,
            'children'    => array_map(static fn (Comment $c) => $c->toArray(), $this->children),
        ];
    }
}
