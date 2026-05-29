<?php

declare(strict_types=1);

namespace VerifyLog;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;
use VerifyLog\Verification\RouteRegistrar;
use VerifyLog\Verification\VerificationRepository;

class AppFactory
{
    /**
     * @param (\Closure(): string)|null $codeGenerator returns the 6-digit code;
     *        defaults to a CSPRNG generator. Tests inject a deterministic one.
     */
    public static function createSqlite(string $dbFile, ?\Closure $codeGenerator = null): RequestHandlerInterface
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
        $repo = new VerificationRepository($executor);
        $json = new JsonResponseFactory($psr17, $psr17);
        $generator = $codeGenerator ?? static fn (): string => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $registrar = new RouteRegistrar($repo, $json, $generator);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
