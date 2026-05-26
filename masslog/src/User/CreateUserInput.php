<?php

declare(strict_types=1);

namespace Mass\User;

/**
 * Explicit DTO for user creation — only name and email are accepted from user input.
 *
 * role and is_active are intentionally excluded: they must be set by server-side
 * business logic, never from the request body. This is the mass-assignment defence.
 */
final readonly class CreateUserInput
{
    public function __construct(
        public string $name,
        public string $email,
    ) {
    }
}
