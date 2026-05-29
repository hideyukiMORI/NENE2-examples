<?php

declare(strict_types=1);

namespace ProjTrack;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use ProjTrack\Project\ProjectRepository;
use ProjTrack\Project\RouteRegistrar;
use ProjTrack\Task\TaskRepository;
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
        $psr17 = new Psr17Factory();
        $projects = new ProjectRepository($executor);
        $tasks = new TaskRepository($executor);
        $json = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($projects, $tasks, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
