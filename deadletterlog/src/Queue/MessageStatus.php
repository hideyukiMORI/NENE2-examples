<?php

declare(strict_types=1);

namespace DeadLetterLog\Queue;

enum MessageStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Dead = 'dead';
}
