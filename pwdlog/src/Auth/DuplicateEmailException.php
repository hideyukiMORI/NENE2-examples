<?php

declare(strict_types=1);

namespace Pwd\Auth;

final class DuplicateEmailException extends \RuntimeException
{
    public function __construct(string $email)
    {
        parent::__construct("Email already registered: {$email}");
    }
}
