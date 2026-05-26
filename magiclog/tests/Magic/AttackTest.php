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
 * FT144 クラッカー攻撃試験 — パスワードレス認証（Magic Link）
 *
 * ATK-01: ランダムトークンで verify を試みる（ブルートフォース）
 * ATK-02: 期限切れ magic link で verify
 * ATK-03: 使用済み magic link の再利用
 * ATK-04: 無効なメール形式でリクエスト
 * ATK-05: 空のトークンで verify
 * ATK-06: SQL インジェクション in email
 * ATK-07: 極端に長いメールアドレス
 * ATK-08: revoked セッションで /me
 * ATK-09: 他人のセッショントークンで /me（IDORシミュレーション）
 * ATK-10: 期限切れセッションで /me
 * ATK-11: logout後の同セッション再利用
 * ATK-12: X-User-Id ヘッダーだけで /me にアクセス（認証バイパス試行）
 */
final class AttackTest extends TestCase
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

    /** ATK-01: ランダムトークンで verify ブルートフォース → 401 */
    public function testAtk01BruteForceVerify(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $fakeToken = bin2hex(random_bytes(32));
            $result = $this->post('/auth/verify', ['token' => $fakeToken]);
            $this->assertSame(401, $result['status'], "ATK-01: random token attempt $i should return 401");
        }
    }

    /** ATK-02: 期限切れ magic link で verify → 401 expired */
    public function testAtk02ExpiredMagicLink(): void
    {
        $req = $this->post('/auth/request', ['email' => 'victim@example.com']);
        $token = (string) $req['body']['token'];
        $this->pdo->exec("UPDATE magic_links SET expires_at = '2000-01-01T00:00:00+00:00'");

        $result = $this->post('/auth/verify', ['token' => $token]);
        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('expired', (string) ($result['body']['error'] ?? ''));
    }

    /** ATK-03: 使用済み magic link の再利用 → 401 */
    public function testAtk03ReplayMagicLink(): void
    {
        $req = $this->post('/auth/request', ['email' => 'victim@example.com']);
        $token = (string) $req['body']['token'];
        $this->post('/auth/verify', ['token' => $token]);

        $result = $this->post('/auth/verify', ['token' => $token]);
        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('already been used', (string) ($result['body']['error'] ?? ''));
    }

    /** ATK-04: 無効なメール形式でリクエスト → 422 */
    public function testAtk04InvalidEmailFormat(): void
    {
        $invalidEmails = [
            'notanemail',
            '@nodomain.com',
            'missing@',
            'two@@signs.com',
            'spaces in@email.com',
        ];
        foreach ($invalidEmails as $email) {
            $result = $this->post('/auth/request', ['email' => $email]);
            $this->assertSame(422, $result['status'], "ATK-04: '$email' should return 422");
        }
    }

    /** ATK-05: 空のトークンで verify → 422 */
    public function testAtk05EmptyTokenVerify(): void
    {
        $result = $this->post('/auth/verify', ['token' => '']);
        $this->assertSame(422, $result['status']);
    }

    /** ATK-06: SQL インジェクション in email → 422 or safe */
    public function testAtk06SqlInjectionInEmail(): void
    {
        $payloads = [
            "' OR '1'='1",
            "admin@example.com'; DROP TABLE users; --",
            "' UNION SELECT * FROM users --",
        ];
        foreach ($payloads as $payload) {
            $result = $this->post('/auth/request', ['email' => $payload]);
            // Either 422 (invalid email) or 202 (treated as literal string)
            // But users table must still exist
            $this->assertContains($result['status'], [202, 422], "ATK-06: SQL injection should not crash");
            $count = $this->pdoQuery("SELECT COUNT(*) FROM users")->fetchColumn();
            $this->assertIsInt((int) $count, 'ATK-06: users table must still exist after SQL injection attempt');
        }
    }

    /** ATK-07: 極端に長いメールアドレス → 422 */
    public function testAtk07VeryLongEmail(): void
    {
        $longEmail = str_repeat('a', 300) . '@example.com';
        $result = $this->post('/auth/request', ['email' => $longEmail]);
        $this->assertSame(422, $result['status']);
    }

    /** ATK-08: revoked セッションで /me → 401 */
    public function testAtk08RevokedSessionAccess(): void
    {
        $sessionToken = $this->requestAndVerify('victim@example.com');
        $this->pdo->exec("UPDATE auth_sessions SET revoked_at = '2000-01-01T00:00:00+00:00'");

        $result = $this->get('/me', ['Authorization' => 'Bearer ' . $sessionToken]);
        $this->assertSame(401, $result['status']);
    }

    /** ATK-09: 他ユーザーのセッショントークンで /me → 正しいユーザー情報か確認 */
    public function testAtk09CrossUserSessionToken(): void
    {
        // Alice and Bob both login
        $aliceToken = $this->requestAndVerify('alice@example.com');
        $bobToken = $this->requestAndVerify('bob@example.com');

        // Alice uses Bob's token
        $result = $this->get('/me', ['Authorization' => 'Bearer ' . $bobToken]);
        $this->assertSame(200, $result['status']);
        // Should return Bob's data, not Alice's (no IDOR — each token is tied to its owner)
        $this->assertSame('bob@example.com', $result['body']['email']);

        // Alice with her own token
        $result2 = $this->get('/me', ['Authorization' => 'Bearer ' . $aliceToken]);
        $this->assertSame('alice@example.com', $result2['body']['email']);
    }

    /** ATK-10: 期限切れセッションで /me → 401 */
    public function testAtk10ExpiredSession(): void
    {
        $sessionToken = $this->requestAndVerify('victim@example.com');
        $this->pdo->exec("UPDATE auth_sessions SET expires_at = '2000-01-01T00:00:00+00:00'");

        $result = $this->get('/me', ['Authorization' => 'Bearer ' . $sessionToken]);
        $this->assertSame(401, $result['status']);
        $this->assertStringContainsString('expired', (string) ($result['body']['error'] ?? ''));
    }

    /** ATK-11: logout 後の同セッション再利用 → 401 */
    public function testAtk11LogoutThenReuseSession(): void
    {
        $sessionToken = $this->requestAndVerify('victim@example.com');

        $this->post('/auth/logout', [], ['Authorization' => 'Bearer ' . $sessionToken]);

        $result = $this->get('/me', ['Authorization' => 'Bearer ' . $sessionToken]);
        $this->assertSame(401, $result['status']);
    }

    /** ATK-12: X-User-Id ヘッダーだけで /me → 401（認証バイパス試行） */
    public function testAtk12XUserIdBypassAttempt(): void
    {
        // Ensure user exists
        $this->post('/auth/request', ['email' => 'victim@example.com']);
        $userId = (int) $this->pdoQuery("SELECT id FROM users LIMIT 1")->fetchColumn();

        // Attacker sends X-User-Id without Bearer token
        $result = $this->get('/me', ['X-User-Id' => (string) $userId]);
        $this->assertSame(401, $result['status']);
    }
}
