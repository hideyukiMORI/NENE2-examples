<?php

declare(strict_types=1);

namespace OtpLog\Tests\Otp;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use OtpLog\AppFactory;
use OtpLog\Otp\OtpRepository;
use PHPUnit\Framework\TestCase;

/**
 * FT148 MySQL integration tests.
 *
 * Skipped unless MYSQL_HOST env var is set.
 */
final class MysqlOtpTest extends TestCase
{
    private ?\PDO $pdo = null;
    private \Nene2\Routing\Router $app;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $hostEnv = getenv('MYSQL_HOST');
        if ($hostEnv === false || $hostEnv === '') {
            $this->markTestSkipped('MYSQL_HOST not set — skipping MySQL tests');
        }

        $host = $hostEnv;
        $port = (int) (getenv('MYSQL_PORT') ?: 3306);
        $database = (string) (getenv('MYSQL_DATABASE') ?: 'ft_test');
        $user = (string) (getenv('MYSQL_USER') ?: 'ft_user');
        $password = (string) (getenv('MYSQL_PASSWORD') ?: 'ft_pass');

        $this->pdo = new \PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $user,
            $password,
        );
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS otp_sessions');
        $this->pdo->exec('DROP TABLE IF EXISTS otp_codes');
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.mysql.sql'));

        $this->psr17 = new Psr17Factory();
        $this->app = AppFactory::createMysql($host, $port, $database, $user, $password);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->exec('DROP TABLE IF EXISTS otp_sessions');
            $this->pdo->exec('DROP TABLE IF EXISTS otp_codes');
            $this->pdo->exec('DROP TABLE IF EXISTS users');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $this->pdo = null;
        }
    }

    /** @param array<string, string> $headers */
    private function post(string $path, mixed $body = [], array $headers = []): array
    {
        $allHeaders = array_merge(['Content-Type' => 'application/json'], $headers);
        $request = new ServerRequest('POST', $path, $allHeaders);
        $json = (is_array($body) && empty($body)) ? '{}' : (json_encode($body) ?: '{}');
        $request = $request->withBody($this->psr17->createStream($json));
        $response = $this->app->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = []): array
    {
        $request = new ServerRequest('GET', $path, $headers);
        $response = $this->app->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /** @param array<string, string> $headers */
    private function delete(string $path, array $headers = []): array
    {
        $request = new ServerRequest('DELETE', $path, $headers);
        $response = $this->app->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    public function testMysql_requestAndVerify(): void
    {
        $req = $this->post('/otp/request', ['email' => 'mysql_alice@example.com']);
        $this->assertSame(202, $req['status']);
        $code = (string) ($req['body']['code'] ?? '');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $verify = $this->post('/otp/verify', ['email' => 'mysql_alice@example.com', 'code' => $code]);
        $this->assertSame(200, $verify['status']);
        $this->assertArrayHasKey('session_token', $verify['body']);
    }

    public function testMysql_sessionValidationAndRevocation(): void
    {
        $req = $this->post('/otp/request', ['email' => 'mysql_bob@example.com']);
        $code = (string) ($req['body']['code'] ?? '');
        $verify = $this->post('/otp/verify', ['email' => 'mysql_bob@example.com', 'code' => $code]);
        $token = (string) ($verify['body']['session_token'] ?? '');

        $session = $this->get('/otp/session', ['Authorization' => "Bearer $token"]);
        $this->assertSame(200, $session['status']);
        $this->assertArrayHasKey('user_id', $session['body']);

        $logout = $this->delete('/otp/session', ['Authorization' => "Bearer $token"]);
        $this->assertSame(200, $logout['status']);

        $afterLogout = $this->get('/otp/session', ['Authorization' => "Bearer $token"]);
        $this->assertSame(401, $afterLogout['status'], 'Session must be invalid after logout');
    }

    public function testMysql_lockoutAfterFailedAttempts(): void
    {
        $this->post('/otp/request', ['email' => 'mysql_charlie@example.com']);

        for ($i = 0; $i < OtpRepository::maxAttempts(); $i++) {
            $result = $this->post('/otp/verify', ['email' => 'mysql_charlie@example.com', 'code' => '000000']);
            $this->assertSame(401, $result['status']);
        }

        $locked = $this->post('/otp/verify', ['email' => 'mysql_charlie@example.com', 'code' => '000001']);
        $this->assertSame(429, $locked['status'], 'Must be locked after max attempts');
    }

    public function testMysql_sameEmailNotDuplicated(): void
    {
        $this->post('/otp/request', ['email' => 'mysql_dave@example.com']);
        $this->post('/otp/request', ['email' => 'mysql_dave@example.com']);

        assert($this->pdo !== null);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE email = 'mysql_dave@example.com'");
        $stmt->execute();
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'User must not be duplicated');
    }

    public function testMysql_invalidCodeReturns401(): void
    {
        $this->post('/otp/request', ['email' => 'mysql_eve@example.com']);
        $result = $this->post('/otp/verify', ['email' => 'mysql_eve@example.com', 'code' => '999999']);
        $this->assertSame(401, $result['status'], 'Wrong code must return 401');
    }
}
