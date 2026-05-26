<?php

declare(strict_types=1);

namespace CartLog\Cart;

use Nene2\Database\DatabaseQueryExecutorInterface;

class CartRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findUserById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findProductById(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT id, name, price, stock FROM products WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findCartItem(int $userId, int $productId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT ci.id, ci.user_id, ci.product_id, ci.quantity, ci.added_at, ci.updated_at,
                    p.name AS product_name, p.price
             FROM cart_items ci
             JOIN products p ON p.id = ci.product_id
             WHERE ci.user_id = ? AND ci.product_id = ?',
            [$userId, $productId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function getCart(int $userId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT ci.id, ci.product_id, ci.quantity, ci.added_at, ci.updated_at,
                    p.name AS product_name, p.price
             FROM cart_items ci
             JOIN products p ON p.id = ci.product_id
             WHERE ci.user_id = ?
             ORDER BY ci.added_at ASC, ci.id ASC',
            [$userId]
        );
    }

    public function addItem(int $userId, int $productId, int $quantity, string $now): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?',
            [$userId, $productId]
        );

        if ($existing !== null) {
            $newQty = (int) $existing['quantity'] + $quantity;
            $this->db->execute(
                'UPDATE cart_items SET quantity = ?, updated_at = ? WHERE id = ?',
                [$newQty, $now, $existing['id']]
            );
        } else {
            $this->db->execute(
                'INSERT INTO cart_items (user_id, product_id, quantity, added_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)',
                [$userId, $productId, $quantity, $now, $now]
            );
        }
    }

    public function updateQuantity(int $userId, int $productId, int $quantity, string $now): void
    {
        $this->db->execute(
            'UPDATE cart_items SET quantity = ?, updated_at = ? WHERE user_id = ? AND product_id = ?',
            [$quantity, $now, $userId, $productId]
        );
    }

    public function removeItem(int $userId, int $productId): void
    {
        $this->db->execute(
            'DELETE FROM cart_items WHERE user_id = ? AND product_id = ?',
            [$userId, $productId]
        );
    }

    public function clearCart(int $userId): void
    {
        $this->db->execute('DELETE FROM cart_items WHERE user_id = ?', [$userId]);
    }
}
