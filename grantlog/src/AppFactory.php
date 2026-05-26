<?php

declare(strict_types=1);

namespace Grantlog;

use Grantlog\Grant\GrantRepository;
use Grantlog\Grant\RouteRegistrar;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;

final class AppFactory
{
    public static function create(DatabaseConnectionFactoryInterface $connectionFactory): Router
    {
        $executor   = new PdoDatabaseQueryExecutor($connectionFactory);
        $repository = new GrantRepository($executor);
        $psr17      = new Psr17Factory();
        $response   = new JsonResponseFactory($psr17, $psr17);
        $router     = new Router();
        $registrar  = new RouteRegistrar($router, $repository, $response);
        $registrar->register();

        return $router;
    }
}
