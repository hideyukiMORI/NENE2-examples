<?php

declare(strict_types=1);

namespace CacheLog;

use CacheLog\Cache\InMemoryCache;
use CacheLog\Cache\ProductRepository;
use CacheLog\Cache\RouteRegistrar;
use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;

class AppFactory
{
    /**
     * @param \Closure(): int|null $clock
     */
    public static function createSqlite(string $dbFile, ?\Closure $clock = null): RequestHandlerInterface
    {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $dbFile,
            user: '',
            password: '',
            charset: '',
        );
        return self::build($dbConfig, $clock);
    }

    /**
     * @param \Closure(): int|null $clock
     */
    private static function build(DatabaseConfig $dbConfig, ?\Closure $clock = null): RequestHandlerInterface
    {
        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $cache     = new InMemoryCache(defaultTtl: 60, clock: $clock);
        $repo      = new ProductRepository($executor);
        $json      = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($repo, $cache, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn(Router $router) => $registrar->register($router)],
        ))->create();
    }
}
