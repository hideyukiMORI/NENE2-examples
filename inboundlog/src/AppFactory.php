<?php

declare(strict_types=1);

namespace InboundLog;

use InboundLog\Inbound\RouteRegistrar;
use InboundLog\Inbound\WebhookRepository;
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
        return self::create(new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $dbFile,
            user: '',
            password: '',
            charset: '',
        ));
    }

    public static function createMysql(
        string $host,
        int $port,
        string $name,
        string $user,
        string $password,
    ): RequestHandlerInterface {
        return self::create(new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'mysql',
            host: $host,
            port: $port,
            name: $name,
            user: $user,
            password: $password,
            charset: 'utf8mb4',
        ));
    }

    private static function create(DatabaseConfig $dbConfig): RequestHandlerInterface
    {
        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $repo      = new WebhookRepository($executor);
        $json      = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($repo, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
