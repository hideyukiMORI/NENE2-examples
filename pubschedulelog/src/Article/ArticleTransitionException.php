<?php

declare(strict_types=1);

namespace Pubschedulelog\Article;

use RuntimeException;

final class ArticleTransitionException extends RuntimeException
{
    public function __construct(ArticleStatus $from, ArticleStatus $to)
    {
        parent::__construct("Cannot transition article from '{$from->value}' to '{$to->value}'.");
    }
}
