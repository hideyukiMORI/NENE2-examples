<?php

declare(strict_types=1);

namespace Hierarchylog\Category;

use RuntimeException;

final class CategoryDepthException extends RuntimeException
{
    public function __construct(int $attempted, int $max)
    {
        parent::__construct("Depth {$attempted} exceeds maximum allowed depth {$max}.");
    }
}
