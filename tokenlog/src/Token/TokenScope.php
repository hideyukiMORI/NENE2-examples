<?php

declare(strict_types=1);

namespace Token\Token;

enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}
