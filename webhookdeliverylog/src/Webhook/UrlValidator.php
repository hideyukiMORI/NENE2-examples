<?php

declare(strict_types=1);

namespace Webhook\Webhook;

/**
 * Validates outbound webhook URLs against SSRF and injection risks.
 *
 * Blocks:
 * - Non-HTTPS schemes (http, file, ftp, gopher, dict, ...)
 * - Private/loopback IP ranges (127.x, 10.x, 172.16-31.x, 192.168.x, ::1, fc00::/7)
 * - Reserved hostnames (localhost, .local, .internal, .test, .example)
 * - Newline characters in URLs (header injection)
 * - URLs without a valid hostname
 */
final class UrlValidator
{
    private const array BLOCKED_HOSTNAMES = [
        'localhost',
        'ip6-localhost',
        'ip6-loopback',
    ];

    private const array BLOCKED_TLD_PATTERNS = [
        '.local',
        '.internal',
        '.test',
        '.example',
        '.invalid',
        '.localhost',
    ];

    public function validate(string $url): ?string
    {
        // Block header injection characters
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return 'URL is not valid.';
        }

        if (strtolower($parsed['scheme']) !== 'https') {
            return 'Only HTTPS URLs are allowed for webhook delivery.';
        }

        $host = strtolower($parsed['host']);

        // Blocked hostnames
        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            return "Webhook URL must not target '{$host}'.";
        }

        // Blocked TLD patterns
        foreach (self::BLOCKED_TLD_PATTERNS as $pattern) {
            if (str_ends_with($host, $pattern)) {
                return "Webhook URL must not target '{$pattern}' domains.";
            }
        }

        // Strip IPv6 brackets for IP check
        $ip = trim($host, '[]');

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if ($this->isPrivateIpv4($ip)) {
                return 'Webhook URL must not target private or loopback IP addresses.';
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if ($this->isPrivateIpv6($ip)) {
                return 'Webhook URL must not target private or loopback IPv6 addresses.';
            }
        }

        return null;
    }

    private function isPrivateIpv4(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function isPrivateIpv6(string $ip): bool
    {
        // ::1 (loopback), fc00::/7 (ULA), fe80::/10 (link-local)
        $expanded = inet_pton($ip);
        if ($expanded === false) {
            return true;
        }

        $loopback = inet_pton('::1');

        return $expanded === $loopback
            || (ord($expanded[0]) & 0xFE) === 0xFC   // fc00::/7 ULA
            || (ord($expanded[0]) === 0xFE && (ord($expanded[1]) & 0xC0) === 0x80); // fe80::/10
    }
}
