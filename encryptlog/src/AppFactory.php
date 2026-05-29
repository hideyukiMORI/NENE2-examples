<?php

declare(strict_types=1);

namespace EncryptLog;

use EncryptLog\Vault\FieldCrypto;
use EncryptLog\Vault\RouteRegistrar;
use EncryptLog\Vault\VaultRepository;
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
    /**
     * @param string $encKey   32-byte AES-256 key (defaults to a dev key)
     * @param string $indexKey separate blind-index HMAC key
     */
    public static function createSqlite(
        string $dbFile,
        string $encKey = '',
        string $indexKey = '',
    ): RequestHandlerInterface {
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

        // Dev fallback keys (32 bytes). Production must inject real secrets.
        $encKey = $encKey !== '' ? $encKey : str_repeat('e', 32);
        $indexKey = $indexKey !== '' ? $indexKey : str_repeat('i', 32);

        $factory = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17 = new Psr17Factory();
        $repo = new VaultRepository($executor);
        $crypto = new FieldCrypto($encKey, $indexKey);
        $json = new JsonResponseFactory($psr17, $psr17);
        $registrar = new RouteRegistrar($repo, $crypto, $json);

        return (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $router) => $registrar->register($router)],
        ))->create();
    }
}
