<?php

declare(strict_types=1);

namespace Webhook\Webhook;

final readonly class WebhookEndpoint
{
    public function __construct(
        public int    $id,
        public string $url,
        public string $eventType,
        public string $secretHash,
        public int    $maxRetries,
        public bool   $active,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'url'         => $this->url,
            'event_type'  => $this->eventType,
            'max_retries' => $this->maxRetries,
            'active'      => $this->active,
            'created_at'  => $this->createdAt,
            // secret_hash intentionally omitted
        ];
    }
}
