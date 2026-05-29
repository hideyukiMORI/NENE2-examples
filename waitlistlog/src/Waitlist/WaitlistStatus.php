<?php

declare(strict_types=1);

namespace WaitlistLog\Waitlist;

enum WaitlistStatus: string
{
    case Waiting = 'waiting';
    case Approved = 'approved';
    case Declined = 'declined';

    /** Approved and declined are terminal — no further transitions allowed. */
    public function isTerminal(): bool
    {
        return $this !== self::Waiting;
    }
}
