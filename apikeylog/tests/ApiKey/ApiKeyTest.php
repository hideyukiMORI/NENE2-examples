<?php

declare(strict_types=1);

namespace ApiKey\Tests\ApiKey;

use ApiKey\ApiKey\ApiKeyGenerator;
use ApiKey\ApiKey\ApiKeyRepository;
use ApiKey\ApiKey\ApiKeyScope;
use ApiKey\ApiKey\RouteRegistrar;
use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiKeyTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;
    private ApiKeyRepository $repo;
    private ApiKeyGenerator $generator;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/apikeylog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig = new DatabaseConfig(
            url:         null,
            environment: 'test',
            adapter:     'sqlite',
            host:        '',
            port:        1,
            name:        $this->dbFile,
            user:        '',
            password:    '',
            charset:     '',
        );

        $factory         = new PdoConnectionFactory($dbConfig);
        $executor        = new PdoDatabaseQueryExecutor($factory);
        $psr17           = new Psr17Factory();
        $json            = new JsonResponseFactory($psr17, $psr17);
        $problems        = new ProblemDetailsResponseFactory($psr17, $psr17);
        $this->generator = new ApiKeyGenerator();
        $this->repo      = new ApiKeyRepository($executor, $this->generator);

        $registrar = new RouteRegistrar($this->repo, $json, $problems);

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
        ))->create();
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function req(string $method, string $uri, mixed $body = null, string $apiKey = ''): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')
                       ->withBody((new Psr17Factory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        if ($apiKey !== '') {
            $req = $req->withHeader('X-Api-Key', $apiKey);
        }

        return $this->app->handle($req);
    }

    private function decode(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- POST /keys ---

    public function testCreateKeyReturns201WithRawKey(): void
    {
        $res  = $this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'read']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertArrayHasKey('key', $body);
        self::assertStringStartsWith('nk_', $body['key']);
        self::assertSame('read', $body['scope']);
        self::assertArrayNotHasKey('key_hash', $body);
    }

    public function testCreateKeyMissingOwnerReturns422(): void
    {
        $res = $this->req('POST', '/keys', ['scope' => 'read']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testCreateKeyInvalidScopeReturns422(): void
    {
        $res = $this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'superadmin']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testCreateKeyWithExpiry(): void
    {
        $res  = $this->req('POST', '/keys', [
            'owner_id'   => 1,
            'scope'      => 'write',
            'expires_at' => '2099-12-31 23:59:59',
        ]);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('2099-12-31 23:59:59', $body['expires_at']);
    }

    // --- GET /keys?owner_id=N ---

    public function testListKeysForOwner(): void
    {
        $this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'read']);
        $this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'write']);
        $this->req('POST', '/keys', ['owner_id' => 2, 'scope' => 'read']);

        $res  = $this->req('GET', '/keys?owner_id=1');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(2, $body['keys']);
        // key_hash must not be exposed
        foreach ($body['keys'] as $k) {
            self::assertArrayNotHasKey('key_hash', $k);
            self::assertArrayNotHasKey('key', $k);
        }
    }

    public function testListKeysMissingOwnerReturns422(): void
    {
        $res = $this->req('GET', '/keys');
        self::assertSame(422, $res->getStatusCode());
    }

    // --- POST /keys/{id}/revoke ---

    public function testRevokeKey(): void
    {
        $create = $this->decode($this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'read']));
        $res    = $this->req('POST', '/keys/1/revoke', ['owner_id' => 1]);
        $body   = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertNotNull($body['revoked_at']);
    }

    public function testRevokedKeyCannotAuthenticate(): void
    {
        $create = $this->decode($this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'read']));
        $rawKey = $create['key'];

        $this->req('POST', '/keys/1/revoke', ['owner_id' => 1]);

        $res = $this->req('GET', '/resource/read', null, $rawKey);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testRevokeWrongOwnerReturns404(): void
    {
        $this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'read']);
        $res = $this->req('POST', '/keys/1/revoke', ['owner_id' => 2]);
        self::assertSame(404, $res->getStatusCode());
    }

    // --- POST /keys/{id}/rotate ---

    public function testRotateKeyRevokesOldAndCreatesNew(): void
    {
        $create = $this->decode($this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'write']));
        $oldKey = $create['key'];

        $res  = $this->req('POST', '/keys/1/rotate', ['owner_id' => 1]);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertStringStartsWith('nk_', $body['key']);
        self::assertNotSame($oldKey, $body['key']);
        self::assertSame('write', $body['scope']);

        // Old key is now revoked
        $readRes = $this->req('GET', '/resource/read', null, $oldKey);
        self::assertSame(401, $readRes->getStatusCode());
    }

    public function testRotateAlreadyRevokedKeyReturns404(): void
    {
        $this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'read']);
        $this->req('POST', '/keys/1/revoke', ['owner_id' => 1]);

        $res = $this->req('POST', '/keys/1/rotate', ['owner_id' => 1]);
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Protected resource endpoints ---

    public function testReadScopeKeyAccessesReadEndpoint(): void
    {
        $create = $this->decode($this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'read']));
        $res    = $this->req('GET', '/resource/read', null, $create['key']);

        self::assertSame(200, $res->getStatusCode());
    }

    public function testReadScopeKeyCannotAccessWriteEndpoint(): void
    {
        $create = $this->decode($this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'read']));
        $res    = $this->req('POST', '/resource/write', null, $create['key']);

        self::assertSame(403, $res->getStatusCode());
    }

    public function testWriteScopeKeyCanAccessReadAndWrite(): void
    {
        $create = $this->decode($this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'write']));
        $key    = $create['key'];

        self::assertSame(200, $this->req('GET', '/resource/read', null, $key)->getStatusCode());
        self::assertSame(200, $this->req('POST', '/resource/write', null, $key)->getStatusCode());
        self::assertSame(403, $this->req('DELETE', '/resource/admin', null, $key)->getStatusCode());
    }

    public function testAdminScopeKeyCanAccessAll(): void
    {
        $create = $this->decode($this->req('POST', '/keys', ['owner_id' => 1, 'scope' => 'admin']));
        $key    = $create['key'];

        self::assertSame(200, $this->req('GET', '/resource/read', null, $key)->getStatusCode());
        self::assertSame(200, $this->req('POST', '/resource/write', null, $key)->getStatusCode());
        self::assertSame(200, $this->req('DELETE', '/resource/admin', null, $key)->getStatusCode());
    }

    public function testMissingApiKeyReturns401(): void
    {
        $res = $this->req('GET', '/resource/read');
        self::assertSame(401, $res->getStatusCode());
    }

    public function testInvalidApiKeyReturns401(): void
    {
        $res = $this->req('GET', '/resource/read', null, 'nk_invalidkeyvalue');
        self::assertSame(401, $res->getStatusCode());
    }

    public function testExpiredKeyReturns401(): void
    {
        $create = $this->decode($this->req('POST', '/keys', [
            'owner_id'   => 1,
            'scope'      => 'read',
            'expires_at' => '2020-01-01 00:00:00',
        ]));

        $res = $this->req('GET', '/resource/read', null, $create['key']);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testMeEndpointReturnsKeyMetadata(): void
    {
        $create = $this->decode($this->req('POST', '/keys', [
            'owner_id'    => 5,
            'scope'       => 'read',
            'description' => 'My test key',
        ]));

        $res  = $this->req('GET', '/auth/me', null, $create['key']);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(5, $body['owner_id']);
        self::assertSame('My test key', $body['description']);
        self::assertArrayNotHasKey('key_hash', $body);
    }
}
