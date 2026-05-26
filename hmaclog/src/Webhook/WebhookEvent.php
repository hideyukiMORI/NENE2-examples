<?php

declare(strict_types=1);

namespace Hmac\Webhook;

final readonly class WebhookEvent
{
    public function __construct(
        public int $id,
        public string $eventType,
        public string $payload,
        public string $deliveredAt,
    ) {
    }
}
