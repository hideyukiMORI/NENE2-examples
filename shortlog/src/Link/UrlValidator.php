<?php

declare(strict_types=1);

namespace ShortLog\Link;

/**
 * SSRF-safe URL validation: scheme allow-list + private/reserved IP blocking,
 * with an injectable DNS resolver so tests need no real network calls.
 *
 * `filter_var(FILTER_VALIDATE_URL)` alone is NOT enough — it accepts
 * `javascript:...` and `ftp://`, and never inspects the resolved IP.
 */
final class UrlValidator
{
    /** @param (\Closure(string): string)|null $ipResolver host → IP (or host when unresolvable) */
    public function __construct(private readonly ?\Closure $ipResolver = null)
    {
    }

    public function isSafe(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        // IP literal → check directly.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return !$this->isBlockedIp($host);
        }

        // Hostname → resolve, then check the resolved IP.
        $resolver = $this->ipResolver ?? static fn (string $h): string => gethostbyname($h);
        $resolved = $resolver($host);
        if ($resolved !== $host) {
            return !$this->isBlockedIp($resolved);
        }
        return true; // unresolvable → allow (cannot reach an internal target)
    }

    private function isBlockedIp(string $ip): bool
    {
        if ($ip === '::1') {
            return true; // IPv6 loopback
        }
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
