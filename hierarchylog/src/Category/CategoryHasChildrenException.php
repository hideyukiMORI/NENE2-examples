<?php

declare(strict_types=1);

namespace Hierarchylog\Category;

use RuntimeException;

final class CategoryHasChildrenException extends RuntimeException
{
    public function __construct(int $id, int $childCount)
    {
        parent::__construct("Category #{$id} has {$childCount} child(ren) and cannot be deleted.");
    }
}
