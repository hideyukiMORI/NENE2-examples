<?php

declare(strict_types=1);

namespace CqrsLog\Order\Query;

final readonly class GetOrderSummaryQuery
{
    public function __construct(public int $orderId)
    {
    }
}
