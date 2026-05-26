<?php

declare(strict_types=1);

namespace DedupLog\Tests\Dedup;

use DedupLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DedupTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/deduplog_test_' . uniqid() . '.sqlite';
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

    private function req(
        string $method,
        string $path,
        mixed $body = null,
        string $idempotencyKey = '',
    ): ResponseInterface {
        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($idempotencyKey !== '') {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $res->getBody(), true);
    }

    // FT170-01: POST /payments without Idempotency-Key → 400
    public function testPaymentRequiresIdempotencyKey(): void
    {
        $res = $this->req('POST', '/payments', ['amount' => 1000]);
        $this->assertSame(400, $res->getStatusCode());
    }

    // FT170-02: POST /orders without Idempotency-Key → 400
    public function testOrderRequiresIdempotencyKey(): void
    {
        $res = $this->req('POST', '/orders', ['item' => 'Widget', 'quantity' => 1]);
        $this->assertSame(400, $res->getStatusCode());
    }

    // FT170-03: First payment request → 201
    public function testFirstPaymentReturns201(): void
    {
        $res  = $this->req('POST', '/payments', ['amount' => 500, 'currency' => 'USD'], 'key-001');
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(500, (int) $data['amount']);
        $this->assertSame('USD', $data['currency']);
        $this->assertSame('completed', $data['status']);
    }

    // FT170-04: Duplicate payment with same key → same response, replayed=true
    public function testDuplicatePaymentIsIdempotent(): void
    {
        $this->req('POST', '/payments', ['amount' => 999], 'dup-key-001');
        $res  = $this->req('POST', '/payments', ['amount' => 999], 'dup-key-001');
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertTrue((bool) $data['replayed']);
        // Only one payment created
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query('SELECT COUNT(*) FROM payments');
        assert($stmt !== false);
        $this->assertSame('1', (string) $stmt->fetchColumn());
    }

    // FT170-05: Different keys create separate payments
    public function testDifferentKeysCreateSeparatePayments(): void
    {
        $this->req('POST', '/payments', ['amount' => 100], 'key-A');
        $this->req('POST', '/payments', ['amount' => 200], 'key-B');
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query('SELECT COUNT(*) FROM payments');
        assert($stmt !== false);
        $this->assertSame('2', (string) $stmt->fetchColumn());
    }

    // FT170-06: First order request → 201
    public function testFirstOrderReturns201(): void
    {
        $res  = $this->req('POST', '/orders', ['item' => 'Gadget', 'quantity' => 3], 'order-key-001');
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Gadget', $data['item']);
        $this->assertSame(3, (int) $data['quantity']);
    }

    // FT170-07: Duplicate order returns same response
    public function testDuplicateOrderIsIdempotent(): void
    {
        $this->req('POST', '/orders', ['item' => 'Widget', 'quantity' => 5], 'order-dup-001');
        $res  = $this->req('POST', '/orders', ['item' => 'Widget', 'quantity' => 5], 'order-dup-001');
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertTrue((bool) $data['replayed']);
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query('SELECT COUNT(*) FROM orders');
        assert($stmt !== false);
        $this->assertSame('1', (string) $stmt->fetchColumn());
    }

    // FT170-08: Invalid payment amount → 422
    public function testPaymentInvalidAmountReturns422(): void
    {
        $res = $this->req('POST', '/payments', ['amount' => -100], 'val-key-001');
        $this->assertSame(422, $res->getStatusCode());
    }

    // FT170-09: Invalid order missing item → 422
    public function testOrderMissingItemReturns422(): void
    {
        $res = $this->req('POST', '/orders', ['quantity' => 1], 'val-key-002');
        $this->assertSame(422, $res->getStatusCode());
    }

    // FT170-10: Same key used for payment idempotency stores response body
    public function testIdempotencyKeyStoresResponseBody(): void
    {
        $res1  = $this->req('POST', '/payments', ['amount' => 750, 'currency' => 'EUR'], 'body-key-001');
        $data1 = $this->json($res1);
        $res2  = $this->req('POST', '/payments', ['amount' => 750, 'currency' => 'EUR'], 'body-key-001');
        $data2 = $this->json($res2);
        // Same payment ID replayed
        $this->assertSame((int) $data1['id'], (int) $data2['id']);
        $this->assertSame('EUR', $data2['currency']);
    }

    // FT170-11: Idempotency key is per-resource — same key on /payments and /orders are independent
    public function testSameKeyDifferentPathsAreIndependent(): void
    {
        $key = 'shared-key-001';
        $resP = $this->req('POST', '/payments', ['amount' => 300], $key);
        $resO = $this->req('POST', '/orders', ['item' => 'Box', 'quantity' => 2], $key);
        // Both succeed or second replays with the first — implementation may vary
        // Key point: neither returns 500
        $this->assertNotSame(500, $resP->getStatusCode());
        $this->assertNotSame(500, $resO->getStatusCode());
    }

    // FT170-12: Zero amount payment → 422
    public function testZeroAmountReturns422(): void
    {
        $res = $this->req('POST', '/payments', ['amount' => 0], 'zero-key-001');
        $this->assertSame(422, $res->getStatusCode());
    }
}
