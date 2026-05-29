<?php

declare(strict_types=1);

namespace CqrsLog;

use CqrsLog\Order\Command\OrderCommandHandler;
use CqrsLog\Order\Query\OrderQueryHandler;
use CqrsLog\Order\RouteRegistrar;
use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Database\PdoDatabaseTransactionManager;
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

        $factory = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $txManager = new PdoDatabaseTransactionManager($factory);
        $psr17 = new Psr17Factory();

        $commands = new OrderCommandHandler($executor); // used for single-statement commands
        $queries = new OrderQueryHandler($executor);    // read side
        $json = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($commands, $queries, $txManager, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
