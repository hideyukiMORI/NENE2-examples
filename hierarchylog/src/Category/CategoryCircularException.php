<?php

declare(strict_types=1);

namespace Hierarchylog\Category;

use RuntimeException;

final class CategoryCircularException extends RuntimeException
{
    public function __construct(string $reason = 'Circular reference detected.')
    {
        parent::__construct($reason);
    }
}
