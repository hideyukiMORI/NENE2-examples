<?php

declare(strict_types=1);

namespace Nested\Order;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class SqliteOrderRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    /**
     * @param list<array{product_id: int, quantity: int, unit_price: float}> $items
     */
    public function create(string $customer, string $note, array $items): Order
    {
        $this->executor->insert(
            'INSERT INTO orders (customer, note, status, created_at) VALUES (?, ?, ?, ?)',
            [$customer, $note, 'pending', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
        );
        $orderId = $this->executor->lastInsertId();

        $orderItems = [];
        foreach ($items as $item) {
            $this->executor->insert(
                'INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)',
                [$orderId, $item['product_id'], $item['quantity'], $item['unit_price']],
            );
            $itemId = $this->executor->lastInsertId();
            $orderItems[] = new OrderItem(
                id:        $itemId,
                orderId:   $orderId,
                productId: $item['product_id'],
                quantity:  $item['quantity'],
                unitPrice: $item['unit_price'],
            );
        }

        $row = $this->executor->fetchOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        assert($row !== null);

        return Order::fromRow($row)->withItems($orderItems);
    }

    public function findById(int $id): ?Order
    {
        $row = $this->executor->fetchOne('SELECT * FROM orders WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $order = Order::fromRow($row);
        return $order->withItems($this->findItems($id));
    }

    /** @return list<Order> */
    public function listAll(): array
    {
        $rows = $this->executor->fetchAll('SELECT * FROM orders ORDER BY id DESC', []);
        return array_map(fn (array $r) => Order::fromRow($r)->withItems($this->findItems((int) $r['id'])), $rows);
    }

    /** @return list<OrderItem> */
    private function findItems(int $orderId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM order_items WHERE order_id = ? ORDER BY id',
            [$orderId],
        );
        return array_map(OrderItem::fromRow(...), $rows);
    }
}
