<?php

declare(strict_types=1);

namespace Bookmark;

use Bookmark\Bookmark\BookmarkRepository;
use Bookmark\Bookmark\RouteRegistrar;
use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;

final class AppFactory
{
    public static function createSqliteApp(string $dbPath): RequestHandlerInterface
    {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $dbPath,
            user: '',
            password: '',
            charset: '',
        );

        return self::buildApp($dbConfig);
    }

    public static function createMysqlApp(
        string $host,
        int $port,
        string $database,
        string $user,
        string $password,
    ): RequestHandlerInterface {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'mysql',
            host: $host,
            port: $port,
            name: $database,
            user: $user,
            password: $password,
            charset: 'utf8mb4',
        );

        return self::buildApp($dbConfig);
    }

    private static function buildApp(DatabaseConfig $dbConfig): RequestHandlerInterface
    {
        $factory  = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17    = new Psr17Factory();
        $json     = new JsonResponseFactory($psr17, $psr17);

        $repo      = new BookmarkRepository($executor);
        $registrar = new RouteRegistrar($repo, $json);

        return new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn(Router $r) => $registrar->register($r)],
        )->create();
    }
}
