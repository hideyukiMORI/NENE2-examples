<?php

declare(strict_types=1);

namespace Relatedlog\Article;

use RuntimeException;

final class ArticleNotFoundException extends RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Article #{$id} not found.");
    }
}
