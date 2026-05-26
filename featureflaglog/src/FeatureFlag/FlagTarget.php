<?php

declare(strict_types=1);

namespace FeatureFlag\FeatureFlag;

final readonly class FlagTarget
{
    public function __construct(
        public int    $id,
        public int    $flagId,
        public string $targetType,
        public string $targetId,
        public bool   $enabled,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'flag_id'     => $this->flagId,
            'target_type' => $this->targetType,
            'target_id'   => $this->targetId,
            'enabled'     => $this->enabled,
        ];
    }
}
