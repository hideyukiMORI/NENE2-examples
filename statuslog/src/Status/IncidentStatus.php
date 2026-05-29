<?php

declare(strict_types=1);

namespace StatusLog\Status;

enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
