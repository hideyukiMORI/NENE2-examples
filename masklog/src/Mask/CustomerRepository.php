<?php

declare(strict_types=1);

namespace MaskLog\Mask;

use PDO;

final class CustomerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function create(string $name, string $email, string $phone, string $now): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO customers (name, email, phone, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $email, $phone, $now]);
        $id = (int) $this->pdo->lastInsertId();
        return ['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'created_at' => $now];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email, phone, created_at FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function logAccess(int $customerId, string $accessor, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mask_audit_log (customer_id, accessor, accessed_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$customerId, $accessor, $now]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAuditLog(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, customer_id, accessor, accessed_at FROM mask_audit_log WHERE customer_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$customerId]);
        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
