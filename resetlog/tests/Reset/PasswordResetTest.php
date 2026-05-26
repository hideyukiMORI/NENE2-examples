<?php

declare(strict_types=1);

namespace Reset\Tests\Reset;

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
use Reset\Reset\ResetRepository;
use Reset\Reset\RouteRegistrar;

final class PasswordResetTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/resetlog-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $repo      = new ResetRepository($executor);
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

    // --- User registration ---

    public function testCreateUserReturns201(): void
    {
        $res  = $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice', 'password' => 'secret123']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('alice@example.com', $body['email']);
        self::assertArrayNotHasKey('password_hash', $body);
    }

    public function testCreateUserShortPasswordReturns422(): void
    {
        $res = $this->req('POST', '/users', ['email' => 'b@example.com', 'name' => 'Bob', 'password' => 'short']);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- Request reset ---

    public function testRequestResetForRegisteredEmailReturns202WithToken(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice', 'password' => 'secret123']);
        $res  = $this->req('POST', '/password-reset', ['email' => 'alice@example.com']);
        $body = $this->decode($res);

        self::assertSame(202, $res->getStatusCode());
        self::assertSame('pending', $body['status']);
        self::assertNotEmpty($body['token']);
    }

    public function testRequestResetForUnknownEmailReturns202WithoutToken(): void
    {
        $res  = $this->req('POST', '/password-reset', ['email' => 'ghost@example.com']);
        $body = $this->decode($res);

        self::assertSame(202, $res->getStatusCode(), 'Unknown email must still return 202 to prevent enumeration');
        self::assertSame('pending', $body['status']);
        self::assertArrayNotHasKey('token', $body, 'Unknown email must not expose a token');
    }

    public function testResetTokenHasSufficientEntropy(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice', 'password' => 'secret123']);
        $res  = $this->req('POST', '/password-reset', ['email' => 'alice@example.com']);
        $body = $this->decode($res);

        self::assertSame(64, strlen($body['token']), 'Token must be 64 hex chars (256-bit entropy)');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['token']);
    }

    // --- Get reset status ---

    public function testGetValidResetReturns200(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice', 'password' => 'secret123']);
        $token = $this->decode($this->req('POST', '/password-reset', ['email' => 'alice@example.com']))['token'];

        $res = $this->req('GET', "/password-reset/{$token}");
        self::assertSame(200, $res->getStatusCode());
    }

    public function testGetNonExistentTokenReturns404(): void
    {
        $res = $this->req('GET', '/password-reset/' . bin2hex(random_bytes(32)));
        self::assertSame(404, $res->getStatusCode());
    }

    public function testGetExpiredTokenReturns410(): void
    {
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $past = (new \DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO users (email, name, password_hash, created_at) VALUES ('x@example.com','X','hash','{$past}')");
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $pdo->exec("INSERT INTO password_resets (user_id, token_hash, used_at, expires_at, created_at) VALUES (1,'{$tokenHash}',NULL,'{$past}','{$past}')");
        unset($pdo);

        $res = $this->req('GET', "/password-reset/{$rawToken}");
        self::assertSame(410, $res->getStatusCode());
    }

    public function testGetUsedTokenReturns409(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice', 'password' => 'secret123']);
        $token = $this->decode($this->req('POST', '/password-reset', ['email' => 'alice@example.com']))['token'];
        $this->req('POST', "/password-reset/{$token}", ['password' => 'newpassword1']);

        $res = $this->req('GET', "/password-reset/{$token}");
        self::assertSame(409, $res->getStatusCode());
    }

    // --- Complete reset ---

    public function testCompleteResetChangesPassword(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice', 'password' => 'oldpassword']);
        $token = $this->decode($this->req('POST', '/password-reset', ['email' => 'alice@example.com']))['token'];

        $res  = $this->req('POST', "/password-reset/{$token}", ['password' => 'newpassword1']);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('completed', $body['status']);
    }

    public function testCompleteResetMarksTokenUsed(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice', 'password' => 'oldpassword']);
        $token = $this->decode($this->req('POST', '/password-reset', ['email' => 'alice@example.com']))['token'];
        $this->req('POST', "/password-reset/{$token}", ['password' => 'newpassword1']);

        // Second attempt must be rejected
        $res = $this->req('POST', "/password-reset/{$token}", ['password' => 'anotherpass']);
        self::assertSame(409, $res->getStatusCode(), 'Used token must be rejected on second attempt');
    }

    public function testCompleteResetShortPasswordReturns422(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice', 'password' => 'secret123']);
        $token = $this->decode($this->req('POST', '/password-reset', ['email' => 'alice@example.com']))['token'];

        $res = $this->req('POST', "/password-reset/{$token}", ['password' => 'short']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testCompleteResetExpiredTokenReturns410(): void
    {
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $past = (new \DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO users (email, name, password_hash, created_at) VALUES ('x@example.com','X','hash','{$past}')");
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $pdo->exec("INSERT INTO password_resets (user_id, token_hash, used_at, expires_at, created_at) VALUES (1,'{$tokenHash}',NULL,'{$past}','{$past}')");
        unset($pdo);

        $res = $this->req('POST', "/password-reset/{$rawToken}", ['password' => 'newpassword1']);
        self::assertSame(410, $res->getStatusCode());
    }

    public function testCompleteResetNonExistentTokenReturns404(): void
    {
        $res = $this->req('POST', '/password-reset/' . bin2hex(random_bytes(32)), ['password' => 'newpassword1']);
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Old token invalidation ---

    public function testRequestingNewResetInvalidatesPreviousToken(): void
    {
        $this->req('POST', '/users', ['email' => 'alice@example.com', 'name' => 'Alice', 'password' => 'secret123']);
        $firstToken  = $this->decode($this->req('POST', '/password-reset', ['email' => 'alice@example.com']))['token'];
        $this->req('POST', '/password-reset', ['email' => 'alice@example.com']); // second request

        // First token must now be rejected
        $res = $this->req('POST', "/password-reset/{$firstToken}", ['password' => 'newpassword1']);
        self::assertSame(409, $res->getStatusCode(), 'Old token must be invalidated when new reset is requested');
    }
}
