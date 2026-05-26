<?php

declare(strict_types=1);

namespace Relatedlog\Article;

use RuntimeException;

final class RelationAlreadyExistsException extends RuntimeException
{
    public function __construct(int $articleId, int $relatedId, RelationType $type)
    {
        parent::__construct(
            "Relation '{$type->value}' between article #{$articleId} and #{$relatedId} already exists.",
        );
    }
}
