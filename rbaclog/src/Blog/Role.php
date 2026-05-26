<?php

declare(strict_types=1);

namespace Rbac\Blog;

enum Role: string
{
    case User  = 'user';
    case Admin = 'admin';
}
