<?php

declare(strict_types=1);

namespace EventSource\EventSource;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class EventSourceRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    public function createAccount(string $owner, string $now): Account
    {
        $this->executor->execute(
            'INSERT INTO accounts (owner, created_at) VALUES (?, ?)',
            [$owner, $now],
        );

        $row = $this->executor->fetchOne('SELECT * FROM accounts WHERE owner = ? ORDER BY id DESC LIMIT 1', [$owner]);

        return $this->hydrateAccount((array) $row);
    }

    public function findAccountById(int $id): ?Account
    {
        $row = $this->executor->fetchOne('SELECT * FROM accounts WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrateAccount($row) : null;
    }

    /** @param array<string, mixed> $payload */
    public function appendEvent(int $aggregateId, string $eventType, array $payload, string $now): DomainEvent
    {
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->executor->execute(
            'INSERT INTO events (aggregate_id, event_type, payload, occurred_at) VALUES (?, ?, ?, ?)',
            [$aggregateId, $eventType, $payloadJson, $now],
        );

        $row = $this->executor->fetchOne(
            'SELECT * FROM events WHERE aggregate_id = ? ORDER BY id DESC LIMIT 1',
            [$aggregateId],
        );

        return $this->hydrateEvent((array) $row);
    }

    /** @return DomainEvent[] */
    public function findEventsByAggregateId(int $aggregateId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM events WHERE aggregate_id = ? ORDER BY id ASC',
            [$aggregateId],
        );

        return array_map(fn(array $row) => $this->hydrateEvent($row), $rows);
    }

    public function replayBalance(int $aggregateId): int
    {
        $events  = $this->findEventsByAggregateId($aggregateId);
        $balance = 0;

        foreach ($events as $event) {
            $amount = isset($event->payload['amount']) ? (int) $event->payload['amount'] : 0;

            if ($event->eventType === DomainEvent::TYPE_DEPOSITED) {
                $balance += $amount;
            } elseif ($event->eventType === DomainEvent::TYPE_WITHDRAWN) {
                $balance -= $amount;
            }
        }

        return $balance;
    }

    /** @param array<string, mixed> $row */
    private function hydrateAccount(array $row): Account
    {
        return new Account(
            id: (int) $row['id'],
            owner: (string) $row['owner'],
            createdAt: (string) $row['created_at'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateEvent(array $row): DomainEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row['payload'], associative: true, flags: JSON_THROW_ON_ERROR);

        return new DomainEvent(
            id: (int) $row['id'],
            aggregateId: (int) $row['aggregate_id'],
            eventType: (string) $row['event_type'],
            payload: $payload,
            occurredAt: (string) $row['occurred_at'],
        );
    }
}
