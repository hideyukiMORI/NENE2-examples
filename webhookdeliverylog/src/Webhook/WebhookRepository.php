<?php

declare(strict_types=1);

namespace Webhook\Webhook;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class WebhookRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
        private WebhookSigner                 $signer,
    ) {
    }

    public function createEndpoint(
        string $url,
        string $eventType,
        string $rawSecret,
        string $now,
        int    $maxRetries = 3,
    ): WebhookEndpoint {
        $secretHash = $this->signer->hashSecret($rawSecret);

        $this->executor->execute(
            'INSERT INTO webhook_endpoints (url, event_type, secret_hash, max_retries, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
            [$url, $eventType, $secretHash, $maxRetries, $now],
        );
        $id = (int) $this->executor->lastInsertId();

        return new WebhookEndpoint($id, $url, $eventType, $secretHash, $maxRetries, true, $now);
    }

    public function findEndpointById(int $id): ?WebhookEndpoint
    {
        $rows = $this->executor->fetchAll('SELECT * FROM webhook_endpoints WHERE id = ?', [$id]);
        if ($rows === []) {
            return null;
        }

        return $this->hydrateEndpoint($rows[0]);
    }

    /**
     * @return list<WebhookEndpoint>
     */
    public function findActiveEndpointsByEventType(string $eventType): array
    {
        $rows = $this->executor->fetchAll(
            "SELECT * FROM webhook_endpoints WHERE event_type = ? AND active = 1",
            [$eventType],
        );

        return array_map(fn (array $row) => $this->hydrateEndpoint($row), $rows);
    }

    public function deactivateEndpoint(int $id): void
    {
        $this->executor->execute(
            'UPDATE webhook_endpoints SET active = 0 WHERE id = ?',
            [$id],
        );
    }

    public function createDelivery(int $endpointId, string $eventType, string $payload, string $now): WebhookDelivery
    {
        $this->executor->execute(
            "INSERT INTO webhook_deliveries (endpoint_id, event_type, payload, status, attempt_count, created_at, updated_at)
             VALUES (?, ?, ?, 'pending', 0, ?, ?)",
            [$endpointId, $eventType, $payload, $now, $now],
        );
        $id = (int) $this->executor->lastInsertId();

        return new WebhookDelivery($id, $endpointId, $eventType, $payload, 'pending', 0, null, null, null, $now, $now);
    }

    public function findDeliveryById(int $id): ?WebhookDelivery
    {
        $rows = $this->executor->fetchAll('SELECT * FROM webhook_deliveries WHERE id = ?', [$id]);
        if ($rows === []) {
            return null;
        }

        return $this->hydrateDelivery($rows[0]);
    }

    /**
     * @return list<WebhookDelivery>
     */
    public function findDeliveriesByEndpoint(int $endpointId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM webhook_deliveries WHERE endpoint_id = ? ORDER BY created_at DESC',
            [$endpointId],
        );

        return array_map(fn (array $row) => $this->hydrateDelivery($row), $rows);
    }

    public function markDelivered(int $id, int $httpStatus, string $now): ?WebhookDelivery
    {
        $delivery = $this->findDeliveryById($id);
        if ($delivery === null) {
            return null;
        }

        $this->executor->execute(
            "UPDATE webhook_deliveries SET status = 'delivered', attempt_count = attempt_count + 1, last_status = ?, delivered_at = ?, updated_at = ? WHERE id = ?",
            [$httpStatus, $now, $now, $id],
        );

        return $this->findDeliveryById($id);
    }

    public function markFailed(int $id, string $error, ?int $httpStatus, string $now, int $maxRetries): ?WebhookDelivery
    {
        $delivery = $this->findDeliveryById($id);
        if ($delivery === null) {
            return null;
        }

        $newCount  = $delivery->attemptCount + 1;
        $newStatus = $newCount >= $maxRetries ? 'failed' : 'pending';

        $this->executor->execute(
            'UPDATE webhook_deliveries SET status = ?, attempt_count = ?, last_status = ?, last_error = ?, updated_at = ? WHERE id = ?',
            [$newStatus, $newCount, $httpStatus, $error, $now, $id],
        );

        return $this->findDeliveryById($id);
    }

    /** @param array<string, mixed> $row */
    private function hydrateEndpoint(array $row): WebhookEndpoint
    {
        return new WebhookEndpoint(
            (int) $row['id'],
            (string) $row['url'],
            (string) $row['event_type'],
            (string) $row['secret_hash'],
            (int) $row['max_retries'],
            (bool) $row['active'],
            (string) $row['created_at'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateDelivery(array $row): WebhookDelivery
    {
        return new WebhookDelivery(
            (int) $row['id'],
            (int) $row['endpoint_id'],
            (string) $row['event_type'],
            (string) $row['payload'],
            (string) $row['status'],
            (int) $row['attempt_count'],
            isset($row['last_status']) ? (int) $row['last_status'] : null,
            isset($row['last_error']) ? (string) $row['last_error'] : null,
            isset($row['delivered_at']) ? (string) $row['delivered_at'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
