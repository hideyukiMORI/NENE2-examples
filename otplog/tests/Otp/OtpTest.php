<?php

declare(strict_types=1);

namespace OtpLog\Tests\Otp;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use OtpLog\Otp\OtpRepository;
use OtpLog\Otp\RouteRegistrar;

class OtpTest extends TestCase
{
    private \PDO $pdo;
    private Router $router;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));

        $this->psr17 = new Psr17Factory();
        $this->router = $this->buildRouterWithPdo($this->pdo);
    }

    private function buildRouterWithPdo(\PDO $pdo): Router
    {
        $factory = new class ($pdo) implements DatabaseConnectionFactoryInterface {
            public function __construct(private readonly \PDO $pdo)
            {
            }
            public function create(): \PDO
            {
                return $this->pdo;
            }
        };
        $executor = new PdoDatabaseQueryExecutor($factory);
        $repository = new OtpRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();
        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();
        return $router;
    }

    /** @param array<string, string> $headers */
    private function post(string $path, mixed $body = [], array $headers = []): array
    {
        $allHeaders = array_merge(['Content-Type' => 'application/json'], $headers);
        $request = new ServerRequest('POST', $path, $allHeaders);
        $json = (is_array($body) && empty($body)) ? '{}' : (json_encode($body) ?: '{}');
        $request = $request->withBody($this->psr17->createStream($json));
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = []): array
    {
        $request = new ServerRequest('GET', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /** @param array<string, string> $headers */
    private function delete(string $path, array $headers = []): array
    {
        $request = new ServerRequest('DELETE', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    private function requestOtp(string $email): string
    {
        $result = $this->post('/otp/request', ['email' => $email]);
        return (string) ($result['body']['code'] ?? '');
    }

    private function verifyAndGetToken(string $email, string $code): string
    {
        $result = $this->post('/otp/verify', ['email' => $email, 'code' => $code]);
        return (string) ($result['body']['session_token'] ?? '');
    }

    public function testRequestOtp_returns202(): void
    {
        $result = $this->post('/otp/request', ['email' => 'alice@example.com']);
        $this->assertSame(202, $result['status']);
        $this->assertArrayHasKey('code', $result['body']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $result['body']['code']);
    }

    public function testRequestOtp_invalidEmail_returns422(): void
    {
        $result = $this->post('/otp/request', ['email' => 'not-an-email']);
        $this->assertSame(422, $result['status']);
    }

    public function testRequestOtp_missingEmail_returns422(): void
    {
        $result = $this->post('/otp/request', []);
        $this->assertSame(422, $result['status']);
    }

    public function testVerifyOtp_validCode_returns200WithToken(): void
    {
        $code = $this->requestOtp('alice@example.com');
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => $code]);
        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('session_token', $result['body']);
        $this->assertArrayHasKey('user_id', $result['body']);
    }

    public function testVerifyOtp_wrongCode_returns401(): void
    {
        $this->requestOtp('alice@example.com');
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => '000000']);
        $this->assertSame(401, $result['status']);
    }

    public function testVerifyOtp_nonExistentEmail_returns401(): void
    {
        $result = $this->post('/otp/verify', ['email' => 'nobody@example.com', 'code' => '123456']);
        $this->assertSame(401, $result['status']);
    }

    public function testVerifyOtp_usedCode_returns401(): void
    {
        $code = $this->requestOtp('alice@example.com');
        $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => $code]);
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => $code]);
        $this->assertSame(401, $result['status']);
    }

    public function testVerifyOtp_missingFields_returns422(): void
    {
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com']);
        $this->assertSame(422, $result['status']);
    }

    public function testVerifyOtp_invalidCodeFormat_returns422(): void
    {
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => 'abc123']);
        $this->assertSame(422, $result['status']);
    }

    public function testGetSession_validToken_returns200(): void
    {
        $code = $this->requestOtp('alice@example.com');
        $token = $this->verifyAndGetToken('alice@example.com', $code);
        $result = $this->get('/otp/session', ['Authorization' => "Bearer $token"]);
        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('user_id', $result['body']);
        $this->assertArrayHasKey('expires_at', $result['body']);
    }

    public function testGetSession_noToken_returns401(): void
    {
        $result = $this->get('/otp/session');
        $this->assertSame(401, $result['status']);
    }

    public function testGetSession_invalidToken_returns401(): void
    {
        $result = $this->get('/otp/session', ['Authorization' => 'Bearer invalidtoken']);
        $this->assertSame(401, $result['status']);
    }

    public function testDeleteSession_logsOut(): void
    {
        $code = $this->requestOtp('alice@example.com');
        $token = $this->verifyAndGetToken('alice@example.com', $code);

        $deleteResult = $this->delete('/otp/session', ['Authorization' => "Bearer $token"]);
        $this->assertSame(200, $deleteResult['status']);

        $sessionResult = $this->get('/otp/session', ['Authorization' => "Bearer $token"]);
        $this->assertSame(401, $sessionResult['status']);
    }

    public function testDeleteSession_noToken_stillReturns200(): void
    {
        $result = $this->delete('/otp/session');
        $this->assertSame(401, $result['status']);
    }

    public function testLockout_after3FailedAttempts(): void
    {
        $this->requestOtp('alice@example.com');
        for ($i = 0; $i < OtpRepository::maxAttempts(); $i++) {
            $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => '000000']);
        }
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => '000000']);
        $this->assertSame(429, $result['status']);
    }

    public function testRequestCreatesNewUser(): void
    {
        $this->post('/otp/request', ['email' => 'newuser@example.com']);
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users WHERE email = 'newuser@example.com'");
        assert($stmt instanceof \PDOStatement);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testRequestExistingUser_doesNotDuplicate(): void
    {
        $this->post('/otp/request', ['email' => 'alice@example.com']);
        $this->post('/otp/request', ['email' => 'alice@example.com']);
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users WHERE email = 'alice@example.com'");
        assert($stmt instanceof \PDOStatement);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testLatestOtpIsUsedForVerification(): void
    {
        $this->requestOtp('alice@example.com');
        $latestCode = $this->requestOtp('alice@example.com');
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => $latestCode]);
        $this->assertSame(200, $result['status']);
    }
}
