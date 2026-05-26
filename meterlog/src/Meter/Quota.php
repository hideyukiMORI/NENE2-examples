<?php

declare(strict_types=1);

namespace Meterlog\Meter;

final readonly class Quota
{
    public function __construct(
        public int $id,
        public int $userId,
        public int $dailyLimit,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'user_id'     => $this->userId,
            'daily_limit' => $this->dailyLimit,
            'created_at'  => $this->createdAt,
            'updated_at'  => $this->updatedAt,
        ];
    }
}
