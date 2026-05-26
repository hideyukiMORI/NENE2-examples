<?php

declare(strict_types=1);

namespace Order\Order;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class OrderRepository
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

    public function createProduct(string $name, int $price, int $stock, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO products (name, price, stock, created_at) VALUES (?, ?, ?, ?)',
            [$name, $price, $stock, $now],
        );

        return (int) $this->executor->lastInsertId();
    }

    /** @return array{id: int, name: string, price: int, stock: int, created_at: string}|null */
    public function findProductById(int $id): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, name, price, stock, created_at FROM products WHERE id = ?',
            [$id],
        );

        return $row !== null ? $this->hydrateProduct((array) $row) : null;
    }

    public function findUserById(int $id): bool
    {
        return $this->executor->fetchOne('SELECT id FROM users WHERE id = ?', [$id]) !== null;
    }

    /** @return array{id: int, user_id: int, product_id: int, quantity: int, added_at: string}|null */
    public function findCartItem(int $userId, int $productId): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, user_id, product_id, quantity, added_at FROM cart_items WHERE user_id = ? AND product_id = ?',
            [$userId, $productId],
        );

        return $row !== null ? $this->hydrateCartItem((array) $row) : null;
    }

    public function addToCart(int $userId, int $productId, int $quantity, string $now): void
    {
        $existing = $this->findCartItem($userId, $productId);

        if ($existing !== null) {
            $this->executor->execute(
                'UPDATE cart_items SET quantity = quantity + ? WHERE user_id = ? AND product_id = ?',
                [$quantity, $userId, $productId],
            );

            return;
        }

        $this->executor->execute(
            'INSERT INTO cart_items (user_id, product_id, quantity, added_at) VALUES (?, ?, ?, ?)',
            [$userId, $productId, $quantity, $now],
        );
    }

    public function removeFromCart(int $userId, int $productId): bool
    {
        $count = $this->executor->execute(
            'DELETE FROM cart_items WHERE user_id = ? AND product_id = ?',
            [$userId, $productId],
        );

        return $count > 0;
    }

    /** @return array<int, array{id: int, product_id: int, name: string, price: int, quantity: int}> */
    public function listCartItems(int $userId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT ci.id, ci.product_id, p.name, p.price, ci.quantity
             FROM cart_items ci
             INNER JOIN products p ON p.id = ci.product_id
             WHERE ci.user_id = ?
             ORDER BY ci.id ASC',
            [$userId],
        );

        return array_map(fn(mixed $row) => $this->hydrateCartEntry((array) $row), $rows);
    }

    public function clearCart(int $userId): void
    {
        $this->executor->execute('DELETE FROM cart_items WHERE user_id = ?', [$userId]);
    }

    /** @param array<int, array{product_id: int, name: string, price: int, quantity: int}> $items */
    public function createOrder(int $userId, array $items, int $total, string $now): int
    {
        $this->executor->execute(
            'INSERT INTO orders (user_id, total, created_at) VALUES (?, ?, ?)',
            [$userId, $total, $now],
        );

        $orderId = (int) $this->executor->lastInsertId();

        foreach ($items as $item) {
            $this->executor->execute(
                'INSERT INTO order_items (order_id, product_id, name, price, quantity) VALUES (?, ?, ?, ?, ?)',
                [$orderId, $item['product_id'], $item['name'], $item['price'], $item['quantity']],
            );
        }

        return $orderId;
    }

    /** @return array{id: int, user_id: int, total: int, created_at: string}|null */
    public function findOrderById(int $id): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, user_id, total, created_at FROM orders WHERE id = ?',
            [$id],
        );

        return $row !== null ? $this->hydrateOrder((array) $row) : null;
    }

    /** @return array<int, array{id: int, product_id: int, name: string, price: int, quantity: int}> */
    public function listOrderItems(int $orderId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT id, product_id, name, price, quantity FROM order_items WHERE order_id = ? ORDER BY id ASC',
            [$orderId],
        );

        return array_map(fn(mixed $row) => $this->hydrateOrderItem((array) $row), $rows);
    }

    public function decrementStock(int $productId, int $quantity): void
    {
        $this->executor->execute(
            'UPDATE products SET stock = stock - ? WHERE id = ?',
            [$quantity, $productId],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, price: int, stock: int, created_at: string}
     */
    private function hydrateProduct(array $row): array
    {
        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : 0,
            'name'       => isset($row['name']) && is_string($row['name']) ? $row['name'] : '',
            'price'      => isset($row['price']) ? (int) $row['price'] : 0,
            'stock'      => isset($row['stock']) ? (int) $row['stock'] : 0,
            'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, user_id: int, product_id: int, quantity: int, added_at: string}
     */
    private function hydrateCartItem(array $row): array
    {
        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : 0,
            'user_id'    => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : 0,
            'quantity'   => isset($row['quantity']) ? (int) $row['quantity'] : 1,
            'added_at'   => isset($row['added_at']) && is_string($row['added_at']) ? $row['added_at'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, product_id: int, name: string, price: int, quantity: int}
     */
    private function hydrateCartEntry(array $row): array
    {
        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : 0,
            'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : 0,
            'name'       => isset($row['name']) && is_string($row['name']) ? $row['name'] : '',
            'price'      => isset($row['price']) ? (int) $row['price'] : 0,
            'quantity'   => isset($row['quantity']) ? (int) $row['quantity'] : 1,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, user_id: int, total: int, created_at: string}
     */
    private function hydrateOrder(array $row): array
    {
        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : 0,
            'user_id'    => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'total'      => isset($row['total']) ? (int) $row['total'] : 0,
            'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, product_id: int, name: string, price: int, quantity: int}
     */
    private function hydrateOrderItem(array $row): array
    {
        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : 0,
            'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : 0,
            'name'       => isset($row['name']) && is_string($row['name']) ? $row['name'] : '',
            'price'      => isset($row['price']) ? (int) $row['price'] : 0,
            'quantity'   => isset($row['quantity']) ? (int) $row['quantity'] : 1,
        ];
    }
}
