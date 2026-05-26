<?php

declare(strict_types=1);

namespace Sale\Tests\Sale;

use Sale\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SaleTest extends TestCase
{
    private string $dbPath;
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/salelog_test_' . uniqid() . '.sqlite';
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

    private function createActiveSale(int $productId, int $price = 100, int $quantity = 5): int
    {
        $startsAt = date('c', strtotime('-1 hour'));
        $endsAt   = date('c', strtotime('+1 hour'));

        return (int) $this->json($this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => $price,
            'quantity'   => $quantity,
            'starts_at'  => $startsAt,
            'ends_at'    => $endsAt,
        ]))['id'];
    }

    private function createUpcomingSale(int $productId): int
    {
        return (int) $this->json($this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 100,
            'quantity'   => 5,
            'starts_at'  => date('c', strtotime('+1 hour')),
            'ends_at'    => date('c', strtotime('+2 hours')),
        ]))['id'];
    }

    private function createEndedSale(int $productId): int
    {
        return (int) $this->json($this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 100,
            'quantity'   => 5,
            'starts_at'  => date('c', strtotime('-2 hours')),
            'ends_at'    => date('c', strtotime('-1 hour')),
        ]))['id'];
    }

    public function testCreateSale(): void
    {
        $productId = $this->createProduct('Widget');
        $res       = $this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 500,
            'quantity'   => 10,
            'starts_at'  => date('c', strtotime('-1 hour')),
            'ends_at'    => date('c', strtotime('+1 hour')),
        ]);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertArrayHasKey('id', $this->json($res));
    }

    public function testCreateSaleMissingProductId(): void
    {
        $res = $this->request('POST', '/sales', [
            'price'     => 500,
            'quantity'  => 10,
            'starts_at' => date('c'),
            'ends_at'   => date('c', strtotime('+1 hour')),
        ]);

        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateSaleInvalidQuantity(): void
    {
        $productId = $this->createProduct('Widget');
        $res       = $this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 500,
            'quantity'   => 0,
            'starts_at'  => date('c'),
            'ends_at'    => date('c', strtotime('+1 hour')),
        ]);

        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateSaleEndsBeforeStarts(): void
    {
        $productId = $this->createProduct('Widget');
        $res       = $this->request('POST', '/sales', [
            'product_id' => $productId,
            'price'      => 500,
            'quantity'   => 5,
            'starts_at'  => date('c', strtotime('+2 hours')),
            'ends_at'    => date('c', strtotime('+1 hour')),
        ]);

        $this->assertSame(422, $res->getStatusCode());
    }

    public function testGetSale(): void
    {
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId, 500, 10);

        $res  = $this->request('GET', "/sales/{$saleId}");
        $sale = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($saleId, $sale['id']);
        $this->assertSame(500, $sale['price']);
        $this->assertSame(10, $sale['quantity']);
        $this->assertSame(10, $sale['remaining']);
        $this->assertSame('active', $sale['status']);
    }

    public function testGetSaleUpcoming(): void
    {
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createUpcomingSale($productId);

        $sale = $this->json($this->request('GET', "/sales/{$saleId}"));
        $this->assertSame('upcoming', $sale['status']);
    }

    public function testGetSaleEnded(): void
    {
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createEndedSale($productId);

        $sale = $this->json($this->request('GET', "/sales/{$saleId}"));
        $this->assertSame('ended', $sale['status']);
    }

    public function testGetSaleNotFound(): void
    {
        $res = $this->request('GET', '/sales/9999');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testPurchaseSuccess(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId);

        $res = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertTrue($this->json($res)['ok']);
    }

    public function testPurchaseDecrementsRemaining(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId, 100, 3);

        $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);

        $sale = $this->json($this->request('GET', "/sales/{$saleId}"));
        $this->assertSame(2, $sale['remaining']);
    }

    public function testPurchaseDuplicate(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId);

        $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);
        $res = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);

        $this->assertSame(409, $res->getStatusCode());
    }

    public function testPurchaseUpcomingSale(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createUpcomingSale($productId);

        $res = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testPurchaseEndedSale(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createEndedSale($productId);

        $res = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testPurchaseSoldOut(): void
    {
        $alice     = $this->createUser('Alice');
        $bob       = $this->createUser('Bob');
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId, 100, 1);

        $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);
        $res = $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $bob);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('sold out', (string) $this->json($res)['error']);
    }

    public function testPurchaseMissingActor(): void
    {
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId);

        $res = $this->request('POST', "/sales/{$saleId}/purchase");
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testListPurchases(): void
    {
        $alice     = $this->createUser('Alice');
        $bob       = $this->createUser('Bob');
        $productId = $this->createProduct('Widget');
        $saleId    = $this->createActiveSale($productId, 100, 5);

        $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $alice);
        $this->request('POST', "/sales/{$saleId}/purchase", actorId: (string) $bob);

        $res  = $this->request('GET', "/sales/{$saleId}/purchases");
        $list = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(2, $list['count']);
    }

    public function testListPurchasesSaleNotFound(): void
    {
        $res = $this->request('GET', '/sales/9999/purchases');
        $this->assertSame(404, $res->getStatusCode());
    }
}
