<?php

declare(strict_types=1);

namespace Group\Group;

enum MemberRole: string
{
    case Owner  = 'owner';
    case Admin  = 'admin';
    case Member = 'member';

    public function canManageMembers(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    public function canChangeRoles(): bool
    {
        return $this === self::Owner;
    }
}
