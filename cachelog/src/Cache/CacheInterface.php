<?php

declare(strict_types=1);

namespace CacheLog\Cache;

interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttl = 60): void;

    public function delete(string $key): void;

    public function flush(): void;

    /** @return array{hits: int, misses: int, size: int} */
    public function stats(): array;
}
