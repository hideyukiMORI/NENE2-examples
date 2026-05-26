<?php

declare(strict_types=1);

namespace Csrf\Order;

final readonly class Order
{
    public function __construct(
        public int $id,
        public string $idempotencyKey,
        public string $item,
        public int $quantity,
        public float $totalPrice,
        public string $status,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'idempotency_key'  => $this->idempotencyKey,
            'item'             => $this->item,
            'quantity'         => $this->quantity,
            'total_price'      => $this->totalPrice,
            'status'           => $this->status,
            'created_at'       => $this->createdAt,
        ];
    }
}
