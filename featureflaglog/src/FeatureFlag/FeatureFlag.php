<?php

declare(strict_types=1);

namespace FeatureFlag\FeatureFlag;

final readonly class FeatureFlag
{
    public function __construct(
        public int    $id,
        public string $name,
        public string $description,
        public bool   $globallyEnabled,
        public int    $rolloutPct,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'description'      => $this->description,
            'globally_enabled' => $this->globallyEnabled,
            'rollout_pct'      => $this->rolloutPct,
            'created_at'       => $this->createdAt,
        ];
    }
}
