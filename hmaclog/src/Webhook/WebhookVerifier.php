<?php

declare(strict_types=1);

namespace Hmac\Webhook;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Verifies incoming webhook signatures using HMAC-SHA256.
 *
 * Signature header format (Stripe-style):
 *   X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-hex>
 *
 * The signed payload is: "<timestamp>.<raw-body>"
 * This ties the timestamp to the body, preventing replay with a different timestamp.
 *
 * IMPORTANT: Use hash_equals() for comparison, NOT === or ==.
 * String comparison with === is vulnerable to timing attacks: an attacker can
 * measure response time differences to learn how many characters of the signature
 * match, and brute-force the secret one byte at a time.
 * hash_equals() runs in constant time regardless of where the strings diverge.
 */
final class WebhookVerifier
{
    /** Maximum age of a webhook before it is rejected as a potential replay. */
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(private readonly string $secret)
    {
    }

    /**
     * Verify the signature on an incoming webhook request.
     *
     * @throws SignatureException if the header is missing, malformed, expired, or the signature does not match
     */
    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        if ($header === '') {
            throw new SignatureException('Missing X-Webhook-Signature header.');
        }

        ['timestamp' => $timestamp, 'signature' => $receivedSig] = $this->parseHeader($header);

        $this->checkTimestamp($timestamp);

        $expectedSig = $this->computeSignature($timestamp, $rawBody);

        // CRITICAL: hash_equals is constant-time; === is NOT
        if (!hash_equals($expectedSig, $receivedSig)) {
            throw new SignatureException('Signature mismatch.');
        }
    }

    /**
     * Generate a valid signature header for the given body and timestamp.
     * Used by senders to sign outgoing webhooks (and in tests to build valid requests).
     */
    public function sign(string $rawBody, int $timestamp): string
    {
        $sig = $this->computeSignature($timestamp, $rawBody);
        return "t={$timestamp},v1={$sig}";
    }

    /** @return array{timestamp: int, signature: string} */
    private function parseHeader(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $chunk) {
            [$k, $v] = explode('=', $chunk, 2) + ['', ''];
            $parts[$k] = $v;
        }

        if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t']) || $parts['v1'] === '') {
            throw new SignatureException('Malformed X-Webhook-Signature header.');
        }

        return ['timestamp' => (int) $parts['t'], 'signature' => $parts['v1']];
    }

    private function checkTimestamp(int $timestamp): void
    {
        $age = abs(time() - $timestamp);
        if ($age > self::TOLERANCE_SECONDS) {
            throw new SignatureException(
                sprintf('Webhook timestamp is %d seconds old (tolerance: %d).', $age, self::TOLERANCE_SECONDS),
            );
        }
    }

    private function computeSignature(int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
    }
}
