<?php

declare(strict_types=1);

namespace DedupLog;

use DedupLog\Dedup\IdempotencyRepository;
use DedupLog\Dedup\RouteRegistrar;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use Psr\Http\Server\RequestHandlerInterface;

final class AppFactory
{
    public static function createSqlite(string $dbFile): RequestHandlerInterface
    {
        $pdo = new PDO('sqlite:' . $dbFile, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return self::create($pdo);
    }

    private static function create(PDO $pdo): RequestHandlerInterface
    {
        $psr17     = new Psr17Factory();
        $repo      = new IdempotencyRepository($pdo);
        $json      = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($repo, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
