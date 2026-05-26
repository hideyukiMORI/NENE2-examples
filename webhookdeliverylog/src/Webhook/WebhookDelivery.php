<?php

declare(strict_types=1);

namespace Webhook\Webhook;

final readonly class WebhookDelivery
{
    public function __construct(
        public int     $id,
        public int     $endpointId,
        public string  $eventType,
        public string  $payload,
        public string  $status,
        public int     $attemptCount,
        public ?int    $lastStatus,
        public ?string $lastError,
        public ?string $deliveredAt,
        public string  $createdAt,
        public string  $updatedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'endpoint_id'   => $this->endpointId,
            'event_type'    => $this->eventType,
            'payload'       => json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR),
            'status'        => $this->status,
            'attempt_count' => $this->attemptCount,
            'last_status'   => $this->lastStatus,
            'last_error'    => $this->lastError,
            'delivered_at'  => $this->deliveredAt,
            'created_at'    => $this->createdAt,
        ];
    }
}
