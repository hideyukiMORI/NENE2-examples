<?php

declare(strict_types=1);

namespace TimeLog\Timer;

final class TimerAlreadyRunningException extends TimerException
{
    public function __construct(public readonly int $runningId)
    {
        parent::__construct('a timer is already running');
    }
}
