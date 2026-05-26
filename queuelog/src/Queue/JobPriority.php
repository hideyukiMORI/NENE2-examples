<?php

declare(strict_types=1);

namespace Queue;

enum JobPriority: int
{
    case Low      = 0;
    case Medium   = 10;
    case High     = 20;
    case Critical = 30;

    public static function fromLabel(string $label): self
    {
        return match (strtolower($label)) {
            'low'      => self::Low,
            'medium'   => self::Medium,
            'high'     => self::High,
            'critical' => self::Critical,
            default    => throw new \InvalidArgumentException("Unknown priority: {$label}"),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low      => 'low',
            self::Medium   => 'medium',
            self::High     => 'high',
            self::Critical => 'critical',
        };
    }
}
