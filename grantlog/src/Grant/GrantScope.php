<?php

declare(strict_types=1);

namespace Grantlog\Grant;

enum GrantScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    /** Whether this scope is at least as powerful as $required. */
    public function satisfies(self $required): bool
    {
        $rank = [self::Read->value => 0, self::Write->value => 1, self::Admin->value => 2];

        return $rank[$this->value] >= $rank[$required->value];
    }
}
