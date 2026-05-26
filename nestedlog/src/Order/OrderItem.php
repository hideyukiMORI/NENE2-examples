<?php

declare(strict_types=1);

namespace Nested\Order;

final readonly class OrderItem
{
    public function __construct(
        public int $id,
        public int $orderId,
        public int $productId,
        public int $quantity,
        public float $unitPrice,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int) $row['id'],
            orderId:   (int) $row['order_id'],
            productId: (int) $row['product_id'],
            quantity:  (int) $row['quantity'],
            unitPrice: (float) $row['unit_price'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'product_id' => $this->productId,
            'quantity'   => $this->quantity,
            'unit_price' => $this->unitPrice,
            'subtotal'   => $this->quantity * $this->unitPrice,
        ];
    }
}
