<?php

declare(strict_types=1);

namespace DedupLog\Dedup;

use PDO;

final class IdempotencyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, idempotency_key, method, path, status_code, response_body, created_at, expires_at
             FROM idempotency_keys WHERE idempotency_key = ?'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function store(
        string $key,
        string $method,
        string $path,
        int $statusCode,
        string $responseBody,
        string $now,
        string $expiresAt,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO idempotency_keys
             (idempotency_key, method, path, status_code, response_body, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$key, $method, $path, $statusCode, $responseBody, $now, $expiresAt]);
    }

    public function createPayment(int $amount, string $currency, string $key, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments (amount, currency, status, idempotency_key, created_at)
             VALUES (?, ?, \'completed\', ?, ?)'
        );
        $stmt->execute([$amount, $currency, $key, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createOrder(string $item, int $quantity, string $key, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (item, quantity, idempotency_key, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$item, $quantity, $key, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function countPayments(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM payments');
        assert($stmt !== false);
        return (int) $stmt->fetchColumn();
    }

    public function countOrders(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM orders');
        assert($stmt !== false);
        return (int) $stmt->fetchColumn();
    }
}
