<?php

declare(strict_types=1);

namespace AbLog\Ab;

class VariantAssigner
{
    /**
     * Assigns a user to a variant using weighted random selection seeded by user+experiment.
     * Same user+experiment always gets the same variant (deterministic via crc32 seed).
     *
     * @param list<array<string, mixed>> $variants
     * @return array<string, mixed>|null
     */
    public function assign(array $variants, string $userId, int $experimentId): ?array
    {
        if ($variants === []) {
            return null;
        }

        $totalWeight = 0;
        foreach ($variants as $v) {
            $totalWeight += (int) $v['weight'];
        }
        if ($totalWeight <= 0) {
            return null;
        }

        // Deterministic bucket: crc32 of "userId:experimentId" mod totalWeight
        $seed   = abs(crc32($userId . ':' . $experimentId));
        $bucket = $seed % $totalWeight;

        $cumulative = 0;
        foreach ($variants as $v) {
            $cumulative += (int) $v['weight'];
            if ($bucket < $cumulative) {
                return $v;
            }
        }

        // Fallback (should never reach here with valid weights)
        return $variants[0];
    }
}
