<?php

declare(strict_types=1);

namespace FeatureFlag\FeatureFlag;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class FeatureFlagRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {
    }

    public function create(string $name, string $description, string $now): ?FeatureFlag
    {
        try {
            $this->executor->execute(
                'INSERT INTO feature_flags (name, description, globally_enabled, rollout_pct, created_at) VALUES (?, ?, 0, 0, ?)',
                [$name, $description, $now],
            );
        } catch (\RuntimeException) {
            return null;
        }

        return $this->findByName($name);
    }

    public function findByName(string $name): ?FeatureFlag
    {
        $row = $this->executor->fetchOne(
            'SELECT * FROM feature_flags WHERE name = ?',
            [$name],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function setGloballyEnabled(string $name, bool $enabled): ?FeatureFlag
    {
        $this->executor->execute(
            'UPDATE feature_flags SET globally_enabled = ? WHERE name = ?',
            [$enabled ? 1 : 0, $name],
        );

        return $this->findByName($name);
    }

    public function setRolloutPct(string $name, int $pct): ?FeatureFlag
    {
        $this->executor->execute(
            'UPDATE feature_flags SET rollout_pct = ? WHERE name = ?',
            [$pct, $name],
        );

        return $this->findByName($name);
    }

    public function upsertTarget(int $flagId, string $targetType, string $targetId, bool $enabled): FlagTarget
    {
        $existing = $this->executor->fetchOne(
            'SELECT * FROM flag_targets WHERE flag_id = ? AND target_type = ? AND target_id = ?',
            [$flagId, $targetType, $targetId],
        );

        if ($existing !== null) {
            $this->executor->execute(
                'UPDATE flag_targets SET enabled = ? WHERE id = ?',
                [$enabled ? 1 : 0, $existing['id']],
            );
        } else {
            $this->executor->execute(
                'INSERT INTO flag_targets (flag_id, target_type, target_id, enabled) VALUES (?, ?, ?, ?)',
                [$flagId, $targetType, $targetId, $enabled ? 1 : 0],
            );
        }

        $row = $this->executor->fetchOne(
            'SELECT * FROM flag_targets WHERE flag_id = ? AND target_type = ? AND target_id = ?',
            [$flagId, $targetType, $targetId],
        );

        return $this->hydrateTarget((array) $row);
    }

    public function deleteTarget(int $flagId, string $targetType, string $targetId): bool
    {
        $existing = $this->executor->fetchOne(
            'SELECT id FROM flag_targets WHERE flag_id = ? AND target_type = ? AND target_id = ?',
            [$flagId, $targetType, $targetId],
        );

        if ($existing === null) {
            return false;
        }

        $this->executor->execute(
            'DELETE FROM flag_targets WHERE id = ?',
            [$existing['id']],
        );

        return true;
    }

    /**
     * @return FlagTarget[]
     */
    public function findTargetsByFlag(int $flagId): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM flag_targets WHERE flag_id = ?',
            [$flagId],
        );

        return array_map($this->hydrateTarget(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): FeatureFlag
    {
        return new FeatureFlag(
            id:              (int) $row['id'],
            name:            (string) $row['name'],
            description:     (string) $row['description'],
            globallyEnabled: (bool) $row['globally_enabled'],
            rolloutPct:      (int) $row['rollout_pct'],
            createdAt:       (string) $row['created_at'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateTarget(array $row): FlagTarget
    {
        return new FlagTarget(
            id:         (int) $row['id'],
            flagId:     (int) $row['flag_id'],
            targetType: (string) $row['target_type'],
            targetId:   (string) $row['target_id'],
            enabled:    (bool) $row['enabled'],
        );
    }
}
