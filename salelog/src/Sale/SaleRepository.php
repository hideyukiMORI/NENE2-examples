<?php

declare(strict_types=1);

namespace Sale\Sale;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Database\DatabaseQueryExecutorInterface;

final class SaleRepository
{
    public function __construct(
        private readonly DatabaseQueryExecutorInterface $executor,
    ) {}

    public function createUser(string $name, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO users (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    public function findUserById(int $id): bool
    {
        return $this->executor->fetchOne('SELECT id FROM users WHERE id = ?', [$id]) !== null;
    }

    public function createProduct(string $name, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO products (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    public function createSale(int $productId, int $price, int $quantity, string $startsAt, string $endsAt, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO flash_sales (product_id, price, quantity, starts_at, ends_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$productId, $price, $quantity, $startsAt, $endsAt, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    /** @return array{id: int, product_id: int, price: int, quantity: int, starts_at: string, ends_at: string, created_at: string}|null */
    public function findSaleById(int $id): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, product_id, price, quantity, starts_at, ends_at, created_at FROM flash_sales WHERE id = ?',
            [$id],
        );

        return $row !== null ? $this->hydrateSale((array) $row) : null;
    }

    public function countPurchases(int $saleId): int
    {
        $row = $this->executor->fetchOne(
            'SELECT COUNT(*) as cnt FROM purchases WHERE sale_id = ?',
            [$saleId],
        );

        return isset($row['cnt']) ? (int) $row['cnt'] : 0;
    }

    public function findPurchase(int $saleId, int $userId): bool
    {
        return $this->executor->fetchOne(
            'SELECT id FROM purchases WHERE sale_id = ? AND user_id = ?',
            [$saleId, $userId],
        ) !== null;
    }

    /**
     * @return bool true=success, false=already purchased (duplicate)
     */
    public function purchase(int $saleId, int $userId, string $now): bool
    {
        try {
            $this->executor->execute(
                'INSERT INTO purchases (sale_id, user_id, purchased_at) VALUES (?, ?, ?)',
                [$saleId, $userId, $now],
            );

            return true;
        } catch (DatabaseConstraintException) {
            return false;
        }
    }

    /** @return array<int, array{id: int, sale_id: int, user_id: int, purchased_at: string}> */
    public function listPurchases(int $saleId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT id, sale_id, user_id, purchased_at FROM purchases WHERE sale_id = ? ORDER BY id ASC',
            [$saleId],
        );

        return array_map(fn(mixed $row) => $this->hydratePurchase((array) $row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, product_id: int, price: int, quantity: int, starts_at: string, ends_at: string, created_at: string}
     */
    private function hydrateSale(array $row): array
    {
        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : 0,
            'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : 0,
            'price'      => isset($row['price']) ? (int) $row['price'] : 0,
            'quantity'   => isset($row['quantity']) ? (int) $row['quantity'] : 0,
            'starts_at'  => isset($row['starts_at']) && is_string($row['starts_at']) ? $row['starts_at'] : '',
            'ends_at'    => isset($row['ends_at']) && is_string($row['ends_at']) ? $row['ends_at'] : '',
            'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, sale_id: int, user_id: int, purchased_at: string}
     */
    private function hydratePurchase(array $row): array
    {
        return [
            'id'           => isset($row['id']) ? (int) $row['id'] : 0,
            'sale_id'      => isset($row['sale_id']) ? (int) $row['sale_id'] : 0,
            'user_id'      => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'purchased_at' => isset($row['purchased_at']) && is_string($row['purchased_at']) ? $row['purchased_at'] : '',
        ];
    }
}
