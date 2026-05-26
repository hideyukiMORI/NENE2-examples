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

/**
 * Cracker-mindset attack tests for FT148: OTP Authentication System.
 * Simulates real attacker scenarios: brute force, replay, lockout bypass,
 * injection, enumeration, and token theft.
 */
class AttackTest extends TestCase
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

    /**
     * ATK-01: Brute force OTP (6 digits = 10^6 combinations).
     * After MAX_ATTEMPTS wrong codes the account must be locked.
     */
    public function testATK01_bruteForceOtpTriggersLockout(): void
    {
        $this->post('/otp/request', ['email' => 'target@example.com']);
        for ($i = 0; $i < OtpRepository::maxAttempts(); $i++) {
            $result = $this->post('/otp/verify', ['email' => 'target@example.com', 'code' => '000000']);
            $this->assertSame(401, $result['status'], "Attempt $i should return 401");
        }
        $result = $this->post('/otp/verify', ['email' => 'target@example.com', 'code' => '000001']);
        $this->assertSame(429, $result['status'], 'After lockout all attempts must return 429');
    }

    /**
     * ATK-02: Replay attack — reuse a valid but already-used OTP.
     */
    public function testATK02_replayUsedOtpIsRejected(): void
    {
        $req = $this->post('/otp/request', ['email' => 'alice@example.com']);
        $code = (string) ($req['body']['code'] ?? '');

        $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => $code]);
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => $code]);
        $this->assertSame(401, $result['status'], 'Used OTP must be rejected on replay');
    }

    /**
     * ATK-03: User enumeration via /otp/request.
     * Requesting for a non-existent email must still return 202 (same as existing).
     */
    public function testATK03_requestAlwaysReturns202PreventingEnumeration(): void
    {
        $existing = $this->post('/otp/request', ['email' => 'alice@example.com']);
        $nonExistent = $this->post('/otp/request', ['email' => 'nobody_xyzabc@example.com']);
        $this->assertSame(202, $existing['status']);
        $this->assertSame(202, $nonExistent['status'], 'Both must return 202 to prevent enumeration');
    }

    /**
     * ATK-04: Verify for non-existent user must return 401 (not 500 or 404).
     */
    public function testATK04_verifyNonExistentUserReturns401(): void
    {
        $result = $this->post('/otp/verify', ['email' => 'ghost@example.com', 'code' => '123456']);
        $this->assertSame(401, $result['status'], 'Non-existent user must return 401');
        $this->assertArrayNotHasKey('trace', $result['body']);
    }

    /**
     * ATK-05: SQL injection in email field.
     * Must be rejected by email validation (422), not cause DB error.
     */
    public function testATK05_sqlInjectionInEmailIsRejected(): void
    {
        $result = $this->post('/otp/request', ['email' => "'; DROP TABLE users; --"]);
        $this->assertSame(422, $result['status'], 'SQL injection string must fail email validation');

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');
        assert($stmt instanceof \PDOStatement);
        $this->assertGreaterThanOrEqual(0, (int) $stmt->fetchColumn(), 'users table must still exist');
    }

    /**
     * ATK-06: 5-digit code (too short) must be rejected.
     */
    public function testATK06_shortCodeIsRejected(): void
    {
        $this->post('/otp/request', ['email' => 'alice@example.com']);
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => '12345']);
        $this->assertSame(422, $result['status'], '5-digit code must return 422');
    }

    /**
     * ATK-07: 7-digit code (too long) must be rejected.
     */
    public function testATK07_longCodeIsRejected(): void
    {
        $this->post('/otp/request', ['email' => 'alice@example.com']);
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => '1234567']);
        $this->assertSame(422, $result['status'], '7-digit code must return 422');
    }

    /**
     * ATK-08: Session token reuse after logout must fail.
     */
    public function testATK08_revokedSessionTokenIsRejected(): void
    {
        $req = $this->post('/otp/request', ['email' => 'alice@example.com']);
        $code = (string) ($req['body']['code'] ?? '');
        $verify = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => $code]);
        $token = (string) ($verify['body']['session_token'] ?? '');

        $this->delete('/otp/session', ['Authorization' => "Bearer $token"]);
        $result = $this->get('/otp/session', ['Authorization' => "Bearer $token"]);
        $this->assertSame(401, $result['status'], 'Revoked session must be rejected');
    }

    /**
     * ATK-09: Random token guessing — a random 64-hex token must return 401.
     */
    public function testATK09_randomTokenIsRejected(): void
    {
        $fakeToken = bin2hex(random_bytes(32));
        $result = $this->get('/otp/session', ['Authorization' => "Bearer $fakeToken"]);
        $this->assertSame(401, $result['status'], 'Random session token must be rejected');
    }

    /**
     * ATK-10: Empty Bearer token must return 401.
     */
    public function testATK10_emptyBearerTokenIsRejected(): void
    {
        $result = $this->get('/otp/session', ['Authorization' => 'Bearer ']);
        $this->assertSame(401, $result['status'], 'Empty Bearer token must return 401');
    }

    /**
     * ATK-11: Alphabetic code (non-numeric) must be rejected.
     */
    public function testATK11_alphabeticCodeIsRejected(): void
    {
        $this->post('/otp/request', ['email' => 'alice@example.com']);
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => 'abcdef']);
        $this->assertSame(422, $result['status'], 'Non-numeric code must return 422');
    }

    /**
     * ATK-12: New OTP request invalidates old code (uses latest only).
     * An attacker cannot use an older OTP after a new one has been issued.
     */
    public function testATK12_oldOtpInvalidatedByNewRequest(): void
    {
        $first = $this->post('/otp/request', ['email' => 'alice@example.com']);
        $oldCode = (string) ($first['body']['code'] ?? '');

        $this->post('/otp/request', ['email' => 'alice@example.com']);

        // Old code should now fail (latest OTP is used for verification)
        $result = $this->post('/otp/verify', ['email' => 'alice@example.com', 'code' => $oldCode]);
        $this->assertSame(401, $result['status'], 'Old OTP must not work after new OTP is issued');
    }
}
