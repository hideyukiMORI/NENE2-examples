<?php

declare(strict_types=1);

namespace WatchLog\Watch;

enum WatchStatus: string
{
    case WantToWatch = 'want-to-watch';
    case Watching = 'watching';
    case Completed = 'completed';
    case Dropped = 'dropped';
}
