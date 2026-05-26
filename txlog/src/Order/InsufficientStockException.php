<?php

declare(strict_types=1);

namespace Tx\Order;

final class InsufficientStockException extends \RuntimeException
{
    public function __construct(int $productId, int $requested, int $available)
    {
        parent::__construct(
            "Product {$productId}: requested {$requested}, available {$available}.",
        );
    }
}
