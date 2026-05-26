<?php

declare(strict_types=1);

namespace InboundLog\Inbound;

use Nene2\Database\DatabaseQueryExecutorInterface;

class WebhookRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function createSource(string $name, string $secret, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO webhook_sources (name, secret, active, created_at) VALUES (?, ?, 1, ?)',
            [$name, $secret, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function findSource(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT * FROM webhook_sources WHERE id = ?', [$id]);
    }

    public function storeEvent(int $sourceId, string $eventId, string $eventType, string $payload, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO inbound_events (source_id, event_id, event_type, payload, processed_at) VALUES (?, ?, ?, ?, ?)',
            [$sourceId, $eventId, $eventType, $payload, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function findEventBySourceAndEventId(int $sourceId, string $eventId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT * FROM inbound_events WHERE source_id = ? AND event_id = ?',
            [$sourceId, $eventId],
        );
    }

    /** @return array<string, mixed>|null */
    public function findEvent(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT * FROM inbound_events WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listEvents(int $sourceId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT * FROM inbound_events WHERE source_id = ? ORDER BY id ASC',
            [$sourceId],
        );
    }
}
