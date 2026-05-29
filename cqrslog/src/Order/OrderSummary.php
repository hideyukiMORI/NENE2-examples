<?php

declare(strict_types=1);

namespace CqrsLog\Order;

/**
 * Read-model DTO — represents a row of the `order_summary` view. It is never
 * written to; keeping it separate from any write-side entity stops read concerns
 * leaking into the write model.
 */
final readonly class OrderSummary
{
    public function __construct(
        public int $id,
        public string $customer,
        public string $status,
        public string $createdAt,
        public int $itemCount,
        public int $totalCents,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->customer,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'item_count' => $this->itemCount,
            'total_cents' => $this->totalCents,
        ];
    }
}
