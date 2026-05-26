<?php

declare(strict_types=1);

namespace Hierarchylog\Category;

use RuntimeException;

final class CategoryNotFoundException extends RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Category #{$id} not found.");
    }
}
