<?php

declare(strict_types=1);

namespace ReviewLog\Review;

use Nene2\Database\DatabaseQueryExecutorInterface;

class ReviewRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    /** @return array<string, mixed>|null */
    public function findUserById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findProductById(int $id): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findReviewById(int $id): ?array
    {
        return $this->executor->fetchOne(
            'SELECT r.*, u.name as user_name FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.id = ?',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByProductAndUser(int $productId, int $userId): ?array
    {
        return $this->executor->fetchOne(
            'SELECT * FROM reviews WHERE product_id = ? AND user_id = ?',
            [$productId, $userId]
        );
    }

    public function create(int $productId, int $userId, int $rating, ?string $body, string $now): int
    {
        return $this->executor->insert(
            'INSERT INTO reviews (product_id, user_id, rating, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$productId, $userId, $rating, $body, $now, $now]
        );
    }

    public function update(int $id, int $rating, ?string $body, string $now): void
    {
        $this->executor->execute(
            'UPDATE reviews SET rating = ?, body = ?, updated_at = ? WHERE id = ?',
            [$rating, $body, $now, $id]
        );
    }

    public function delete(int $id): void
    {
        $this->executor->execute('DELETE FROM reviews WHERE id = ?', [$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByProduct(int $productId, int $limit, ?int $beforeId): array
    {
        if ($beforeId !== null) {
            return $this->executor->fetchAll(
                'SELECT r.*, u.name as user_name FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.product_id = ? AND r.id < ? ORDER BY r.id DESC LIMIT ?',
                [$productId, $beforeId, $limit]
            );
        }
        return $this->executor->fetchAll(
            'SELECT r.*, u.name as user_name FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.product_id = ? ORDER BY r.id DESC LIMIT ?',
            [$productId, $limit]
        );
    }

    /** @return array<string, mixed> */
    public function getSummary(int $productId): array
    {
        $row = $this->executor->fetchOne(
            'SELECT COUNT(*) as total, AVG(rating) as avg_rating FROM reviews WHERE product_id = ?',
            [$productId]
        );

        $distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $distRow = $this->executor->fetchOne(
                'SELECT COUNT(*) as cnt FROM reviews WHERE product_id = ? AND rating = ?',
                [$productId, $i]
            );
            $distribution[$i] = $distRow !== null ? (int) $distRow['cnt'] : 0;
        }

        $total = $row !== null ? (int) $row['total'] : 0;
        $avgRating = ($row !== null && $row['avg_rating'] !== null)
            ? round((float) $row['avg_rating'], 2)
            : null;

        return [
            'total' => $total,
            'avg_rating' => $avgRating,
            'distribution' => $distribution,
        ];
    }
}
