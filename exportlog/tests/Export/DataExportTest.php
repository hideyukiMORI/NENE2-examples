<?php

declare(strict_types=1);

namespace Export\Tests\Export;

use Export\Export\ExportRepository;
use Export\Export\RouteRegistrar;
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

final class DataExportTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/exportlog-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $factory  = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17    = new Psr17Factory();
        $json     = new JsonResponseFactory($psr17, $psr17);
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $repo     = new ExportRepository($executor);

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
        $res  = $this->req('POST', '/users', ['email' => 'a@example.com', 'name' => 'Alice', 'password' => 'secret']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('a@example.com', $body['email']);
        self::assertSame('Alice', $body['name']);
    }

    public function testCreateUserMissingEmailReturns422(): void
    {
        $res = $this->req('POST', '/users', ['name' => 'Alice', 'password' => 'secret']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testCreateDuplicateEmailReturns409(): void
    {
        $this->req('POST', '/users', ['email' => 'dup@example.com', 'name' => 'Alice', 'password' => 'secret']);
        $res = $this->req('POST', '/users', ['email' => 'dup@example.com', 'name' => 'Bob', 'password' => 'pass']);
        self::assertSame(409, $res->getStatusCode());
    }

    // --- Profile ---

    public function testGetUserReturns200(): void
    {
        $this->req('POST', '/users', ['email' => 'b@example.com', 'name' => 'Bob', 'password' => 'p']);
        $res  = $this->req('GET', '/users/1');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('Bob', $body['name']);
    }

    public function testGetUserDoesNotExposePasswordHash(): void
    {
        $this->req('POST', '/users', ['email' => 'c@example.com', 'name' => 'Carol', 'password' => 'hunter2']);
        $res  = $this->req('GET', '/users/1');
        $body = $this->decode($res);

        self::assertArrayNotHasKey('password_hash', $body, 'password_hash must not appear in profile response');
        self::assertArrayNotHasKey('password', $body, 'raw password must not appear in profile response');
    }

    public function testGetUserDoesNotExposePhone(): void
    {
        $this->req('POST', '/users', [
            'email' => 'd@example.com', 'name' => 'Dave', 'phone' => '090-1234-5678', 'password' => 'p',
        ]);
        $res  = $this->req('GET', '/users/1');
        $body = $this->decode($res);

        self::assertArrayNotHasKey('phone', $body, 'phone must not appear in public profile response');
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('090-1234-5678', $json, 'phone number must not appear anywhere in response');
    }

    public function testGetNonExistentUserReturns404(): void
    {
        $res = $this->req('GET', '/users/999');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Export request ---

    public function testRequestExportReturns202WithToken(): void
    {
        $this->req('POST', '/users', ['email' => 'e@example.com', 'name' => 'Eve', 'password' => 'p']);
        $res  = $this->req('POST', '/users/1/export');
        $body = $this->decode($res);

        self::assertSame(202, $res->getStatusCode());
        self::assertSame('pending', $body['status']);
        self::assertNotEmpty($body['token']);
    }

    public function testExportTokenHasSufficientEntropy(): void
    {
        $this->req('POST', '/users', ['email' => 'f@example.com', 'name' => 'Frank', 'password' => 'p']);
        $res  = $this->req('POST', '/users/1/export');
        $body = $this->decode($res);

        // bin2hex(random_bytes(32)) = 64 hex chars = 256 bits of entropy
        self::assertSame(64, strlen($body['token']), 'Export token must be 64 hex chars (256-bit entropy)');
    }

    public function testRequestExportForNonExistentUserReturns404(): void
    {
        $res = $this->req('POST', '/users/999/export');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Process export ---

    public function testProcessExportMarksReady(): void
    {
        $this->req('POST', '/users', ['email' => 'g@example.com', 'name' => 'Grace', 'password' => 'p']);
        $exportRes = $this->req('POST', '/users/1/export');
        $token     = $this->decode($exportRes)['token'];

        $res  = $this->req('POST', "/exports/{$token}/process");
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('ready', $body['status']);
    }

    public function testProcessNonExistentExportReturns404(): void
    {
        $res = $this->req('POST', '/exports/nonexistenttoken/process');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testProcessExpiredExportReturns410(): void
    {
        // Insert an already-expired export that is still pending
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $past = (new \DateTimeImmutable())->modify('-1 hour')->format('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO users (email, name, phone, password_hash, created_at) VALUES ('vuln@example.com','Vuln','','hash','{$past}')");
        $pdo->exec("INSERT INTO data_exports (user_id, token, status, payload, expires_at, created_at) VALUES (1,'expiredpendingtoken','pending',NULL,'{$past}','{$past}')");
        unset($pdo);

        $res = $this->req('POST', '/exports/expiredpendingtoken/process');
        self::assertSame(410, $res->getStatusCode(), 'VULN-FIX: processing an expired export must return 410');
    }

    // --- Download export ---

    public function testDownloadReadyExportReturns200(): void
    {
        $this->req('POST', '/users', ['email' => 'h@example.com', 'name' => 'Heidi', 'password' => 'p']);
        $token = $this->decode($this->req('POST', '/users/1/export'))['token'];
        $this->req('POST', "/exports/{$token}/process");

        $res  = $this->req('GET', "/exports/{$token}");
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertArrayHasKey('export', $body);
        self::assertSame('h@example.com', $body['export']['user']['email']);
        self::assertSame('Heidi', $body['export']['user']['name']);
    }

    public function testDownloadExportDoesNotContainPasswordHash(): void
    {
        $this->req('POST', '/users', ['email' => 'i@example.com', 'name' => 'Ivan', 'password' => 'topsecret']);
        $token = $this->decode($this->req('POST', '/users/1/export'))['token'];
        $this->req('POST', "/exports/{$token}/process");

        $res  = $this->req('GET', "/exports/{$token}");
        $json = (string) $res->getBody();

        self::assertStringNotContainsString('password_hash', $json, 'password_hash must not appear in export payload');
        self::assertStringNotContainsString('topsecret', $json, 'raw password must not appear in export payload');
    }

    public function testDownloadExportDoesNotContainPhone(): void
    {
        $this->req('POST', '/users', [
            'email' => 'j@example.com', 'name' => 'Judy', 'phone' => '080-9876-5432', 'password' => 'p',
        ]);
        $token = $this->decode($this->req('POST', '/users/1/export'))['token'];
        $this->req('POST', "/exports/{$token}/process");

        $res  = $this->req('GET', "/exports/{$token}");
        $json = (string) $res->getBody();

        self::assertStringNotContainsString('phone', $json, 'phone field must not appear in export payload');
        self::assertStringNotContainsString('080-9876-5432', $json, 'phone number must not appear in export payload');
    }

    public function testDownloadPendingExportReturns409(): void
    {
        $this->req('POST', '/users', ['email' => 'k@example.com', 'name' => 'Kate', 'password' => 'p']);
        $token = $this->decode($this->req('POST', '/users/1/export'))['token'];

        $res = $this->req('GET', "/exports/{$token}");
        self::assertSame(409, $res->getStatusCode());
    }

    public function testDownloadNonExistentExportReturns404(): void
    {
        $res = $this->req('GET', '/exports/nosuchtoken');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testDownloadExpiredExportReturns410(): void
    {
        // Insert an already-expired export directly
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $past = (new \DateTimeImmutable())->modify('-1 hour')->format('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO users (email, name, phone, password_hash, created_at) VALUES ('exp@example.com','Exp','','hash','{$past}')");
        $pdo->exec("INSERT INTO data_exports (user_id, token, status, payload, expires_at, created_at) VALUES (1,'expiredtoken123','ready','{}','{$past}','{$past}')");
        unset($pdo);

        $res = $this->req('GET', '/exports/expiredtoken123');
        self::assertSame(410, $res->getStatusCode());
    }
}
