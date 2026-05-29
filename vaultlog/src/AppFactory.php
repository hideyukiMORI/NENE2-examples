<?php

declare(strict_types=1);

namespace VaultLog;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;
use VaultLog\Vault\RouteRegistrar;
use VaultLog\Vault\VaultRepository;

class AppFactory
{
    public static function createSqlite(
        string $dbFile,
        string $adminKey = '',
        string $hmacSecret = '',
    ): RequestHandlerInterface {
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
        return self::build($dbConfig, $adminKey, $hmacSecret);
    }

    private static function build(
        DatabaseConfig $dbConfig,
        string $adminKey,
        string $hmacSecret,
    ): RequestHandlerInterface {
        $factory = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17 = new Psr17Factory();
        $repo = new VaultRepository($executor, $hmacSecret);
        $json = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($repo, $json, $adminKey);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
