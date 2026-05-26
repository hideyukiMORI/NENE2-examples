<?php

declare(strict_types=1);

namespace Relatedlog\Article;

final readonly class ArticleRelation
{
    public function __construct(
        public int $id,
        public int $articleId,
        public int $relatedId,
        public RelationType $relationType,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'article_id'    => $this->articleId,
            'related_id'    => $this->relatedId,
            'relation_type' => $this->relationType->value,
            'created_at'    => $this->createdAt,
        ];
    }
}
