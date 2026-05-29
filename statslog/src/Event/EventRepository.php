<?php

declare(strict_types=1);

namespace StatsLog\Event;

use Nene2\Database\DatabaseQueryExecutorInterface;

class EventRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function record(string $type, string $userId, string $sessionId, string $propertiesJson, string $occurredAt): int
    {
        return $this->db->insert(
            'INSERT INTO events (event_type, user_id, session_id, properties, occurred_at) VALUES (?, ?, ?, ?, ?)',
            [$type, $userId, $sessionId, $propertiesJson, $occurredAt],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM events WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listAll(int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT * FROM events ORDER BY occurred_at DESC, id DESC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    /**
     * Filter by a JSON property. Both the JSONPath and the value are bound
     * parameters — the key cannot inject SQL even with special characters.
     *
     * @return list<array<string, mixed>>
     */
    public function byProperty(string $key, string $value, int $limit, int $offset): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT * FROM events WHERE json_extract(properties, ?) = ?
             ORDER BY occurred_at DESC, id DESC LIMIT ? OFFSET ?',
            ['$.' . $key, $value, $limit, $offset],
        );
    }

    /** @return list<array{day: string, count: int}> */
    public function perDay(string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(*) AS count
             FROM events WHERE occurred_at >= ? AND occurred_at < ?
             GROUP BY day ORDER BY day ASC",
            [$from, $to],
        );
        return array_map(
            static fn (array $r): array => ['day' => (string) $r['day'], 'count' => (int) $r['count']],
            $rows,
        );
    }

    /** @return list<array{event_type: string, count: int}> */
    public function perType(string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            'SELECT event_type, COUNT(*) AS count
             FROM events WHERE occurred_at >= ? AND occurred_at < ?
             GROUP BY event_type ORDER BY count DESC, event_type ASC',
            [$from, $to],
        );
        return array_map(
            static fn (array $r): array => ['event_type' => (string) $r['event_type'], 'count' => (int) $r['count']],
            $rows,
        );
    }

    /** @return list<array{day: string, unique_users: int}> */
    public function uniqueUsers(string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(DISTINCT user_id) AS unique_users
             FROM events WHERE occurred_at >= ? AND occurred_at < ?
             GROUP BY day ORDER BY day ASC",
            [$from, $to],
        );
        return array_map(
            static fn (array $r): array => ['day' => (string) $r['day'], 'unique_users' => (int) $r['unique_users']],
            $rows,
        );
    }
}
