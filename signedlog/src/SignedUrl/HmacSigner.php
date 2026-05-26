<?php

declare(strict_types=1);

namespace Signed\SignedUrl;

/**
 * Signs and verifies time-limited, resource-scoped access tokens.
 *
 * Token payload (before base64url encoding):
 *   {resource_id}|{expires_at}|{hmac-sha256}
 *
 * The HMAC covers "resource_id|expires_at" — binding the token
 * to exactly one resource and one expiry time.
 */
final readonly class HmacSigner
{
    private const string ALGO = 'sha256';

    public function __construct(
        private string $secret,
    ) {
    }

    public function sign(int $resourceId, string $expiresAt): string
    {
        $payload = $resourceId . '|' . $expiresAt;
        $mac     = hash_hmac(self::ALGO, $payload, $this->secret);

        return $this->base64UrlEncode($payload . '|' . $mac);
    }

    /**
     * Verify a token and return its resource ID on success, null on failure.
     * Checks HMAC integrity first (constant-time), then expiry.
     * Returns null without distinguishing expired from tampered — callers use 410 for
     * expired tokens only after a separate expiry check if UX requires it.
     */
    public function verify(string $token, string $now): ?int
    {
        $decoded = $this->base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$resourceId, $expiresAt, $storedMac] = $parts;

        $payload     = $resourceId . '|' . $expiresAt;
        $expectedMac = hash_hmac(self::ALGO, $payload, $this->secret);

        if (!hash_equals($expectedMac, $storedMac)) {
            return null;
        }

        if ($expiresAt < $now) {
            return null;
        }

        return (int) $resourceId;
    }

    /**
     * Decode only enough to check expiry — used to return 410 vs 403.
     * Does NOT verify HMAC. Call verify() for authoritative validation.
     */
    public function extractExpiresAt(string $token): ?string
    {
        $decoded = $this->base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('|', $decoded, 3);

        return count($parts) === 3 ? $parts[1] : null;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), strict: true);

        return $decoded === false ? null : $decoded;
    }
}
