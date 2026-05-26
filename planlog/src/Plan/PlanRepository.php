<?php

declare(strict_types=1);

namespace Plan\Plan;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class PlanRepository
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

    /** @return array<int, array{id: int, slug: string, name: string, price_cents: int}> */
    public function listPlans(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT id, slug, name, price_cents FROM plans ORDER BY price_cents ASC',
            [],
        );

        return array_map(fn(mixed $row) => $this->hydratePlan((array) $row), $rows);
    }

    /** @return array{id: int, slug: string, name: string, price_cents: int}|null */
    public function findPlanBySlug(string $slug): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT id, slug, name, price_cents FROM plans WHERE slug = ?',
            [$slug],
        );

        return $row !== null ? $this->hydratePlan((array) $row) : null;
    }

    /** @return array{id: int, user_id: int, plan_id: int, plan_slug: string, plan_name: string, price_cents: int, status: string, started_at: string, cancelled_at: string|null}|null */
    public function findSubscription(int $userId): ?array
    {
        $row = $this->executor->fetchOne(
            'SELECT s.id, s.user_id, s.plan_id, p.slug AS plan_slug, p.name AS plan_name,
                    p.price_cents, s.status, s.started_at, s.cancelled_at
             FROM subscriptions s
             INNER JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ?',
            [$userId],
        );

        return $row !== null ? $this->hydrateSubscription((array) $row) : null;
    }

    public function subscribe(int $userId, int $planId, string $now): void
    {
        $existing = $this->findSubscription($userId);

        if ($existing !== null) {
            // Reactivate a cancelled subscription
            $this->executor->execute(
                "UPDATE subscriptions SET plan_id = ?, status = 'active', started_at = ?, cancelled_at = NULL WHERE user_id = ?",
                [$planId, $now, $userId],
            );

            return;
        }

        $this->executor->execute(
            'INSERT INTO subscriptions (user_id, plan_id, status, started_at) VALUES (?, ?, ?, ?)',
            [$userId, $planId, 'active', $now],
        );
    }

    public function changePlan(int $userId, int $planId, string $now): void
    {
        $this->executor->execute(
            'UPDATE subscriptions SET plan_id = ?, status = ?, started_at = ?, cancelled_at = NULL
             WHERE user_id = ?',
            [$planId, 'active', $now, $userId],
        );
    }

    public function cancel(int $userId, string $now): bool
    {
        $count = $this->executor->execute(
            "UPDATE subscriptions SET status = 'cancelled', cancelled_at = ?
             WHERE user_id = ? AND status = 'active'",
            [$now, $userId],
        );

        return $count > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, slug: string, name: string, price_cents: int}
     */
    private function hydratePlan(array $row): array
    {
        return [
            'id'          => isset($row['id']) ? (int) $row['id'] : 0,
            'slug'        => isset($row['slug']) && is_string($row['slug']) ? $row['slug'] : '',
            'name'        => isset($row['name']) && is_string($row['name']) ? $row['name'] : '',
            'price_cents' => isset($row['price_cents']) ? (int) $row['price_cents'] : 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, user_id: int, plan_id: int, plan_slug: string, plan_name: string, price_cents: int, status: string, started_at: string, cancelled_at: string|null}
     */
    private function hydrateSubscription(array $row): array
    {
        return [
            'id'           => isset($row['id']) ? (int) $row['id'] : 0,
            'user_id'      => isset($row['user_id']) ? (int) $row['user_id'] : 0,
            'plan_id'      => isset($row['plan_id']) ? (int) $row['plan_id'] : 0,
            'plan_slug'    => isset($row['plan_slug']) && is_string($row['plan_slug']) ? $row['plan_slug'] : '',
            'plan_name'    => isset($row['plan_name']) && is_string($row['plan_name']) ? $row['plan_name'] : '',
            'price_cents'  => isset($row['price_cents']) ? (int) $row['price_cents'] : 0,
            'status'       => isset($row['status']) && is_string($row['status']) ? $row['status'] : 'active',
            'started_at'   => isset($row['started_at']) && is_string($row['started_at']) ? $row['started_at'] : '',
            'cancelled_at' => isset($row['cancelled_at']) && is_string($row['cancelled_at']) ? $row['cancelled_at'] : null,
        ];
    }
}
