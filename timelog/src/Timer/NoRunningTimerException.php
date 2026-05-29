<?php

declare(strict_types=1);

namespace TimeLog\Timer;

final class NoRunningTimerException extends TimerException
{
    public function __construct()
    {
        parent::__construct('no timer is running');
    }
}
