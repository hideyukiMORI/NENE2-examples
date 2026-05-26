<?php

declare(strict_types=1);

namespace CouponLog\Coupon;

use Nene2\Database\DatabaseQueryExecutorInterface;

class CouponRepository
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
    public function findByCode(string $code): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM coupons WHERE code = ?', [$code]);
    }

    public function createCoupon(int $createdBy, string $code, int $discountPct, int $maxUses, ?string $expiresAt, string $now): int
    {
        return $this->executor->insert(
            'INSERT INTO coupons (code, discount_pct, max_uses, use_count, is_active, expires_at, created_by, created_at) VALUES (?, ?, ?, 0, 1, ?, ?, ?)',
            [$code, $discountPct, $maxUses, $expiresAt, $createdBy, $now]
        );
    }

    public function deactivateCoupon(int $couponId): void
    {
        $this->executor->execute('UPDATE coupons SET is_active = 0 WHERE id = ?', [$couponId]);
    }

    /** @return array<string, mixed>|null */
    public function findUse(int $couponId, int $userId): ?array
    {
        return $this->executor->fetchOne('SELECT * FROM coupon_uses WHERE coupon_id = ? AND user_id = ?', [$couponId, $userId]);
    }

    public function countUses(int $couponId): int
    {
        $row = $this->executor->fetchOne('SELECT COUNT(*) as c FROM coupon_uses WHERE coupon_id = ?', [$couponId]);
        return (int) ($row['c'] ?? 0);
    }

    /** @return list<array<string, mixed>> */
    public function listUses(int $couponId): array
    {
        return $this->executor->fetchAll(
            'SELECT cu.*, u.name as user_name FROM coupon_uses cu JOIN users u ON cu.user_id = u.id WHERE cu.coupon_id = ? ORDER BY cu.id ASC',
            [$couponId]
        );
    }

    public function recordUse(int $couponId, int $userId, string $now): int
    {
        $id = $this->executor->insert('INSERT INTO coupon_uses (coupon_id, user_id, used_at) VALUES (?, ?, ?)', [$couponId, $userId, $now]);
        $this->executor->execute('UPDATE coupons SET use_count = use_count + 1 WHERE id = ?', [$couponId]);
        return $id;
    }
}
