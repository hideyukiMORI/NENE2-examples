<?php

declare(strict_types=1);

namespace Audit\AuditLog;

final readonly class AuditEntry
{
    public function __construct(
        public int    $id,
        public int    $actorId,
        public string $action,
        public string $resourceType,
        public int    $resourceId,
        public string $occurredAt,
        public string $payload,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'actor_id'      => $this->actorId,
            'action'        => $this->action,
            'resource_type' => $this->resourceType,
            'resource_id'   => $this->resourceId,
            'occurred_at'   => $this->occurredAt,
            'payload'       => json_decode($this->payload, true),
        ];
    }
}
