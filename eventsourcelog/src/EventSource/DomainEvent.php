<?php

declare(strict_types=1);

namespace EventSource\EventSource;

final readonly class DomainEvent
{
    public const string TYPE_ACCOUNT_CREATED = 'account_created';
    public const string TYPE_DEPOSITED       = 'deposited';
    public const string TYPE_WITHDRAWN       = 'withdrawn';

    /** @param array<string, mixed> $payload */
    public function __construct(
        public int    $id,
        public int    $aggregateId,
        public string $eventType,
        public array  $payload,
        public string $occurredAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'aggregate_id' => $this->aggregateId,
            'event_type'   => $this->eventType,
            'payload'      => $this->payload,
            'occurred_at'  => $this->occurredAt,
        ];
    }
}
