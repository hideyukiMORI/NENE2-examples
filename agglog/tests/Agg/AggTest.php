<?php

declare(strict_types=1);

namespace AggLog\Tests\Agg;

use AggLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AggTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/agglog_test_' . uniqid() . '.sqlite';
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

    private function order(string $customerId, string $itemName, int $amount, string $status = 'completed'): void
    {
        $this->req('POST', '/orders', [
            'customer_id' => $customerId, 'item_name' => $itemName,
            'amount' => $amount, 'status' => $status,
        ]);
    }

    // =========================================================================

    public function testCreateOrderReturns201(): void
    {
        $res = $this->req('POST', '/orders', [
            'customer_id' => 'cust-1', 'item_name' => 'Widget', 'amount' => 1000,
        ]);
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testCreateOrderRequiresPositiveAmount(): void
    {
        $res = $this->req('POST', '/orders', [
            'customer_id' => 'c', 'item_name' => 'X', 'amount' => 0,
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateOrderRequiresCustomerId(): void
    {
        $res = $this->req('POST', '/orders', ['item_name' => 'X', 'amount' => 100]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testSummaryReturnsAggregates(): void
    {
        $this->order('c1', 'A', 1000);
        $this->order('c2', 'B', 2000);
        $this->order('c3', 'C', 3000, 'pending');

        $data = $this->json($this->req('GET', '/reports/summary'));
        $this->assertSame(3, (int) $data['total_orders']);
        $this->assertSame(6000, (int) $data['total_revenue']);
        $this->assertSame(2, (int) $data['completed_orders']);
    }

    public function testSummaryEmptyReturnsZeros(): void
    {
        $data = $this->json($this->req('GET', '/reports/summary'));
        $this->assertSame(0, (int) $data['total_orders']);
        $this->assertSame(0, (int) $data['total_revenue']);
    }

    public function testDailyBreakdown(): void
    {
        $this->order('c1', 'A', 500);
        $this->order('c2', 'B', 1500);

        $data = $this->json($this->req('GET', '/reports/daily'));
        $this->assertGreaterThanOrEqual(1, $data['count']);
    }

    public function testByStatusGrouping(): void
    {
        $this->order('c1', 'A', 100, 'completed');
        $this->order('c2', 'B', 200, 'completed');
        $this->order('c3', 'C', 300, 'refunded');

        $data     = $this->json($this->req('GET', '/reports/by-status'));
        $statuses = array_column($data['statuses'], 'status');
        $this->assertContains('completed', $statuses);
        $this->assertContains('refunded', $statuses);
    }

    public function testTopItemsSortedByRevenue(): void
    {
        $this->order('c1', 'cheap', 100);
        $this->order('c2', 'cheap', 100);
        $this->order('c3', 'expensive', 5000);

        $data  = $this->json($this->req('GET', '/reports/top-items'));
        $items = $data['items'];
        $this->assertSame('expensive', $items[0]['item_name']);
    }

    public function testTopItemsLimitClamped(): void
    {
        $res  = $this->req('GET', '/reports/top-items', queryParams: ['limit' => '999']);
        $data = $this->json($res);
        $this->assertSame(100, (int) $data['limit']); // clamped to MAX_LIMIT
    }

    public function testDateRangeFilter(): void
    {
        // Use direct PDO to insert with specific dates
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec("INSERT INTO orders (customer_id, item_name, amount, status, created_at) VALUES ('c1','A',100,'completed','2026-01-15T00:00:00Z')");
        $pdo->exec("INSERT INTO orders (customer_id, item_name, amount, status, created_at) VALUES ('c2','B',200,'completed','2026-03-20T00:00:00Z')");

        $data = $this->json($this->req('GET', '/reports/summary', queryParams: [
            'from' => '2026-01-01', 'to' => '2026-02-01',
        ]));
        $this->assertSame(1, (int) $data['total_orders']);
        $this->assertSame(100, (int) $data['total_revenue']);
    }

    public function testInvalidDateReturns422(): void
    {
        $res = $this->req('GET', '/reports/summary', queryParams: ['from' => 'not-a-date']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testFromAfterToReturns422(): void
    {
        $res = $this->req('GET', '/reports/summary', queryParams: [
            'from' => '2026-12-31', 'to' => '2026-01-01',
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testInvalidLimitReturns422(): void
    {
        $res = $this->req('GET', '/reports/top-items', queryParams: ['limit' => 'abc']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testNegativeLimitReturns422(): void
    {
        $res = $this->req('GET', '/reports/top-items', queryParams: ['limit' => '-1']);
        $this->assertSame(422, $res->getStatusCode());
    }
}
