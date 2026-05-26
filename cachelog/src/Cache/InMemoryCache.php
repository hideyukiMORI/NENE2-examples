<?php

declare(strict_types=1);

namespace CacheLog\Cache;

class InMemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<string, int> */
    private array $expiry = [];

    private int $hits   = 0;
    private int $misses = 0;

    /**
     * @param \Closure(): int $clock Injected for testing; defaults to time()
     */
    public function __construct(
        private readonly int $defaultTtl = 60,
        private readonly ?\Closure $clock = null,
    ) {}

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->store)) {
            $this->misses++;
            return null;
        }
        if ($this->expiry[$key] <= $this->now()) {
            unset($this->store[$key], $this->expiry[$key]);
            $this->misses++;
            return null;
        }
        $this->hits++;
        return $this->store[$key];
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $effectiveTtl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $this->store[$key]  = $value;
        $this->expiry[$key] = $this->now() + $effectiveTtl;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key], $this->expiry[$key]);
    }

    public function flush(): void
    {
        $this->store  = [];
        $this->expiry = [];
    }

    public function stats(): array
    {
        return [
            'hits'   => $this->hits,
            'misses' => $this->misses,
            'size'   => count($this->store),
        ];
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
