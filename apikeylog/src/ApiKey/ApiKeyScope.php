<?php

declare(strict_types=1);

namespace ApiKey\ApiKey;

enum ApiKeyScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function allows(self $required): bool
    {
        return match ($required) {
            self::Read  => true,
            self::Write => $this === self::Write || $this === self::Admin,
            self::Admin => $this === self::Admin,
        };
    }
}
