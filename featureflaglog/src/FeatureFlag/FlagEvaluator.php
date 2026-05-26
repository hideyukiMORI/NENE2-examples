<?php

declare(strict_types=1);

namespace FeatureFlag\FeatureFlag;

final readonly class FlagEvaluator
{
    /**
     * Evaluate a flag for a specific user and optional tenant.
     *
     * Priority (highest to lowest):
     *  1. Explicit user-level target override
     *  2. Explicit tenant-level target override
     *  3. globally_enabled
     *  4. rollout_pct: hash-based bucket assignment
     *  5. false
     *
     * @param FlagTarget[] $targets
     */
    public function evaluate(FeatureFlag $flag, array $targets, string $userId, ?string $tenantId): bool
    {
        foreach ($targets as $target) {
            if ($target->targetType === 'user' && $target->targetId === $userId) {
                return $target->enabled;
            }
        }

        if ($tenantId !== null) {
            foreach ($targets as $target) {
                if ($target->targetType === 'tenant' && $target->targetId === $tenantId) {
                    return $target->enabled;
                }
            }
        }

        if ($flag->globallyEnabled) {
            return true;
        }

        if ($flag->rolloutPct > 0) {
            $bucket = abs(crc32($userId . '.' . $flag->name)) % 100;
            return $bucket < $flag->rolloutPct;
        }

        return false;
    }
}
