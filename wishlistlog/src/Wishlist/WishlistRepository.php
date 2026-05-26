<?php

declare(strict_types=1);

namespace WishlistLog\Wishlist;

use Nene2\Database\DatabaseQueryExecutorInterface;

class WishlistRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM wishlists WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findProductById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
    }

    public function create(int $userId, string $name, bool $isPublic, string $now): int
    {
        return $this->executor->insert(
            'INSERT INTO wishlists (user_id, name, is_public, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $name, $isPublic ? 1 : 0, $now, $now]
        );
    }

    public function update(int $id, string $name, bool $isPublic, string $now): void
    {
        $this->executor->execute(
            'UPDATE wishlists SET name = ?, is_public = ?, updated_at = ? WHERE id = ?',
            [$name, $isPublic ? 1 : 0, $now, $id]
        );
    }

    public function delete(int $id): void
    {
        $this->executor->execute('DELETE FROM wishlist_items WHERE wishlist_id = ?', [$id]);
        $this->executor->execute('DELETE FROM wishlists WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findItem(int $wishlistId, int $productId): ?array
    {
        return $this->executor->fetchOne(
            'SELECT * FROM wishlist_items WHERE wishlist_id = ? AND product_id = ?',
            [$wishlistId, $productId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listItems(int $wishlistId): array
    {
        return $this->executor->fetchAll(
            'SELECT wi.*, p.name as product_name FROM wishlist_items wi JOIN products p ON wi.product_id = p.id WHERE wi.wishlist_id = ? ORDER BY wi.id ASC',
            [$wishlistId]
        );
    }

    public function addItem(int $wishlistId, int $productId, string $priority, ?string $note, string $now): int
    {
        return $this->executor->insert(
            'INSERT INTO wishlist_items (wishlist_id, product_id, priority, note, added_at) VALUES (?, ?, ?, ?, ?)',
            [$wishlistId, $productId, $priority, $note, $now]
        );
    }

    public function removeItem(int $wishlistId, int $productId): void
    {
        $this->executor->execute(
            'DELETE FROM wishlist_items WHERE wishlist_id = ? AND product_id = ?',
            [$wishlistId, $productId]
        );
    }

    public function countItems(int $wishlistId): int
    {
        $row = $this->executor->fetchOne('SELECT COUNT(*) as c FROM wishlist_items WHERE wishlist_id = ?', [$wishlistId]);
        return (int) ($row['c'] ?? 0);
    }
}
