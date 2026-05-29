<?php

declare(strict_types=1);

namespace ReorderLog;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Database\PdoDatabaseTransactionManager;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;
use ReorderLog\Reorder\ReorderRepository;
use ReorderLog\Reorder\ReorderService;
use ReorderLog\Reorder\RouteRegistrar;

class AppFactory
{
    public static function createSqlite(string $dbFile): RequestHandlerInterface
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
        return self::build($dbConfig);
    }

    public static function createMysql(
        string $host,
        string $name,
        string $user,
        string $password,
        int $port = 3306,
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
        return self::build($dbConfig);
    }

    private static function build(DatabaseConfig $dbConfig): RequestHandlerInterface
    {
        $factory = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $txManager = new PdoDatabaseTransactionManager($factory);
        $psr17 = new Psr17Factory();
        $repo = new ReorderRepository($executor);
        $service = new ReorderService($txManager);
        $json = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($repo, $service, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
