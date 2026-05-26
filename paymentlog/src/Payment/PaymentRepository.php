<?php

declare(strict_types=1);

namespace PaymentLog\Payment;

use Nene2\Database\DatabaseQueryExecutorInterface;

class PaymentRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db) {}

    // -------------------------------------------------------------------------
    // Payments

    public function createPayment(string $externalId, int $amount, string $currency, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO payments (external_id, amount, currency, status, created_at, updated_at) VALUES (?, ?, ?, \'pending\', ?, ?)',
            [$externalId, $amount, $currency, $now, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function findByExternalId(string $externalId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT * FROM payments WHERE external_id = ?', [$externalId]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT * FROM payments WHERE id = ?', [$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll('SELECT * FROM payments ORDER BY id DESC', []);
    }

    public function updateStatus(string $externalId, string $status, string $now): bool
    {
        $payment = $this->findByExternalId($externalId);
        if ($payment === null) {
            return false;
        }
        $this->db->insert(
            'UPDATE payments SET status = ?, updated_at = ? WHERE external_id = ?',
            [$status, $now, $externalId],
        );
        return true;
    }

    // -------------------------------------------------------------------------
    // Idempotency

    public function isEventProcessed(string $eventId): bool
    {
        $row = $this->db->fetchOne('SELECT id FROM webhook_events WHERE event_id = ?', [$eventId]);
        return $row !== null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordEvent(string $eventId, string $eventType, array $payload, string $now): void
    {
        $this->db->insert(
            'INSERT INTO webhook_events (event_id, event_type, payload, processed_at) VALUES (?, ?, ?, ?)',
            [$eventId, $eventType, json_encode($payload), $now],
        );
    }
}
