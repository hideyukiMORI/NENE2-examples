<?php

declare(strict_types=1);

namespace Vote\Vote;

final readonly class ItemScore
{
    public function __construct(
        public int $itemId,
        public int $upvotes,
        public int $downvotes,
    ) {}

    public function score(): int
    {
        return $this->upvotes - $this->downvotes;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'item_id'   => $this->itemId,
            'upvotes'   => $this->upvotes,
            'downvotes' => $this->downvotes,
            'score'     => $this->score(),
        ];
    }
}
