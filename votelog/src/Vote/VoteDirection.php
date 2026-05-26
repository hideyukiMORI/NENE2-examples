<?php

declare(strict_types=1);

namespace Vote\Vote;

enum VoteDirection: string
{
    case Up   = 'up';
    case Down = 'down';
}
