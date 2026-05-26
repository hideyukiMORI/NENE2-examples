<?php

declare(strict_types=1);

namespace Lock\Tests\Lock;

use Lock\Lock\LockRepository;
use Lock\Lock\RouteRegistrar;
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

final class DistributedLockTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/distlocklog-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $repo      = new LockRepository($executor);

        $registrar = new RouteRegistrar($repo, $json, $problems);

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

    private function req(string $method, string $uri, mixed $body = null): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')
                       ->withBody((new Psr17Factory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($req);
    }

    private function decode(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- Acquire ---

    public function testAcquireNewResourceSucceeds(): void
    {
        $res  = $this->req('POST', '/locks/job-42', ['owner' => 'worker-1', 'ttl_seconds' => 30]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($body['acquired']);
        self::assertSame('job-42', $body['lock']['resource']);
        self::assertSame('worker-1', $body['lock']['owner']);
    }

    public function testAcquireSameOwnerIsIdempotent(): void
    {
        $this->req('POST', '/locks/job-99', ['owner' => 'worker-1', 'ttl_seconds' => 30]);
        $res  = $this->req('POST', '/locks/job-99', ['owner' => 'worker-1', 'ttl_seconds' => 60]);
        $body = $this->decode($res);

        self::assertTrue($body['acquired']);
        self::assertSame('worker-1', $body['lock']['owner']);
    }

    public function testAcquireContestedLockReturnsFalse(): void
    {
        $this->req('POST', '/locks/resource-x', ['owner' => 'worker-1', 'ttl_seconds' => 30]);
        $res  = $this->req('POST', '/locks/resource-x', ['owner' => 'worker-2', 'ttl_seconds' => 30]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertFalse($body['acquired']);
        self::assertArrayNotHasKey('lock', $body);
    }

    public function testAcquireMissingOwnerReturns422(): void
    {
        $res = $this->req('POST', '/locks/res', ['ttl_seconds' => 30]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testAcquireMissingTtlReturns422(): void
    {
        $res = $this->req('POST', '/locks/res', ['owner' => 'w1']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testAcquireZeroTtlReturns422(): void
    {
        $res = $this->req('POST', '/locks/res', ['owner' => 'w1', 'ttl_seconds' => 0]);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- Get lock state ---

    public function testGetActiveLockReturns200(): void
    {
        $this->req('POST', '/locks/state-check', ['owner' => 'w1', 'ttl_seconds' => 60]);
        $res  = $this->req('GET', '/locks/state-check');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('state-check', $body['resource']);
        self::assertSame('w1', $body['owner']);
    }

    public function testGetNonExistentLockReturns404(): void
    {
        $res = $this->req('GET', '/locks/ghost-resource');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Release ---

    public function testReleaseWithCorrectOwnerReturns204(): void
    {
        $this->req('POST', '/locks/to-release', ['owner' => 'w1', 'ttl_seconds' => 30]);
        $res = $this->req('DELETE', '/locks/to-release', ['owner' => 'w1']);
        self::assertSame(204, $res->getStatusCode());
    }

    public function testReleaseWithWrongOwnerReturns403(): void
    {
        $this->req('POST', '/locks/contested', ['owner' => 'w1', 'ttl_seconds' => 30]);
        $res = $this->req('DELETE', '/locks/contested', ['owner' => 'w2']);
        self::assertSame(403, $res->getStatusCode());
    }

    public function testReleaseNonExistentLockReturns404(): void
    {
        $res = $this->req('DELETE', '/locks/no-such-lock', ['owner' => 'w1']);
        self::assertSame(404, $res->getStatusCode());
    }

    public function testAfterReleaseAnotherOwnerCanAcquire(): void
    {
        $this->req('POST', '/locks/handover', ['owner' => 'w1', 'ttl_seconds' => 30]);
        $this->req('DELETE', '/locks/handover', ['owner' => 'w1']);

        $res  = $this->req('POST', '/locks/handover', ['owner' => 'w2', 'ttl_seconds' => 30]);
        $body = $this->decode($res);

        self::assertTrue($body['acquired']);
        self::assertSame('w2', $body['lock']['owner']);
    }

    // --- Expired lock claim ---

    public function testExpiredLockCanBeClaimedByNewOwner(): void
    {
        // Acquire with a very short TTL in the past (simulate expired)
        $dbFile = $this->dbFile;
        $pdo    = new \PDO('sqlite:' . $dbFile);
        $past   = (new \DateTimeImmutable())->modify('-10 seconds')->format('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at) VALUES ('expired-res', 'old-worker', '{$past}', '{$past}')");
        unset($pdo);

        $res  = $this->req('POST', '/locks/expired-res', ['owner' => 'new-worker', 'ttl_seconds' => 30]);
        $body = $this->decode($res);

        self::assertTrue($body['acquired']);
        self::assertSame('new-worker', $body['lock']['owner']);
    }

    // --- Renew ---

    public function testRenewWithCorrectOwnerExtendsTtl(): void
    {
        $this->req('POST', '/locks/renewable', ['owner' => 'w1', 'ttl_seconds' => 30]);
        $res  = $this->req('POST', '/locks/renewable/renew', ['owner' => 'w1', 'ttl_seconds' => 300]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('renewable', $body['resource']);
    }

    public function testRenewWithWrongOwnerReturns409(): void
    {
        $this->req('POST', '/locks/no-renew', ['owner' => 'w1', 'ttl_seconds' => 30]);
        $res = $this->req('POST', '/locks/no-renew/renew', ['owner' => 'w2', 'ttl_seconds' => 60]);
        self::assertSame(409, $res->getStatusCode());
    }

    public function testRenewExpiredLockReturns409(): void
    {
        $dbFile = $this->dbFile;
        $pdo    = new \PDO('sqlite:' . $dbFile);
        $past   = (new \DateTimeImmutable())->modify('-10 seconds')->format('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at) VALUES ('exp-renew', 'w1', '{$past}', '{$past}')");
        unset($pdo);

        $res = $this->req('POST', '/locks/exp-renew/renew', ['owner' => 'w1', 'ttl_seconds' => 60]);
        self::assertSame(409, $res->getStatusCode());
    }
}
