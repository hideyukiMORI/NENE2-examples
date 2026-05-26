<?php

declare(strict_types=1);

namespace Pubschedulelog\Article;

use RuntimeException;

final class ArticleScheduleException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
