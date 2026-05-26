<?php

declare(strict_types=1);

namespace Lockout\Tests\Lockout;

use Lockout\Lockout\LockoutRepository;
use Lockout\Lockout\RouteRegistrar;
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

final class LockoutTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;
    private LockoutRepository $repo;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/lockoutlog-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $this->dbFile,
            user: '',
            password: '',
            charset: '',
        );

        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $this->repo = new LockoutRepository($executor);
        $registrar = new RouteRegistrar($this->repo, $json, $problems);

        $this->app = new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn(Router $r) => $registrar->register($r)],
        )->create();
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
                       ->withBody(new Psr17Factory()->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($req);
    }

    private function decode(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function createUser(string $email = 'alice@example.com', string $pass = 'correct-pass'): void
    {
        $this->req('POST', '/users', ['email' => $email, 'password' => $pass]);
    }

    // --- User creation ---

    public function testCreateUserReturns201(): void
    {
        $res  = $this->req('POST', '/users', ['email' => 'alice@example.com', 'password' => 'secret123']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('alice@example.com', $body['email']);
        self::assertArrayNotHasKey('password_hash', $body);
    }

    public function testCreateUserMissingEmailReturns422(): void
    {
        $res = $this->req('POST', '/users', ['email' => '', 'password' => 'pass']);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- Login ---

    public function testSuccessfulLoginReturns200(): void
    {
        $this->createUser();
        $res = $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'correct-pass']);
        self::assertSame(200, $res->getStatusCode());
    }

    public function testWrongPasswordReturns401(): void
    {
        $this->createUser();
        $res = $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong-pass']);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testUnknownEmailReturns401(): void
    {
        $res = $this->req('POST', '/auth/login', ['email' => 'nobody@example.com', 'password' => 'pass']);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testLoginMissingFieldsReturns422(): void
    {
        $res = $this->req('POST', '/auth/login', ['email' => '', 'password' => 'pass']);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- Lockout ---

    public function testFiveFailuresTriggersLockout(): void
    {
        $this->createUser();

        for ($i = 0; $i < 5; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        }

        $res = $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        self::assertSame(423, $res->getStatusCode());
    }

    public function testFourFailuresDoNotLock(): void
    {
        $this->createUser();

        for ($i = 0; $i < 4; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        }

        $res = $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testLockedAccountBlocksCorrectPassword(): void
    {
        $this->createUser();

        for ($i = 0; $i < 5; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        }

        $res = $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'correct-pass']);
        self::assertSame(423, $res->getStatusCode());
    }

    public function testSuccessfulLoginResetsFailureCount(): void
    {
        $this->createUser();

        // 4 failures
        for ($i = 0; $i < 4; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        }

        // Successful login — should reset counter
        $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'correct-pass']);

        // 4 more failures — should not lock yet
        for ($i = 0; $i < 4; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        }

        $res = $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testLockedAccountHasLockedUntilInStatus(): void
    {
        $this->createUser();

        for ($i = 0; $i < 5; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        }

        $res  = $this->req('GET', '/auth/status/alice@example.com');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($body['is_locked']);
        self::assertNotNull($body['locked_until']);
    }

    // --- Status endpoint ---

    public function testStatusForNewAccountIsNotLocked(): void
    {
        $this->createUser();
        $res  = $this->req('GET', '/auth/status/alice@example.com');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertFalse($body['is_locked']);
        self::assertSame(0, $body['failed_count']);
    }

    public function testStatusReflectsFailureCount(): void
    {
        $this->createUser();
        $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);
        $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);

        $res  = $this->req('GET', '/auth/status/alice@example.com');
        $body = $this->decode($res);

        self::assertSame(2, $body['failed_count']);
    }

    // --- Response sanitization ---

    public function testLoginResponseDoesNotExposePasswordHash(): void
    {
        $this->createUser();
        $res  = $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'correct-pass']);
        $body = $this->decode($res);

        self::assertArrayNotHasKey('password_hash', $body);
    }

    // --- User enumeration prevention ---

    public function testUnknownAndWrongPasswordReturnSameStatus(): void
    {
        $this->createUser();

        $wrongPass   = $this->req('POST', '/auth/login', ['email' => 'alice@example.com', 'password' => 'nope']);
        $unknownUser = $this->req('POST', '/auth/login', ['email' => 'nobody@example.com', 'password' => 'nope']);

        self::assertSame($wrongPass->getStatusCode(), $unknownUser->getStatusCode());
    }
}
