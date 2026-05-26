<?php

declare(strict_types=1);

namespace Relatedlog\Article;

enum RelationType: string
{
    case Related   = 'related';
    case Sequel    = 'sequel';
    case Prequel   = 'prequel';
    case Reference = 'reference';

    /**
     * Returns the inverse relation type.
     *
     * - sequel ↔ prequel  (if A is a sequel of B, then B is a prequel of A)
     * - related ↔ related (symmetric)
     * - reference ↔ reference (symmetric — referenced-by is stored separately if needed)
     */
    public function inverse(): self
    {
        return match ($this) {
            self::Sequel   => self::Prequel,
            self::Prequel  => self::Sequel,
            self::Related  => self::Related,
            self::Reference => self::Reference,
        };
    }
}
