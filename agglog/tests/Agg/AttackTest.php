<?php

declare(strict_types=1);

namespace AggLog\Tests\Agg;

use AggLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * クラッカー攻撃試験 ATK-01〜12 (FT168)
 *
 * 攻撃者視点でレポート集計 API の脆弱性を突く12テスト。
 */
final class AttackTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/agglog_atk_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $this->app = AppFactory::createSqlite($this->dbFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $queryParams */
    private function req(string $method, string $path, mixed $body = null, array $queryParams = []): ResponseInterface
    {
        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($queryParams !== []) {
            $request = $request->withQueryParams($queryParams);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $res->getBody(), true);
    }

    // ATK-01: SQLインジェクション — from パラメータに悪意のある文字列
    public function testAtk01SqlInjectionInFromDate(): void
    {
        $res = $this->req('GET', '/reports/summary', queryParams: [
            'from' => "2026-01-01' OR '1'='1",
        ]);
        // Invalid date format → 422
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-02: SQLインジェクション — to パラメータ
    public function testAtk02SqlInjectionInToDate(): void
    {
        $res = $this->req('GET', '/reports/summary', queryParams: [
            'to' => "'; DROP TABLE orders; --",
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-03: 巨大な limit 値でクエリ爆発を試みる
    public function testAtk03HugeLimitIsClamped(): void
    {
        $res  = $this->req('GET', '/reports/top-items', queryParams: ['limit' => '999999999']);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertLessThanOrEqual(100, (int) $data['limit']);
    }

    // ATK-04: 不正な日付形式（月が13）
    public function testAtk04InvalidMonthReturns422(): void
    {
        $res = $this->req('GET', '/reports/daily', queryParams: ['from' => '2026-13-01']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-05: from > to（逆順日付）
    public function testAtk05FromAfterToReturns422(): void
    {
        $res = $this->req('GET', '/reports/by-status', queryParams: [
            'from' => '2026-12-31', 'to' => '2026-01-01',
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-06: SQLインジェクション — item_name フィールドに悪意のある文字列
    public function testAtk06SqlInjectionInItemName(): void
    {
        $res = $this->req('POST', '/orders', [
            'customer_id' => 'cust',
            'item_name'   => "Widget'; DROP TABLE orders; --",
            'amount'      => 100,
        ]);
        // Should succeed (201) — treated as literal string via parameterized query
        $this->assertSame(201, $res->getStatusCode());
        // DB must still be intact
        $summary = $this->json($this->req('GET', '/reports/summary'));
        $this->assertSame(1, (int) $summary['total_orders']);
    }

    // ATK-07: 非常に大きな amount 値
    public function testAtk07VeryLargeAmount(): void
    {
        $res = $this->req('POST', '/orders', [
            'customer_id' => 'c', 'item_name' => 'big',
            'amount' => PHP_INT_MAX,
        ]);
        // Should succeed — PHP_INT_MAX is valid positive integer
        $this->assertSame(201, $res->getStatusCode());
    }

    // ATK-08: ゼロの amount — 拒否されること
    public function testAtk08ZeroAmountRejected(): void
    {
        $res = $this->req('POST', '/orders', [
            'customer_id' => 'c', 'item_name' => 'free', 'amount' => 0,
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-09: customer_id への SQLインジェクション — 安全に保存される
    public function testAtk09SqlInjectionInCustomerId(): void
    {
        $res = $this->req('POST', '/orders', [
            'customer_id' => "'; DELETE FROM orders; --",
            'item_name'   => 'item',
            'amount'      => 100,
        ]);
        $this->assertSame(201, $res->getStatusCode());
        $summary = $this->json($this->req('GET', '/reports/summary'));
        $this->assertSame(1, (int) $summary['total_orders']);
    }

    // ATK-10: limit に非数値文字列を渡す
    public function testAtk10NonNumericLimit(): void
    {
        $res = $this->req('GET', '/reports/top-items', queryParams: ['limit' => 'abc; DROP TABLE orders;']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-11: 空文字列パラメータ（空 from/to は無視される）
    public function testAtk11EmptyDateParamsIgnored(): void
    {
        $res = $this->req('GET', '/reports/summary', queryParams: ['from' => '', 'to' => '']);
        $this->assertSame(200, $res->getStatusCode()); // empty strings → no filter applied
    }

    // ATK-12: 特殊文字・Unicode を含む item_name が正常に集計される
    public function testAtk12SpecialCharsInItemNameAggregated(): void
    {
        $this->req('POST', '/orders', [
            'customer_id' => 'c1', 'item_name' => "Café & <Crêpes> \"special\"", 'amount' => 500,
        ]);
        $this->req('POST', '/orders', [
            'customer_id' => 'c2', 'item_name' => "Café & <Crêpes> \"special\"", 'amount' => 500,
        ]);

        $data  = $this->json($this->req('GET', '/reports/top-items'));
        $items = $data['items'];
        $this->assertSame(1, count($items));
        $this->assertSame(1000, (int) $items[0]['revenue']);
        $this->assertSame(2, (int) $items[0]['order_count']);
    }
}
