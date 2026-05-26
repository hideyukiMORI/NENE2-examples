<?php

declare(strict_types=1);

namespace PointLog\Tests\Point;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use PointLog\Point\PointRepository;
use PointLog\Point\RouteRegistrar;

/**
 * FT152 クラッカー攻撃試験 — ポイント・ロイヤルティシステム
 *
 * ATK-01: 未認証での残高取得試み → 401
 * ATK-02: 他ユーザーの残高盗み見 → 403
 * ATK-03: 他ユーザーへのポイント自己付与 → 403
 * ATK-04: 負の amount でポイント付与（残高増やし）→ 422
 * ATK-05: amount=0 での空トランザクション → 422
 * ATK-06: 残高超過のポイント消費 → 422（残高が負にならない）
 * ATK-07: 一般ユーザーによる adjust → 403
 * ATK-08: 超大量ポイント付与（MAX_EARN 超過）→ 422
 * ATK-09: reference_id を再利用したダブルクレジット試み → 200（冪等、残高不変）
 * ATK-10: reference_id を再利用したダブルデビット試み → 200（冪等、残高不変）
 * ATK-11: SQLインジェクションを含む reference_id → 正常処理（parameterized query）
 * ATK-12: 浮動小数点数 amount でポイント操作 → 422（整数のみ許可）
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
        $repository = new PointRepository($executor);
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

    /** ATK-01: 未認証での残高取得 → 401 */
    public function testATK_01_unauthenticatedBalanceAccess(): void
    {
        $result = $this->get('/users/2/points');
        $this->assertSame(401, $result['status'], 'ATK-01: unauthenticated balance access must be 401');
    }

    /** ATK-02: 他ユーザーの残高盗み見 → 403 */
    public function testATK_02_crossUserBalancePeek(): void
    {
        $result = $this->get('/users/2/points', ['X-User-Id' => '3']);
        $this->assertSame(403, $result['status'], 'ATK-02: cross-user balance peek must be 403');
    }

    /** ATK-03: 他ユーザーへのポイント自己付与 → 403 */
    public function testATK_03_selfGrantToOtherUser(): void
    {
        $result = $this->post('/users/3/points/earn', ['amount' => 99999], ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status'], 'ATK-03: earning points for other user must be 403');

        $balance = $this->get('/users/3/points', ['X-User-Id' => '1', 'X-User-Role' => 'admin']);
        $this->assertSame(0, $balance['body']['balance'], 'ATK-03: target user balance must remain 0');
    }

    /** ATK-04: 負の amount でポイント付与 → 422 */
    public function testATK_04_negativeAmountEarn(): void
    {
        $result = $this->post('/users/2/points/earn', ['amount' => -500], ['X-User-Id' => '2']);
        $this->assertSame(422, $result['status'], 'ATK-04: negative earn amount must be 422');

        $balance = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(0, $balance['body']['balance'], 'ATK-04: balance must not change');
    }

    /** ATK-05: amount=0 での空トランザクション → 422 */
    public function testATK_05_zeroAmountTransaction(): void
    {
        $earn = $this->post('/users/2/points/earn', ['amount' => 0], ['X-User-Id' => '2']);
        $this->assertSame(422, $earn['status'], 'ATK-05: zero earn amount must be 422');

        $spend = $this->post('/users/2/points/spend', ['amount' => 0], ['X-User-Id' => '2']);
        $this->assertSame(422, $spend['status'], 'ATK-05: zero spend amount must be 422');
    }

    /** ATK-06: 残高超過のポイント消費 → 422（残高が負にならない） */
    public function testATK_06_overdraftSpend(): void
    {
        $this->post('/users/2/points/earn', ['amount' => 100], ['X-User-Id' => '2']);
        $result = $this->post('/users/2/points/spend', ['amount' => 101], ['X-User-Id' => '2']);

        $this->assertSame(422, $result['status'], 'ATK-06: overdraft must be 422');

        $balance = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(100, $balance['body']['balance'], 'ATK-06: balance must not go negative');
    }

    /** ATK-07: 一般ユーザーによる adjust → 403 */
    public function testATK_07_regularUserAdjust(): void
    {
        $result = $this->post('/users/2/points/adjust', ['amount' => 99999], ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status'], 'ATK-07: non-admin adjust must be 403');

        $balance = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(0, $balance['body']['balance'], 'ATK-07: balance must remain 0');
    }

    /** ATK-08: 超大量ポイント付与（MAX_EARN 超過）→ 422 */
    public function testATK_08_excessiveEarnAmount(): void
    {
        $result = $this->post('/users/2/points/earn', ['amount' => 10001], ['X-User-Id' => '2']);
        $this->assertSame(422, $result['status'], 'ATK-08: excessive earn must be 422');

        $balance = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(0, $balance['body']['balance'], 'ATK-08: balance must remain 0');
    }

    /** ATK-09: reference_id を再利用したダブルクレジット試み → 200（冪等） */
    public function testATK_09_doubleEarnWithSameReferenceId(): void
    {
        $r1 = $this->post('/users/2/points/earn', ['amount' => 500, 'reference_id' => 'order-999'], ['X-User-Id' => '2']);
        $r2 = $this->post('/users/2/points/earn', ['amount' => 500, 'reference_id' => 'order-999'], ['X-User-Id' => '2']);

        $this->assertSame(201, $r1['status']);
        $this->assertSame(200, $r2['status'], 'ATK-09: duplicate earn must return 200 (idempotent)');
        $this->assertSame($r1['body']['id'], $r2['body']['id'], 'ATK-09: must return same transaction');

        $balance = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(500, $balance['body']['balance'], 'ATK-09: balance must be 500, not 1000');
    }

    /** ATK-10: reference_id を再利用したダブルデビット試み → 200（冪等） */
    public function testATK_10_doubleSpendWithSameReferenceId(): void
    {
        $this->post('/users/2/points/earn', ['amount' => 1000], ['X-User-Id' => '2']);

        $r1 = $this->post('/users/2/points/spend', ['amount' => 300, 'reference_id' => 'redemption-777'], ['X-User-Id' => '2']);
        $r2 = $this->post('/users/2/points/spend', ['amount' => 300, 'reference_id' => 'redemption-777'], ['X-User-Id' => '2']);

        $this->assertSame(201, $r1['status']);
        $this->assertSame(200, $r2['status'], 'ATK-10: duplicate spend must return 200 (idempotent)');

        $balance = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(700, $balance['body']['balance'], 'ATK-10: balance must be 700, not 400');
    }

    /** ATK-11: SQL インジェクションを含む reference_id → 正常処理（parameterized query） */
    public function testATK_11_sqlInjectionInReferenceId(): void
    {
        $injection = "' OR '1'='1' --";
        $result = $this->post('/users/2/points/earn', [
            'amount' => 100,
            'reference_id' => $injection,
        ], ['X-User-Id' => '2']);

        $this->assertSame(201, $result['status'], 'ATK-11: SQL injection in reference_id must be processed normally');
        $this->assertSame($injection, $result['body']['reference_id'], 'ATK-11: reference_id must be stored as-is');

        $balance = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(100, $balance['body']['balance'], 'ATK-11: balance must be 100 (not corrupted)');
    }

    /** ATK-12: 浮動小数点数 amount → 422（整数のみ許可） */
    public function testATK_12_floatAmountRejected(): void
    {
        $result = $this->post('/users/2/points/earn', ['amount' => 10.5], ['X-User-Id' => '2']);
        $this->assertSame(422, $result['status'], 'ATK-12: float amount must be 422 (integer only)');

        $balance = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(0, $balance['body']['balance'], 'ATK-12: balance must remain 0');
    }
}
