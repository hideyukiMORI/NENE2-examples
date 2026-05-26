<?php

declare(strict_types=1);

namespace Lock\Lock;

enum ReleaseResult
{
    case Released;
    case NotFound;
    case Forbidden;
}
