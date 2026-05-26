<?php

declare(strict_types=1);

namespace PinLog;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PinLog\Pin\PinRepository;
use PinLog\Pin\RouteRegistrar;

final class AppFactory
{
    public static function createSqliteApp(?PDO $pdo = null): Router
    {
        if ($pdo !== null) {
            $factory = new class ($pdo) implements DatabaseConnectionFactoryInterface {
                public function __construct(private readonly PDO $pdo)
                {
                }
                public function create(): PDO
                {
                    return $this->pdo;
                }
            };
        } else {
            $factory = new class () implements DatabaseConnectionFactoryInterface {
                public function create(): PDO
                {
                    $pdo = new PDO('sqlite::memory:');
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $pdo->exec('PRAGMA foreign_keys = ON');
                    return $pdo;
                }
            };
        }

        $executor = new PdoDatabaseQueryExecutor($factory);
        $repository = new PinRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();

        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();

        return $router;
    }
}
