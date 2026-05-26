<?php

declare(strict_types=1);

namespace ImportLog;

use ImportLog\Import\CsvImporter;
use ImportLog\Import\ImportRepository;
use ImportLog\Import\RouteRegistrar;
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
        return self::build($dbConfig);
    }

    private static function build(DatabaseConfig $dbConfig): RequestHandlerInterface
    {
        $factory = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17 = new Psr17Factory();
        $repo = new ImportRepository($executor);
        $importer = new CsvImporter();
        $json = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($repo, $importer, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn(Router $router) => $registrar->register($router)],
        ))->create();
    }
}
