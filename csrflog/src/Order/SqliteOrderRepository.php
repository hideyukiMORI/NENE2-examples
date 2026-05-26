<?php

declare(strict_types=1);

namespace Csrf\Order;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class SqliteOrderRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $db,
    ) {
    }

    public function findByIdempotencyKey(string $key): ?Order
    {
        $row = $this->db->fetchOne(
            'SELECT id, idempotency_key, item, quantity, total_price, status, created_at FROM orders WHERE idempotency_key = ?',
            [$key],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function create(string $idempotencyKey, string $item, int $quantity, float $totalPrice): Order
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $id = $this->db->insert(
            'INSERT INTO orders (idempotency_key, item, quantity, total_price, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$idempotencyKey, $item, $quantity, $totalPrice, 'pending', $now],
        );

        return new Order($id, $idempotencyKey, $item, $quantity, $totalPrice, 'pending', $now);
    }

    public function findById(int $id): ?Order
    {
        $row = $this->db->fetchOne(
            'SELECT id, idempotency_key, item, quantity, total_price, status, created_at FROM orders WHERE id = ?',
            [$id],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<Order> */
    public function listAll(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, idempotency_key, item, quantity, total_price, status, created_at FROM orders ORDER BY id',
        );

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Order
    {
        return new Order(
            id:              (int) $row['id'],
            idempotencyKey:  (string) $row['idempotency_key'],
            item:            (string) $row['item'],
            quantity:        (int) $row['quantity'],
            totalPrice:      (float) $row['total_price'],
            status:          (string) $row['status'],
            createdAt:       (string) $row['created_at'],
        );
    }
}
