<?php

declare(strict_types=1);

namespace CqrsLog\Order\Command;

final readonly class UpdateOrderStatusCommand
{
    public function __construct(
        public int $orderId,
        public string $newStatus,
    ) {
    }
}
