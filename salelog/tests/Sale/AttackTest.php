<?php

declare(strict_types=1);

namespace Sale\Tests\Sale;

use Sale\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cracker-mindset attack tests for FT140.
 *
 * Each test attempts a realistic attack on the flash sale system
 * and asserts that the system rejects or neutralises it.
 */
final class AttackTest extends TestCase
{
    private string $dbPath;
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/salelog_attack_' . uniqid() . '.sqlite';
        $schema       = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec($schema);

        $this->app = AppFactory::createSqliteApp($this->dbPath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    private function request(string $method, string $path, mixed $body = null, string $actorId = ''): ResponseInterface
    {
        $req = new ServerRequest($method, $path);

        if ($body !== null) {
            $req = $req
                ->withBody(Stream::create((string) json_encode($body)))
                ->withHeader('Content-Type', 'application/json');
        }

        if ($actorId !== '') {
            $req = $req->withHeader('X-User-Id', $actorId);
        }

        return $this->app->handle($req);
    }

    private function json(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function createUser(string $name): int
    {
        return (int) $this->json($this->request('POST', '/users', ['name' => $name]))['id'];
    }

    private function createProduct(string $name): int
    {
        return (int) $this->json($this->request('POST', '/products', ['name' => $name]))['id'];
    }

    private function createActiveSale(int $productId, int $quantity = 5): int
    {
        return (int) $this->json($this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 100,
            'quantity'   => $quantity,
            'starts_at'  => date('c', strtotime('-1 hour')),
            'ends_at'    => date('c', strtotime('+1 hour')),
        ]))['id'];
    }

    /** ATK-01: SQL injection in product name */
    public function testSqlInjectionInProductName(): void
    {
        $res = $this->request('POST', '/products', ['name' => "'; DROP TABLE products; --"]);
        // Should succeed (201) with the literal malicious string stored verbatim
        $this->assertSame(201, $res->getStatusCode());

        // Follow-up requests should still work — table not dropped
        $productId = (int) $this->json($res)['id'];
        $res2      = $this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 100,
            'quantity'   => 1,
            'starts_at'  => date('c', strtotime('-1 hour')),
            'ends_at'    => date('c', strtotime('+1 hour')),
        ]);
        $this->assertSame(201, $res2->getStatusCode());
    }

    /** ATK-02: Purchase without X-User-Id header */
    public function testPurchaseWithoutActorHeader(): void
    {
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId);

        $res = $this->request('POST', "/sales/{$saleId}/purchase");
        $this->assertSame(400, $res->getStatusCode());
    }

    /** ATK-03: Non-numeric X-User-Id header */
    public function testNonNumericActorHeader(): void
    {
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId);

        $res = $this->request('POST', "/sales/{$saleId}/purchase", actorId: 'admin');
        $this->assertNotSame(201, $res->getStatusCode());
    }

    /** ATK-04: Negative saleId in URL */
    public function testNegativeSaleIdInUrl(): void
    {
        $alice = $this->createUser('Alice');

        $res = $this->request('POST', '/sales/-1/purchase', actorId: (string) $alice);
        $this->assertNotSame(201, $res->getStatusCode());
    }

    /** ATK-05: Buy before sale starts (time manipulation attempt) */
    public function testPurchaseBeforeSaleStarts(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget');

        $saleId = (int) $this->json($this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 100,
            'quantity'   => 5,
            'starts_at'  => date('c', strtotime('+1 hour')),
            'ends_at'    => date('c', strtotime('+2 hours')),
        ]))['id'];

        $res = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode());
    }

    /** ATK-06: Buy after sale ends */
    public function testPurchaseAfterSaleEnds(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget');

        $saleId = (int) $this->json($this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 100,
            'quantity'   => 5,
            'starts_at'  => date('c', strtotime('-2 hours')),
            'ends_at'    => date('c', strtotime('-1 hour')),
        ]))['id'];

        $res = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode());
    }

    /** ATK-07: Double-purchase same sale (race condition simulation) */
    public function testDoublePurchaseSameSale(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId, 10);

        $first  = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);
        $second = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(409, $second->getStatusCode());
    }

    /** ATK-08: Exhaust stock then try to buy */
    public function testExhaustStockThenBuy(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId, 1);

        $first  = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);
        $this->assertSame(201, $first->getStatusCode());

        $bob   = $this->createUser('Bob');
        $extra = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $bob);
        $this->assertSame(422, $extra->getStatusCode());
        $this->assertSame('sold out', $this->json($extra)['error']);
    }

    /** ATK-09: Create sale with quantity=0 (bypass stock check attempt) */
    public function testCreateSaleWithZeroQuantity(): void
    {
        $productId = $this->createProduct('Widget');
        $res       = $this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 100,
            'quantity'   => 0,
            'starts_at'  => date('c', strtotime('-1 hour')),
            'ends_at'    => date('c', strtotime('+1 hour')),
        ]);

        $this->assertSame(422, $res->getStatusCode());
    }

    /** ATK-10: Create sale with negative price */
    public function testCreateSaleWithNegativePrice(): void
    {
        $productId = $this->createProduct('Widget');
        $res       = $this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => -999,
            'quantity'   => 5,
            'starts_at'  => date('c', strtotime('-1 hour')),
            'ends_at'    => date('c', strtotime('+1 hour')),
        ]);

        $this->assertSame(422, $res->getStatusCode());
    }

    /** ATK-11: IDOR — purchase as non-existent user */
    public function testPurchaseAsNonExistentUser(): void
    {
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId);

        $res = $this->request('POST', "/sales/{$saleId}/purchase", actorId: '99999');
        $this->assertSame(404, $res->getStatusCode());
    }

    /** ATK-12: ends_at before starts_at (time inversion) */
    public function testCreateSaleWithInvertedDates(): void
    {
        $productId = $this->createProduct('Widget');
        $res       = $this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 100,
            'quantity'   => 5,
            'starts_at'  => date('c', strtotime('+2 hours')),
            'ends_at'    => date('c', strtotime('+1 hour')),
        ]);

        $this->assertSame(422, $res->getStatusCode());
    }
}
