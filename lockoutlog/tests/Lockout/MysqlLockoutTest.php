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

/**
 * MySQL integration tests for FT128.
 *
 * Skipped unless MYSQL_HOST environment variable is set.
 * Run via:
 *   docker compose -f /home/xi/docker/NENE2-FT/docker-compose.yml up -d mysql
 *   docker run --rm --network nene2-ft_default \
 *     -v /home/xi/docker/NENE2-FT/lockoutlog:/app -w /app \
 *     -e MYSQL_HOST=mysql -e MYSQL_PORT=3306 \
 *     -e MYSQL_DATABASE=ft_test -e MYSQL_USER=ft_user -e MYSQL_PASSWORD=ft_pass \
 *     nene2-app composer test
 *
 * Tables are dropped and recreated in setUp/tearDown for test isolation.
 * ft_user must have CREATE/DROP privileges on MYSQL_DATABASE.
 */
final class MysqlLockoutTest extends TestCase
{
    private RequestHandlerInterface $app;
    private \PDO $pdo;
    private bool $mysqlEnabled = false;

    protected function setUp(): void
    {
        $host = (string) (getenv('MYSQL_HOST') ?: '');
        if ($host === '') {
            self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
        }

        $this->mysqlEnabled = true;

        $port     = (int) (getenv('MYSQL_PORT') ?: 3306);
        $database = (string) (getenv('MYSQL_DATABASE') ?: 'ft_test');
        $user     = (string) (getenv('MYSQL_USER') ?: 'ft_user');
        $password = (string) (getenv('MYSQL_PASSWORD') ?: 'ft_pass');

        $dsn       = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $this->pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        // Recreate tables for isolation
        $this->pdo->exec('DROP TABLE IF EXISTS account_states');
        $this->pdo->exec('DROP TABLE IF EXISTS users');

        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.mysql.sql');
        $this->pdo->exec($schema);

        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'mysql',
            host: $host,
            port: $port,
            name: $database,
            user: $user,
            password: $password,
            charset: 'utf8mb4',
        );

        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $repo      = new LockoutRepository($executor);
        $registrar = new RouteRegistrar($repo, $json, $problems);

        $this->app = new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn(Router $r) => $registrar->register($r)],
        )->create();
    }

    protected function tearDown(): void
    {
        if ($this->mysqlEnabled) {
            $this->pdo->exec('DROP TABLE IF EXISTS account_states');
            $this->pdo->exec('DROP TABLE IF EXISTS users');
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

    public function testMysqlCreateAndLoginFlow(): void
    {
        $res = $this->req('POST', '/users', ['email' => 'mysql@example.com', 'password' => 'pass123']);
        self::assertSame(201, $res->getStatusCode());

        $res = $this->req('POST', '/auth/login', ['email' => 'mysql@example.com', 'password' => 'pass123']);
        self::assertSame(200, $res->getStatusCode());
    }

    public function testMysqlLockoutAfterFiveFailures(): void
    {
        $this->req('POST', '/users', ['email' => 'mysql2@example.com', 'password' => 'secret']);

        for ($i = 0; $i < 5; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'mysql2@example.com', 'password' => 'wrong']);
        }

        $res = $this->req('POST', '/auth/login', ['email' => 'mysql2@example.com', 'password' => 'wrong']);
        self::assertSame(423, $res->getStatusCode());
    }

    public function testMysqlStatusEndpoint(): void
    {
        $this->req('POST', '/users', ['email' => 'mysql3@example.com', 'password' => 'abc']);
        $this->req('POST', '/auth/login', ['email' => 'mysql3@example.com', 'password' => 'wrong']);

        $res  = $this->req('GET', '/auth/status/mysql3@example.com');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(1, $body['failed_count']);
        self::assertFalse($body['is_locked']);
    }

    public function testMysqlSuccessfulLoginResetsCounter(): void
    {
        $this->req('POST', '/users', ['email' => 'mysql4@example.com', 'password' => 'correct']);

        for ($i = 0; $i < 3; $i++) {
            $this->req('POST', '/auth/login', ['email' => 'mysql4@example.com', 'password' => 'wrong']);
        }

        $this->req('POST', '/auth/login', ['email' => 'mysql4@example.com', 'password' => 'correct']);

        $res  = $this->req('GET', '/auth/status/mysql4@example.com');
        $body = $this->decode($res);

        self::assertSame(0, $body['failed_count']);
        self::assertFalse($body['is_locked']);
    }

    public function testMysqlUniqueEmailConstraint(): void
    {
        $this->req('POST', '/users', ['email' => 'dup@example.com', 'password' => 'pass1']);
        $res = $this->req('POST', '/users', ['email' => 'dup@example.com', 'password' => 'pass2']);
        self::assertGreaterThanOrEqual(400, $res->getStatusCode(), 'Duplicate email must be rejected');
    }
}
