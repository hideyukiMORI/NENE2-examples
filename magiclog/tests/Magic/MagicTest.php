<?php

declare(strict_types=1);

namespace MagicLog\Tests\Magic;

use MagicLog\AppFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use PDO;

final class MagicTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));

        $this->router = AppFactory::createSqliteApp(':memory:');
        // Inject our controlled PDO by rebuilding with shared PDO
        $this->router = $this->buildRouterWithPdo($this->pdo);
        $this->psr17 = new Psr17Factory();
    }

    private function buildRouterWithPdo(PDO $pdo): Router
    {
        $factory = new class ($pdo) implements \Nene2\Database\DatabaseConnectionFactoryInterface {
            public function __construct(private readonly PDO $pdo)
            {
            }
            public function create(): PDO
            {
                return $this->pdo;
            }
        };
        $executor = new \Nene2\Database\PdoDatabaseQueryExecutor($factory);
        $repository = new \MagicLog\Magic\MagicRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new \Nene2\Http\JsonResponseFactory($psr17, $psr17);
        $router = new Router();
        $registrar = new \MagicLog\Magic\RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();
        return $router;
    }

    private function post(string $path, array $body = [], array $headers = []): array
    {
        $request = new ServerRequest('POST', $path, array_merge(['Content-Type' => 'application/json'], $headers));
        $json = empty($body) ? '{}' : (json_encode($body) ?: '{}');
        $request = $request->withBody($this->psr17->createStream($json));
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    private function get(string $path, array $headers = []): array
    {
        $request = new ServerRequest('GET', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    private function pdoQuery(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        assert($stmt instanceof \PDOStatement);
        return $stmt;
    }

    // --- request ---

    public function testRequestWithValidEmailReturns202(): void
    {
        $result = $this->post('/auth/request', ['email' => 'alice@example.com']);
        $this->assertSame(202, $result['status']);
        $this->assertArrayHasKey('token', $result['body']);
        $this->assertNotEmpty($result['body']['token']);
    }

    public function testRequestAlwaysReturns202EvenForUnknownEmail(): void
    {
        // Prevents user enumeration — same response whether user exists or not
        $result1 = $this->post('/auth/request', ['email' => 'new@example.com']);
        $this->assertSame(202, $result1['status']);
    }

    public function testRequestCreatesUserOnFirstLogin(): void
    {
        $this->post('/auth/request', ['email' => 'newuser@example.com']);
        $row = $this->pdoQuery("SELECT * FROM users WHERE email = 'newuser@example.com'")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('newuser@example.com', $row['email']);
    }

    public function testRequestReusesExistingUser(): void
    {
        $this->post('/auth/request', ['email' => 'alice@example.com']);
        $this->post('/auth/request', ['email' => 'alice@example.com']);
        $count = (int) $this->pdoQuery("SELECT COUNT(*) FROM users WHERE email = 'alice@example.com'")->fetchColumn();
        $this->assertSame(1, $count);
        $linkCount = (int) $this->pdoQuery("SELECT COUNT(*) FROM magic_links")->fetchColumn();
        $this->assertSame(2, $linkCount);
    }

    public function testRequestWithMissingEmailReturns422(): void
    {
        $result = $this->post('/auth/request', []);
        $this->assertSame(422, $result['status']);
    }

    public function testRequestWithInvalidEmailReturns422(): void
    {
        $result = $this->post('/auth/request', ['email' => 'not-an-email']);
        $this->assertSame(422, $result['status']);
    }

    public function testRequestWithEmptyEmailReturns422(): void
    {
        $result = $this->post('/auth/request', ['email' => '']);
        $this->assertSame(422, $result['status']);
    }

    // --- verify ---

    public function testVerifyWithValidTokenReturns200(): void
    {
        $req = $this->post('/auth/request', ['email' => 'alice@example.com']);
        $token = (string) $req['body']['token'];

        $result = $this->post('/auth/verify', ['token' => $token]);
        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('session_token', $result['body']);
        $this->assertArrayHasKey('expires_at', $result['body']);
    }

    public function testVerifyMarksTokenAsUsed(): void
    {
        $req = $this->post('/auth/request', ['email' => 'alice@example.com']);
        $token = (string) $req['body']['token'];
        $this->post('/auth/verify', ['token' => $token]);

        $row = $this->pdoQuery("SELECT used_at FROM magic_links LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertNotNull($row['used_at']);
    }

    public function testVerifyWithInvalidTokenReturns401(): void
    {
        $result = $this->post('/auth/verify', ['token' => 'invalidtoken123']);
        $this->assertSame(401, $result['status']);
    }

    public function testVerifyWithUsedTokenReturns401(): void
    {
        $req = $this->post('/auth/request', ['email' => 'alice@example.com']);
        $token = (string) $req['body']['token'];
        $this->post('/auth/verify', ['token' => $token]);

        $result = $this->post('/auth/verify', ['token' => $token]);
        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('already been used', (string) ($result['body']['error'] ?? ''));
    }

    public function testVerifyWithExpiredTokenReturns401(): void
    {
        $this->post('/auth/request', ['email' => 'alice@example.com']);
        // Manually expire the link
        $this->pdo->exec("UPDATE magic_links SET expires_at = '2000-01-01T00:00:00+00:00'");

        $row = $this->pdoQuery("SELECT token_hash FROM magic_links LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        // We can't get the raw token back, so just verify the expiry check works via a fresh token
        $req2 = $this->post('/auth/request', ['email' => 'alice@example.com']);
        $freshToken = (string) $req2['body']['token'];
        $this->pdo->exec("UPDATE magic_links SET expires_at = '2000-01-01T00:00:00+00:00' WHERE used_at IS NULL");
        $result = $this->post('/auth/verify', ['token' => $freshToken]);
        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('expired', (string) ($result['body']['error'] ?? ''));
    }

    public function testVerifyWithMissingTokenReturns422(): void
    {
        $result = $this->post('/auth/verify', []);
        $this->assertSame(422, $result['status']);
    }

    // --- logout ---

    public function testLogoutInvalidatesSession(): void
    {
        $req = $this->post('/auth/request', ['email' => 'alice@example.com']);
        $verifyResult = $this->post('/auth/verify', ['token' => (string) $req['body']['token']]);
        $sessionToken = (string) $verifyResult['body']['session_token'];

        $result = $this->post('/auth/logout', [], ['Authorization' => 'Bearer ' . $sessionToken]);
        $this->assertSame(204, $result['status']);

        // Session should now be revoked
        $meResult = $this->get('/me', ['Authorization' => 'Bearer ' . $sessionToken]);
        $this->assertSame(401, $meResult['status']);
        $this->assertStringContainsString('revoked', (string) ($meResult['body']['error'] ?? ''));
    }

    public function testLogoutAlwaysReturns204EvenWithoutToken(): void
    {
        $result = $this->post('/auth/logout', []);
        $this->assertSame(204, $result['status']);
    }

    public function testLogoutAlwaysReturns204WithInvalidToken(): void
    {
        $result = $this->post('/auth/logout', [], ['Authorization' => 'Bearer invalidtoken']);
        $this->assertSame(204, $result['status']);
    }

    // --- /me ---

    public function testMeWithValidSessionReturns200(): void
    {
        $req = $this->post('/auth/request', ['email' => 'alice@example.com']);
        $verifyResult = $this->post('/auth/verify', ['token' => (string) $req['body']['token']]);
        $sessionToken = (string) $verifyResult['body']['session_token'];

        $result = $this->get('/me', ['Authorization' => 'Bearer ' . $sessionToken]);
        $this->assertSame(200, $result['status']);
        $this->assertSame('alice@example.com', $result['body']['email']);
    }

    public function testMeWithoutTokenReturns401(): void
    {
        $result = $this->get('/me');
        $this->assertSame(401, $result['status']);
    }

    public function testMeWithInvalidTokenReturns401(): void
    {
        $result = $this->get('/me', ['Authorization' => 'Bearer badtoken']);
        $this->assertSame(401, $result['status']);
    }
}
