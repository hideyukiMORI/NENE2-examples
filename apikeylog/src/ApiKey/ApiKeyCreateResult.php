<?php

declare(strict_types=1);

namespace ApiKey\ApiKey;

/**
 * Wraps a newly created ApiKey together with its raw (unhashed) key value.
 * The raw key is only available at creation time and must not be persisted.
 */
final readonly class ApiKeyCreateResult
{
    public function __construct(
        public ApiKey $key,
        public string $rawKey,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->key->toArray(), ['key' => $this->rawKey]);
    }
}
