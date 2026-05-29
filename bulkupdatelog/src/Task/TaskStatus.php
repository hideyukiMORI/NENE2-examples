<?php

declare(strict_types=1);

namespace BulkUpdateLog\Task;

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
