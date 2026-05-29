<?php

declare(strict_types=1);

namespace AssetLog;

use AssetLog\Asset\AssetRepository;
use AssetLog\Asset\RouteRegistrar;
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
    public static function createSqlite(string $dbFile, string $adminKey = ''): RequestHandlerInterface
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
        return self::build($dbConfig, $adminKey);
    }

    public static function createMysql(
        string $host,
        string $name,
        string $user,
        string $password,
        int $port = 3306,
        string $adminKey = '',
    ): RequestHandlerInterface {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'mysql',
            host: $host,
            port: $port,
            name: $name,
            user: $user,
            password: $password,
            charset: 'utf8mb4',
        );
        return self::build($dbConfig, $adminKey);
    }

    private static function build(DatabaseConfig $dbConfig, string $adminKey): RequestHandlerInterface
    {
        $factory = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17 = new Psr17Factory();
        $repo = new AssetRepository($executor);
        $json = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($repo, $json, $adminKey);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
