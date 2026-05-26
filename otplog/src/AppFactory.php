<?php

declare(strict_types=1);

namespace OtpLog;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use OtpLog\Otp\OtpRepository;
use OtpLog\Otp\RouteRegistrar;

class AppFactory
{
    public static function create(DatabaseConnectionFactoryInterface $connectionFactory): Router
    {
        $executor = new PdoDatabaseQueryExecutor($connectionFactory);
        $repository = new OtpRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();
        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();
        return $router;
    }

    public static function createMysql(string $host, int $port, string $name, string $user, string $password): Router
    {
        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'mysql',
            host: $host,
            port: $port,
            name: $name,
            user: $user,
            password: $password,
            charset: 'utf8mb4',
        );
        $factory = new PdoConnectionFactory($dbConfig);
        return self::create($factory);
    }
}
