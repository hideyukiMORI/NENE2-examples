<?php

declare(strict_types=1);

namespace MaskLog\Mask;

final class MaskService
{
    public function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***@unknown';
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $masked = substr($local, 0, 1) . '***';
        return $masked . '@' . $domain;
    }

    public function maskPhone(string $phone): string
    {
        // Keep last 4 digits; mask everything else with matching separators
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === null || strlen($digits) < 4) {
            return '***';
        }
        $last4   = substr($digits, -4);
        $pattern = $phone;
        // Build masked version: replace digit groups except last 4
        $digitCount = strlen($digits);
        $keepFrom   = $digitCount - 4;
        $replaced   = 0;
        $result     = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                if ($replaced < $keepFrom) {
                    $result .= '*';
                    $replaced++;
                } else {
                    $result .= $ch;
                }
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    public function maskName(string $name): string
    {
        $words = explode(' ', $name);
        $masked = [];
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $masked[] = mb_substr($word, 0, 1) . '***';
        }
        return implode(' ', $masked);
    }

    /**
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    public function applyMask(array $customer): array
    {
        $customer['name']  = $this->maskName((string) $customer['name']);
        $customer['email'] = $this->maskEmail((string) $customer['email']);
        $customer['phone'] = $this->maskPhone((string) $customer['phone']);
        return $customer;
    }
}
