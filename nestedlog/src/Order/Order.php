<?php

declare(strict_types=1);

namespace Nested\Order;

final readonly class Order
{
    /** @param list<OrderItem> $items */
    public function __construct(
        public int $id,
        public string $customer,
        public string $note,
        public string $status,
        public string $createdAt,
        public array $items = [],
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int) $row['id'],
            customer:  (string) $row['customer'],
            note:      (string) $row['note'],
            status:    (string) $row['status'],
            createdAt: (string) $row['created_at'],
        );
    }

    /** @param list<OrderItem> $items */
    public function withItems(array $items): self
    {
        return new self(
            id:        $this->id,
            customer:  $this->customer,
            note:      $this->note,
            status:    $this->status,
            createdAt: $this->createdAt,
            items:     $items,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $total = array_sum(array_map(
            static fn (OrderItem $i) => $i->quantity * $i->unitPrice,
            $this->items,
        ));

        return [
            'id'         => $this->id,
            'customer'   => $this->customer,
            'note'       => $this->note,
            'status'     => $this->status,
            'created_at' => $this->createdAt,
            'items'      => array_map(static fn (OrderItem $i) => $i->toArray(), $this->items),
            'total'      => round($total, 2),
        ];
    }
}
