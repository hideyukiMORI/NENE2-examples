<?php

declare(strict_types=1);

namespace CqrsLog\Tests\Order;

use CqrsLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class CqrsTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $pdo->exec($schema);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $headers */
    private function req(string $method, string $path, array $headers = [], mixed $body = null, ?string $query = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        if ($query !== null) {
            parse_str($query, $q);
            $request = $request->withQueryParams($q);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        assert(is_array($decoded));
        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function place(string $customer, array $items): array
    {
        $res = $this->req('POST', '/orders', [], ['customer' => $customer, 'items' => $items]);
        assert($res->getStatusCode() === 201);
        return $this->json($res);
    }

    // ── command then query ────────────────────────────────────────────────────

    public function testPlaceOrderReturnsReadModelProjection(): void
    {
        $order = $this->place('Alice', [
            ['product' => 'Widget', 'quantity' => 2, 'unit_price' => 500],
            ['product' => 'Gadget', 'quantity' => 1, 'unit_price' => 1500],
        ]);
        // response shape comes from the VIEW: computed item_count + total_cents
        $this->assertSame('Alice', $order['customer']);
        $this->assertSame('pending', $order['status']);
        $this->assertSame(2, $order['item_count']);
        $this->assertSame(2500, $order['total_cents']); // 2*500 + 1*1500
    }

    public function testGetOrderReadsFromView(): void
    {
        $id = (int) $this->place('Bob', [['product' => 'X', 'quantity' => 3, 'unit_price' => 100]])['id'];
        $data = $this->json($this->req('GET', '/orders/' . $id));
        $this->assertSame(300, $data['total_cents']);
        $this->assertSame(1, $data['item_count']);
    }

    public function testUpdateStatusReflectedInReadModel(): void
    {
        $id = (int) $this->place('Carol', [['product' => 'X', 'quantity' => 1, 'unit_price' => 100]])['id'];
        $res = $this->req('PATCH', '/orders/' . $id . '/status', [], ['status' => 'shipped']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('shipped', $this->json($res)['status']);
        // read side agrees
        $this->assertSame('shipped', $this->json($this->req('GET', '/orders/' . $id))['status']);
    }

    // ── list + filter ─────────────────────────────────────────────────────────

    public function testListAndStatusFilter(): void
    {
        $a = (int) $this->place('A', [['product' => 'X', 'quantity' => 1, 'unit_price' => 1]])['id'];
        $this->place('B', [['product' => 'Y', 'quantity' => 1, 'unit_price' => 1]]);
        $this->req('PATCH', '/orders/' . $a . '/status', [], ['status' => 'paid']);

        $this->assertSame(2, $this->json($this->req('GET', '/orders'))['total']);
        $paid = $this->json($this->req('GET', '/orders', [], null, 'status=paid'));
        $this->assertSame(1, $paid['total']);
        $this->assertSame('A', $paid['data'][0]['customer']);
    }

    // ── validation (write side) ──────────────────────────────────────────────────

    public function testCustomerRequired(): void
    {
        $this->assertSame(422, $this->req('POST', '/orders', [], ['items' => [['product' => 'X', 'quantity' => 1, 'unit_price' => 1]]])->getStatusCode());
    }

    public function testEmptyItemsRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/orders', [], ['customer' => 'A', 'items' => []])->getStatusCode());
    }

    public function testFloatQuantityRejected(): void
    {
        $res = $this->req('POST', '/orders', [], ['customer' => 'A', 'items' => [['product' => 'X', 'quantity' => 1.5, 'unit_price' => 100]]]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testStringQuantityRejected(): void
    {
        $res = $this->req('POST', '/orders', [], ['customer' => 'A', 'items' => [['product' => 'X', 'quantity' => '2', 'unit_price' => 100]]]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testNegativeUnitPriceRejected(): void
    {
        $res = $this->req('POST', '/orders', [], ['customer' => 'A', 'items' => [['product' => 'X', 'quantity' => 1, 'unit_price' => -1]]]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testZeroUnitPriceAllowed(): void
    {
        $order = $this->place('A', [['product' => 'Free', 'quantity' => 1, 'unit_price' => 0]]);
        $this->assertSame(0, $order['total_cents']);
    }

    public function testInvalidStatusRejected(): void
    {
        $id = (int) $this->place('A', [['product' => 'X', 'quantity' => 1, 'unit_price' => 1]])['id'];
        $this->assertSame(422, $this->req('PATCH', '/orders/' . $id . '/status', [], ['status' => 'teleported'])->getStatusCode());
    }

    public function testUpdateUnknownOrderIs404(): void
    {
        $this->assertSame(404, $this->req('PATCH', '/orders/999/status', [], ['status' => 'paid'])->getStatusCode());
    }

    public function testGetUnknownOrderIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/orders/999')->getStatusCode());
    }
}
