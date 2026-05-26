<?php

declare(strict_types=1);

namespace WishlistLog;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use WishlistLog\Wishlist\RouteRegistrar;
use WishlistLog\Wishlist\WishlistRepository;

class AppFactory
{
    public static function create(DatabaseConnectionFactoryInterface $connectionFactory): Router
    {
        $executor = new PdoDatabaseQueryExecutor($connectionFactory);
        $repository = new WishlistRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();
        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();
        return $router;
    }
}
