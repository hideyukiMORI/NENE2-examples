<?php

declare(strict_types=1);

namespace Order\Tests\Order;

use Order\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OrderTest extends TestCase
{
    private string $dbPath;
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/orderlog_test_' . uniqid() . '.sqlite';
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

    private function createProduct(string $name, int $price, int $stock = 10): int
    {
        return (int) $this->json($this->request('POST', '/products', ['name' => $name, 'price' => $price, 'stock' => $stock]))['id'];
    }

    public function testCreateUser(): void
    {
        $res = $this->request('POST', '/users', ['name' => 'Alice']);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertArrayHasKey('id', $this->json($res));
    }

    public function testCreateUserMissingName(): void
    {
        $res = $this->request('POST', '/users', ['name' => '']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateProduct(): void
    {
        $res = $this->request('POST', '/products', ['name' => 'Widget', 'price' => 500, 'stock' => 10]);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertArrayHasKey('id', $this->json($res));
    }

    public function testCreateProductMissingName(): void
    {
        $res = $this->request('POST', '/products', ['price' => 500]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateProductNegativePrice(): void
    {
        $res = $this->request('POST', '/products', ['name' => 'Widget', 'price' => -1]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testAddToCart(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget', 500);

        $res = $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 2], actorId: (string) $alice);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testAddToCartMissingActor(): void
    {
        $productId = $this->createProduct('Widget', 500);
        $res       = $this->request('POST', '/cart', ['product_id' => $productId]);
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testAddToCartUnknownProduct(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', '/cart', ['product_id' => 9999, 'quantity' => 1], actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testAddToCartAccumulatesQuantity(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget', 500);

        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 2], actorId: (string) $alice);
        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 3], actorId: (string) $alice);

        $cart = $this->json($this->request('GET', '/cart', actorId: (string) $alice));
        $this->assertSame(5, $cart['items'][0]['quantity']);
    }

    public function testGetCart(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget', 500);

        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 2], actorId: (string) $alice);

        $cart = $this->json($this->request('GET', '/cart', actorId: (string) $alice));
        $this->assertSame(1, $cart['count']);
        $this->assertSame(1000, $cart['total']);
        $this->assertSame('Widget', $cart['items'][0]['name']);
    }

    public function testGetCartEmpty(): void
    {
        $alice = $this->createUser('Alice');
        $cart  = $this->json($this->request('GET', '/cart', actorId: (string) $alice));
        $this->assertSame(0, $cart['count']);
        $this->assertSame(0, $cart['total']);
    }

    public function testRemoveFromCart(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget', 500);

        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 1], actorId: (string) $alice);
        $res = $this->request('DELETE', "/cart/{$productId}", actorId: (string) $alice);
        $this->assertSame(204, $res->getStatusCode());

        $cart = $this->json($this->request('GET', '/cart', actorId: (string) $alice));
        $this->assertSame(0, $cart['count']);
    }

    public function testRemoveFromCartNotFound(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('DELETE', '/cart/9999', actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testPlaceOrder(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget', 500, 10);

        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 2], actorId: (string) $alice);

        $res   = $this->request('POST', '/orders', actorId: (string) $alice);
        $order = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertArrayHasKey('id', $order);
        $this->assertSame(1000, $order['total']);
    }

    public function testPlaceOrderClearsCart(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget', 500);

        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 1], actorId: (string) $alice);
        $this->request('POST', '/orders', actorId: (string) $alice);

        $cart = $this->json($this->request('GET', '/cart', actorId: (string) $alice));
        $this->assertSame(0, $cart['count']);
    }

    public function testPlaceOrderDecrementsStock(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget', 500, 5);

        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 3], actorId: (string) $alice);
        $this->request('POST', '/orders', actorId: (string) $alice);

        // New order attempt with remaining stock 2, try ordering 3 → insufficient
        $bob = $this->createUser('Bob');
        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 3], actorId: (string) $bob);
        $res = $this->request('POST', '/orders', actorId: (string) $bob);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testPlaceOrderEmptyCart(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', '/orders', actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testPlaceOrderInsufficientStock(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget', 500, 1);

        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 5], actorId: (string) $alice);
        $res = $this->request('POST', '/orders', actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame($productId, $this->json($res)['product_id']);
    }

    public function testGetOrder(): void
    {
        $alice     = $this->createUser('Alice');
        $productId = $this->createProduct('Widget', 500, 10);

        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 2], actorId: (string) $alice);
        $orderId = (int) $this->json($this->request('POST', '/orders', actorId: (string) $alice))['id'];

        $res   = $this->request('GET', "/orders/{$orderId}", actorId: (string) $alice);
        $order = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($orderId, $order['id']);
        $this->assertSame(1000, $order['total']);
        $this->assertCount(1, $order['items']);
        $this->assertSame('Widget', $order['items'][0]['name']);
        $this->assertSame(2, $order['items'][0]['quantity']);
    }

    public function testGetOrderForbiddenForOtherUser(): void
    {
        $alice     = $this->createUser('Alice');
        $bob       = $this->createUser('Bob');
        $productId = $this->createProduct('Widget', 500);

        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 1], actorId: (string) $alice);
        $orderId = (int) $this->json($this->request('POST', '/orders', actorId: (string) $alice))['id'];

        $res = $this->request('GET', "/orders/{$orderId}", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testGetOrderNotFound(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('GET', '/orders/9999', actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testMultipleProductsInCart(): void
    {
        $alice    = $this->createUser('Alice');
        $product1 = $this->createProduct('Widget', 500, 10);
        $product2 = $this->createProduct('Gadget', 300, 10);

        $this->request('POST', '/cart', ['product_id' => $product1, 'quantity' => 2], actorId: (string) $alice);
        $this->request('POST', '/cart', ['product_id' => $product2, 'quantity' => 3], actorId: (string) $alice);

        $cart = $this->json($this->request('GET', '/cart', actorId: (string) $alice));
        $this->assertSame(2, $cart['count']);
        $this->assertSame(1900, $cart['total']); // 500*2 + 300*3

        $orderId = (int) $this->json($this->request('POST', '/orders', actorId: (string) $alice))['id'];
        $order   = $this->json($this->request('GET', "/orders/{$orderId}", actorId: (string) $alice));
        $this->assertSame(1900, $order['total']);
        $this->assertCount(2, $order['items']);
    }

    public function testCartIsolatedPerUser(): void
    {
        $alice     = $this->createUser('Alice');
        $bob       = $this->createUser('Bob');
        $productId = $this->createProduct('Widget', 500);

        $this->request('POST', '/cart', ['product_id' => $productId, 'quantity' => 3], actorId: (string) $alice);

        $bobCart = $this->json($this->request('GET', '/cart', actorId: (string) $bob));
        $this->assertSame(0, $bobCart['count']);
    }
}
