<?php

declare(strict_types=1);

namespace CqrsLog\Order\Command;

final readonly class PlaceOrderCommand
{
    /**
     * @param list<array{product: string, quantity: int, unit_price: int}> $items
     */
    public function __construct(
        public string $customer,
        public array $items,
    ) {
    }
}
