<?php

declare(strict_types=1);

namespace Hmac\Webhook;

use Nene2\Database\DatabaseQueryExecutorInterface;

final class WebhookEventRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $executor)
    {
    }

    public function store(string $eventType, string $payload): WebhookEvent
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $id = $this->executor->insert(
            'INSERT INTO webhook_events (event_type, payload, delivered_at) VALUES (?, ?, ?)',
            [$eventType, $payload, $now],
        );

        return new WebhookEvent(id: $id, eventType: $eventType, payload: $payload, deliveredAt: $now);
    }

    public function count(): int
    {
        /** @var array{cnt: int}|null $row */
        $row = $this->executor->fetchOne('SELECT COUNT(*) as cnt FROM webhook_events');
        return $row['cnt'] ?? 0;
    }

    /** @return list<WebhookEvent> */
    public function findAll(): array
    {
        /** @var list<array{id: int, event_type: string, payload: string, delivered_at: string}> $rows */
        $rows = $this->executor->fetchAll(
            'SELECT id, event_type, payload, delivered_at FROM webhook_events ORDER BY id',
        );

        return array_map(
            fn (array $r) => new WebhookEvent(
                id:          $r['id'],
                eventType:   $r['event_type'],
                payload:     $r['payload'],
                deliveredAt: $r['delivered_at'],
            ),
            $rows,
        );
    }
}
