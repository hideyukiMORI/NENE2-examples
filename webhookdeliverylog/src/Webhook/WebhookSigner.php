<?php

declare(strict_types=1);

namespace Webhook\Webhook;

/**
 * Signs webhook payloads with HMAC-SHA256.
 *
 * Signature format: "sha256={hex}"
 * Signed content: "{timestamp}.{body}" — timestamp binding prevents replay attacks.
 *
 * The secret is stored as a SHA-256 hash in the DB. On delivery, the raw secret
 * is not available — callers must store the raw secret themselves at creation time
 * and pass it here for signing.
 */
final class WebhookSigner
{
    public function sign(string $rawSecret, string $body, string $timestamp): string
    {
        $payload = $timestamp . '.' . $body;
        $mac     = hash_hmac('sha256', $payload, $rawSecret);

        return 'sha256=' . $mac;
    }

    public function hashSecret(string $rawSecret): string
    {
        return hash('sha256', $rawSecret);
    }
}
