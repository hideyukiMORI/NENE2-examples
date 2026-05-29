<?php

declare(strict_types=1);

namespace CqrsLog\Order\Query;

final readonly class ListOrderSummariesQuery
{
    public function __construct(public ?string $status)
    {
    }
}
