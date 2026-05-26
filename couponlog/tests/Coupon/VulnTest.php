<?php

declare(strict_types=1);

namespace CouponLog\Tests\Coupon;

use CouponLog\Coupon\CouponRepository;
use CouponLog\Coupon\RouteRegistrar;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * FT150 脆弱性診断 — クーポン・プロモコード管理
 *
 * VULN-A: 非認証ユーザーがクーポン作成不可（401）
 * VULN-B: 一般ユーザーがクーポン作成不可（403）
 * VULN-C: 一般ユーザーが利用履歴閲覧不可（403）
 * VULN-D: 一般ユーザーがクーポン無効化不可（403）
 * VULN-E: 有効期限切れクーポンは利用不可（422）
 * VULN-F: 無効化済みクーポンは利用不可（422）
 * VULN-G: 同一ユーザーの二重利用防止（422）
 * VULN-H: max_uses 上限超過防止（422）
 * VULN-I: discount_pct=0 が拒否される（422）
 * VULN-J: discount_pct=101 が拒否される（422）
 * VULN-K: SQLインジェクション試みに 404 を返す（parameterized query）
 * VULN-L: user_id はボディから受け付けない（X-User-Id ヘッダーのみ）
 */
class VulnTest extends TestCase
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

        $now = date('c');
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Admin', 'admin', '$now')");
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Alice', 'user', '$now')");
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Bob', 'user', '$now')");

        $this->psr17 = new Psr17Factory();
        $factory = new class ($this->pdo) implements DatabaseConnectionFactoryInterface {
            public function __construct(private readonly \PDO $pdo)
            {
            }
            public function create(): \PDO
            {
                return $this->pdo;
            }
        };
        $executor = new PdoDatabaseQueryExecutor($factory);
        $repository = new CouponRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $this->router = new Router();
        $registrar = new RouteRegistrar($this->router, $repository, $responseFactory);
        $registrar->register();
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function get(string $path, array $headers = []): array
    {
        $request = new ServerRequest('GET', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function delete(string $path, array $headers = []): array
    {
        $request = new ServerRequest('DELETE', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /** @return array<string, mixed> */
    private function adminCreate(string $code, int $discountPct = 20, ?string $expiresAt = null, int $maxUses = 0): array
    {
        $body = ['code' => $code, 'discount_pct' => $discountPct, 'max_uses' => $maxUses];
        if ($expiresAt !== null) {
            $body['expires_at'] = $expiresAt;
        }
        return $this->post('/coupons', $body, ['X-User-Id' => '1', 'X-User-Role' => 'admin']);
    }

    /** VULN-A: 非認証ユーザーはクーポン作成不可 → 401 */
    public function testVULN_A_unauthenticatedCannotCreateCoupon(): void
    {
        $result = $this->post('/coupons', ['code' => 'HACK', 'discount_pct' => 50]);
        $this->assertSame(401, $result['status'], 'VULN-A: unauthenticated must be 401');
    }

    /** VULN-B: 一般ユーザーはクーポン作成不可 → 403 */
    public function testVULN_B_regularUserCannotCreateCoupon(): void
    {
        $result = $this->post('/coupons', ['code' => 'HACK', 'discount_pct' => 50], [
            'X-User-Id' => '2',
            'X-User-Role' => 'user',
        ]);
        $this->assertSame(403, $result['status'], 'VULN-B: regular user must be 403');
    }

    /** VULN-C: 一般ユーザーは利用履歴閲覧不可 → 403 */
    public function testVULN_C_regularUserCannotListUses(): void
    {
        $this->adminCreate('HIST', 10);
        $result = $this->get('/coupons/HIST/uses', ['X-User-Id' => '2', 'X-User-Role' => 'user']);
        $this->assertSame(403, $result['status'], 'VULN-C: use history must be admin-only');
    }

    /** VULN-D: 一般ユーザーはクーポン無効化不可 → 403 */
    public function testVULN_D_regularUserCannotDeactivateCoupon(): void
    {
        $this->adminCreate('ACTIVE', 10);
        $result = $this->delete('/coupons/ACTIVE', ['X-User-Id' => '2', 'X-User-Role' => 'user']);
        $this->assertSame(403, $result['status'], 'VULN-D: deactivation must be admin-only');

        $get = $this->get('/coupons/ACTIVE');
        $this->assertTrue($get['body']['is_active'], 'VULN-D: coupon must still be active');
    }

    /** VULN-E: 有効期限切れクーポンは利用不可 → 422 */
    public function testVULN_E_expiredCouponCannotBeUsed(): void
    {
        $this->adminCreate('EXPIRED', 10, '2000-01-01T00:00:00+00:00');
        $result = $this->post('/coupons/EXPIRED/use', [], ['X-User-Id' => '2']);
        $this->assertSame(422, $result['status'], 'VULN-E: expired coupon must be rejected');
        $this->assertStringContainsString('expired', $result['body']['error']);
    }

    /** VULN-F: 無効化済みクーポンは利用不可 → 422 */
    public function testVULN_F_inactiveCouponCannotBeUsed(): void
    {
        $this->adminCreate('DISABLED', 10);
        $this->delete('/coupons/DISABLED', ['X-User-Id' => '1', 'X-User-Role' => 'admin']);

        $result = $this->post('/coupons/DISABLED/use', [], ['X-User-Id' => '2']);
        $this->assertSame(422, $result['status'], 'VULN-F: inactive coupon must be rejected');
        $this->assertStringContainsString('not active', $result['body']['error']);
    }

    /** VULN-G: 同一ユーザーの二重利用防止 → 422 */
    public function testVULN_G_sameUserCannotUseTwice(): void
    {
        $this->adminCreate('ONCE', 10);
        $first = $this->post('/coupons/ONCE/use', [], ['X-User-Id' => '2']);
        $this->assertSame(201, $first['status']);

        $second = $this->post('/coupons/ONCE/use', [], ['X-User-Id' => '2']);
        $this->assertSame(422, $second['status'], 'VULN-G: same user double-use must be 422');
        $this->assertStringContainsString('already used', $second['body']['error']);

        $get = $this->get('/coupons/ONCE');
        $this->assertSame(1, $get['body']['use_count'], 'VULN-G: use_count must remain 1');
    }

    /** VULN-H: max_uses 超過後の利用防止 → 422 */
    public function testVULN_H_maxUsesEnforced(): void
    {
        $this->adminCreate('LIMIT', 10, null, 2);
        $this->post('/coupons/LIMIT/use', [], ['X-User-Id' => '2']);
        $this->post('/coupons/LIMIT/use', [], ['X-User-Id' => '3']);

        $result = $this->post('/coupons/LIMIT/use', [], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status'], 'VULN-H: exceeding max_uses must be rejected');
        $this->assertStringContainsString('limit', $result['body']['error']);
    }

    /** VULN-I: discount_pct=0 は拒否 → 422 */
    public function testVULN_I_zeroDiscountRejected(): void
    {
        $result = $this->post('/coupons', ['code' => 'ZERO', 'discount_pct' => 0], [
            'X-User-Id' => '1',
            'X-User-Role' => 'admin',
        ]);
        $this->assertSame(422, $result['status'], 'VULN-I: discount_pct=0 must be rejected');
    }

    /** VULN-J: discount_pct=101 は拒否 → 422 */
    public function testVULN_J_discountOver100Rejected(): void
    {
        $result = $this->post('/coupons', ['code' => 'OVER', 'discount_pct' => 101], [
            'X-User-Id' => '1',
            'X-User-Role' => 'admin',
        ]);
        $this->assertSame(422, $result['status'], 'VULN-J: discount_pct > 100 must be rejected');
    }

    /** VULN-K: SQL インジェクション試みに 404 を返す（parameterized query が機能している） */
    public function testVULN_K_sqlInjectionInCodeReturns404(): void
    {
        $this->adminCreate('LEGIT', 10);

        $result = $this->get("/coupons/' OR '1'='1");
        $this->assertSame(404, $result['status'], 'VULN-K: SQL injection must not match all coupons');

        $result2 = $this->get('/coupons/LEGIT%27%20OR%20%271%27%3D%271');
        $this->assertSame(404, $result2['status'], 'VULN-K: URL-encoded injection must be 404');
    }

    /** VULN-L: user_id はボディではなく X-User-Id ヘッダーから取得 */
    public function testVULN_L_userIdCannotBeSpoofedViaBody(): void
    {
        $this->adminCreate('SPOOF', 10);

        $result = $this->post('/coupons/SPOOF/use', ['user_id' => 999], ['X-User-Id' => '2']);
        $this->assertSame(201, $result['status']);
        $this->assertSame(2, $result['body']['user_id'], 'VULN-L: user_id must come from X-User-Id header, not body');

        $uses = $this->get('/coupons/SPOOF/uses', ['X-User-Id' => '1', 'X-User-Role' => 'admin']);
        $this->assertSame(2, $uses['body']['uses'][0]['user_id'], 'VULN-L: recorded user_id must be 2 (from header)');
    }
}
