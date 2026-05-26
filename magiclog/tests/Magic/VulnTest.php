<?php

declare(strict_types=1);

namespace MagicLog\Tests\Magic;

use MagicLog\Magic\MagicRepository;
use MagicLog\Magic\RouteRegistrar;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * FT144 脆弱性診断 — パスワードレス認証（Magic Link）
 *
 * VULN-A: 期限切れトークンが使用済みチェックより先に弾かれること（expiry before used_at）
 * VULN-B: セッショントークンは SHA-256 ハッシュで保存されること（生トークン未保存）
 * VULN-C: 使用済みmagic linkは再使用不可であること（リプレイ攻撃防止）
 * VULN-D: logout後のセッションは /me で 401 になること
 * VULN-E: POST /auth/request は存在しないメールでも 202 を返すこと（ユーザー列挙防止）
 * VULN-F: revoked セッションは /me で 401 になること
 * VULN-G: 期限切れセッションは /me で 401 になること
 * VULN-H: magic link token は DB に生値ではなく SHA-256 ハッシュで保存されること
 * VULN-I: magic link 有効期限は 15 分以内であること
 * VULN-J: session token 有効期限が設定されていること
 * VULN-K: セッショントークンは十分な長さ（64文字以上の hex）であること
 * VULN-L: X-User-Id ヘッダーを使った認証バイパスができないこと
 */
final class VulnTest extends TestCase
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
        $this->router = $this->buildRouterWithPdo($this->pdo);
        $this->psr17 = new Psr17Factory();
    }

    private function buildRouterWithPdo(PDO $pdo): Router
    {
        $factory = new class ($pdo) implements DatabaseConnectionFactoryInterface {
            public function __construct(private readonly PDO $pdo)
            {
            }
            public function create(): PDO
            {
                return $this->pdo;
            }
        };
        $executor = new PdoDatabaseQueryExecutor($factory);
        $repository = new MagicRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();
        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
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

    private function requestAndVerify(string $email): string
    {
        $req = $this->post('/auth/request', ['email' => $email]);
        $token = (string) $req['body']['token'];
        $verify = $this->post('/auth/verify', ['token' => $token]);
        return (string) $verify['body']['session_token'];
    }

    /** VULN-A: 期限切れトークンは used_at チェックより先に弾かれる */
    public function testVulnAExpiredTokenRejectedBeforeUsedCheck(): void
    {
        $req = $this->post('/auth/request', ['email' => 'alice@example.com']);
        $token = (string) $req['body']['token'];

        // Expire the token AND mark it as used simultaneously
        $this->pdo->exec("UPDATE magic_links SET expires_at = '2000-01-01T00:00:00+00:00', used_at = '2000-01-01T00:00:01+00:00'");

        $result = $this->post('/auth/verify', ['token' => $token]);
        $this->assertSame(401, $result['status']);
        // Error should mention "expired" not "already been used" — expiry checked first
        $this->assertStringContainsString('expired', (string) ($result['body']['error'] ?? ''));
    }

    /** VULN-B: session token は SHA-256 ハッシュ保存 — 生トークンはDBにない */
    public function testVulnBSessionTokenStoredAsHash(): void
    {
        $sessionToken = $this->requestAndVerify('alice@example.com');

        $row = $this->pdoQuery("SELECT session_token_hash FROM auth_sessions LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        // DB contains hash, not the raw token
        $this->assertNotSame($sessionToken, $row['session_token_hash']);
        $this->assertSame(hash('sha256', $sessionToken), $row['session_token_hash']);
    }

    /** VULN-C: 使用済み magic link の再使用を防止 */
    public function testVulnCUsedMagicLinkCannotBeReused(): void
    {
        $req = $this->post('/auth/request', ['email' => 'alice@example.com']);
        $token = (string) $req['body']['token'];

        $this->post('/auth/verify', ['token' => $token]);
        $result = $this->post('/auth/verify', ['token' => $token]);

        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('already been used', (string) ($result['body']['error'] ?? ''));
    }

    /** VULN-D: logout 後のセッションで /me が 401 */
    public function testVulnDLoggedOutSessionIsInvalid(): void
    {
        $sessionToken = $this->requestAndVerify('alice@example.com');

        $this->post('/auth/logout', [], ['Authorization' => 'Bearer ' . $sessionToken]);

        $result = $this->get('/me', ['Authorization' => 'Bearer ' . $sessionToken]);
        $this->assertSame(401, $result['status']);
    }

    /** VULN-E: ユーザー列挙防止 — 存在しないメールでも 202 */
    public function testVulnENonExistentEmailReturns202(): void
    {
        $result = $this->post('/auth/request', ['email' => 'nonexistent@example.com']);
        $this->assertSame(202, $result['status']);
        // Response should not leak user existence
        $body = json_encode($result['body']);
        $this->assertStringNotContainsString('not found', (string) $body);
        $this->assertStringNotContainsString('does not exist', (string) $body);
    }

    /** VULN-F: revoked セッションは /me で 401 */
    public function testVulnFRevokedSessionDenied(): void
    {
        $sessionToken = $this->requestAndVerify('alice@example.com');

        // Manually revoke session
        $this->pdo->exec("UPDATE auth_sessions SET revoked_at = '2000-01-01T00:00:00+00:00'");

        $result = $this->get('/me', ['Authorization' => 'Bearer ' . $sessionToken]);
        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('revoked', (string) ($result['body']['error'] ?? ''));
    }

    /** VULN-G: 期限切れセッションは /me で 401 */
    public function testVulnGExpiredSessionDenied(): void
    {
        $sessionToken = $this->requestAndVerify('alice@example.com');

        $this->pdo->exec("UPDATE auth_sessions SET expires_at = '2000-01-01T00:00:00+00:00'");

        $result = $this->get('/me', ['Authorization' => 'Bearer ' . $sessionToken]);
        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('expired', (string) ($result['body']['error'] ?? ''));
    }

    /** VULN-H: magic link token も SHA-256 ハッシュ保存 */
    public function testVulnHMagicLinkTokenStoredAsHash(): void
    {
        $req = $this->post('/auth/request', ['email' => 'alice@example.com']);
        $rawToken = (string) $req['body']['token'];

        $row = $this->pdoQuery("SELECT token_hash FROM magic_links LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertNotSame($rawToken, $row['token_hash']);
        $this->assertSame(hash('sha256', $rawToken), $row['token_hash']);
    }

    /** VULN-I: magic link の有効期限は 15 分（900秒）以内 */
    public function testVulnIMagicLinkExpiresWithin15Minutes(): void
    {
        $before = time();
        $this->post('/auth/request', ['email' => 'alice@example.com']);

        $row = $this->pdoQuery("SELECT expires_at, created_at FROM magic_links LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $expiresAt = strtotime((string) $row['expires_at']);
        $createdAt = strtotime((string) $row['created_at']);
        $ttl = $expiresAt - $createdAt;
        $this->assertLessThanOrEqual(900, $ttl, 'Magic link TTL should be <= 15 minutes');
        $this->assertGreaterThan(0, $ttl, 'Magic link TTL should be positive');
    }

    /** VULN-J: session token に有効期限が設定されていること */
    public function testVulnJSessionHasExpiry(): void
    {
        $sessionToken = $this->requestAndVerify('alice@example.com');
        $row = $this->pdoQuery("SELECT expires_at FROM auth_sessions LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertNotNull($row['expires_at']);
        $expiresAt = strtotime((string) $row['expires_at']);
        $this->assertGreaterThan(time(), $expiresAt, 'Session should expire in the future');
    }

    /** VULN-K: session token は十分な長さ（64 文字以上の hex）*/
    public function testVulnKSessionTokenHasSufficientEntropy(): void
    {
        $sessionToken = $this->requestAndVerify('alice@example.com');
        $this->assertGreaterThanOrEqual(64, strlen($sessionToken), 'Session token should be at least 64 hex chars (256-bit)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $sessionToken, 'Session token should be hex');
    }

    /** VULN-L: X-User-Id ヘッダーで認証バイパスできないこと */
    public function testVulnLXUserIdHeaderCannotBypassAuth(): void
    {
        // Create a user
        $this->post('/auth/request', ['email' => 'alice@example.com']);
        $userId = (int) $this->pdoQuery("SELECT id FROM users LIMIT 1")->fetchColumn();

        // Try to access /me with X-User-Id only (no Bearer token)
        $result = $this->get('/me', ['X-User-Id' => (string) $userId]);
        $this->assertSame(401, $result['status']);
    }
}
