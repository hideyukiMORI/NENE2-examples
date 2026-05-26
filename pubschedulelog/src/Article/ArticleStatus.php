<?php

declare(strict_types=1);

namespace Pubschedulelog\Article;

enum ArticleStatus: string
{
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived  = 'archived';

    /** Transitions allowed from this status. */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft     => in_array($next, [self::Scheduled, self::Published, self::Archived], true),
            self::Scheduled => in_array($next, [self::Published, self::Draft, self::Archived], true),
            self::Published => $next === self::Archived,
            self::Archived  => false,
        };
    }
}
