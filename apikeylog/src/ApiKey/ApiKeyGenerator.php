<?php

declare(strict_types=1);

namespace ApiKey\ApiKey;

/**
 * Generates cryptographically secure API keys.
 *
 * Format: {prefix}_{base64url(32 random bytes)}
 * Example: nk_Vf3aB2cX...
 *
 * The prefix makes it easy to identify key type in logs and grep codebase.
 * The raw key is returned only once at creation time; only the hash is stored.
 */
final class ApiKeyGenerator
{
    private const string PREFIX = 'nk';
    private const int    BYTES  = 32;

    public function generate(): string
    {
        $raw = random_bytes(self::BYTES);

        return self::PREFIX . '_' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /**
     * Extract a lookup prefix from the raw key.
     * Uses the first 16 characters of the full key (includes type prefix + start of random part).
     * This gives ~78 bits of differentiation, making each key's prefix effectively unique,
     * which enables O(1) DB index lookup without storing the full key.
     */
    public function extractPrefix(string $rawKey): string
    {
        return substr($rawKey, 0, 16);
    }

    /**
     * Timing-safe comparison of a raw key against a stored hash.
     * Uses hash_equals() to prevent timing attacks.
     */
    public function verify(string $rawKey, string $storedHash): bool
    {
        $computed = $this->hash($rawKey);

        return hash_equals($storedHash, $computed);
    }
}
