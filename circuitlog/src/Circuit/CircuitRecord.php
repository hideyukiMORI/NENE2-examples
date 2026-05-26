<?php

declare(strict_types=1);

namespace Circuit\Circuit;

final readonly class CircuitRecord
{
    public function __construct(
        public int          $id,
        public string       $name,
        public CircuitState $state,
        public int          $failureCount,
        public int          $failureThreshold,
        public ?string      $openUntil,
        public ?string      $halfOpenAt,
        public ?string      $lastFailureAt,
        public string       $updatedAt,
    ) {
    }

    public function isCallAllowed(string $now): bool
    {
        return match ($this->state) {
            CircuitState::Closed   => true,
            CircuitState::Open     => $now >= ($this->openUntil ?? ''),
            CircuitState::HalfOpen => true,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'state'             => $this->state->value,
            'failure_count'     => $this->failureCount,
            'failure_threshold' => $this->failureThreshold,
            'open_until'        => $this->openUntil,
            'last_failure_at'   => $this->lastFailureAt,
        ];
    }
}
