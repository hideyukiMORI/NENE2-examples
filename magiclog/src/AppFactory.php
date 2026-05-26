<?php

declare(strict_types=1);

namespace MagicLog;

use MagicLog\Magic\MagicRepository;
use MagicLog\Magic\RouteRegistrar;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use PDO;
use Psr\Http\Message\ResponseFactoryInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

final class AppFactory
{
    public static function createSqliteApp(?string $dbPath = null): Router
    {
        $path = $dbPath ?? ':memory:';
        $factory = new class ($path) implements \Nene2\Database\DatabaseConnectionFactoryInterface {
            public function __construct(private readonly string $path)
            {
            }
            public function create(): PDO
            {
                $pdo = new PDO('sqlite:' . $this->path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA foreign_keys = ON');
                return $pdo;
            }
        };

        return self::buildApp($factory);
    }

    private static function buildApp(\Nene2\Database\DatabaseConnectionFactoryInterface $factory): Router
    {
        $executor = new PdoDatabaseQueryExecutor($factory);
        $repository = new MagicRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();

        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();

        return $router;
    }
}
