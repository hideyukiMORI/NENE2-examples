<?php

declare(strict_types=1);

namespace CartLog\Tests\Cart;

use CartLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class CartTest extends TestCase
{
    private PDO $pdo;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $this->pdo->exec($schema);

        // Seed users
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Alice', '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Bob', '2026-01-01T00:00:00Z')");

        // Seed products
        $this->pdo->exec("INSERT INTO products (name, price, stock, created_at) VALUES ('Widget', 500, 100, '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO products (name, price, stock, created_at) VALUES ('Gadget', 1200, 50, '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO products (name, price, stock, created_at) VALUES ('Doohickey', 300, 10, '2026-01-01T00:00:00Z')");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @param array<string, string> $headers */
    private function req(
        string $method,
        string $path,
        array $headers = [],
        mixed $body = null,
    ): ResponseInterface {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();

        $uri = $psr17->createUri('http://localhost' . $path);
        /** @var \Psr\Http\Message\ServerRequestInterface $request */
        $request = $psr17->createServerRequest($method, $uri);

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

    // ─── GET /cart ────────────────────────────────────────────────────────

    public function testGetCartRequiresAuth(): void
    {
        $res = $this->req('GET', '/cart');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testGetCartUnknownUser(): void
    {
        $res = $this->req('GET', '/cart', ['X-User-Id' => '999']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGetCartEmpty(): void
    {
        $res = $this->req('GET', '/cart', ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame([], $data['items']);
        $this->assertSame(0, $data['total']);
        $this->assertSame(0, $data['count']);
    }

    // ─── POST /cart/items ─────────────────────────────────────────────────

    public function testAddItemRequiresAuth(): void
    {
        $res = $this->req('POST', '/cart/items', [], ['product_id' => 1, 'quantity' => 1]);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testAddItemReturns201ForNew(): void
    {
        $res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(1, $data['product_id']);
        $this->assertSame(2, $data['quantity']);
        $this->assertSame(1000, $data['subtotal']); // 500 * 2
    }

    public function testAddItemAccumulatesQuantity(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(5, $data['quantity']);
        $this->assertSame(2500, $data['subtotal']); // 500 * 5
    }

    public function testAddItemUnknownUser(): void
    {
        $res = $this->req('POST', '/cart/items', ['X-User-Id' => '999'], ['product_id' => 1, 'quantity' => 1]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testAddItemUnknownProduct(): void
    {
        $res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 999, 'quantity' => 1]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testAddItemValidationMissingFields(): void
    {
        $res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], []);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testAddItemValidationQuantityMustBeInt(): void
    {
        $res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => '2']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testAddItemValidationQuantityMustBePositive(): void
    {
        $res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 0]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testAddItemValidationProductIdMustBeInt(): void
    {
        $res = $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => '1', 'quantity' => 1]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ─── GET /cart with items ─────────────────────────────────────────────

    public function testGetCartWithItems(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 2, 'quantity' => 1]);

        $res = $this->req('GET', '/cart', ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(2, $data['count']);
        // 500*2 + 1200*1 = 2200
        $this->assertSame(2200, $data['total']);
    }

    public function testGetCartIsolatedByUser(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
        $res = $this->req('GET', '/cart', ['X-User-Id' => '2']);
        $data = $this->json($res);
        $this->assertSame(0, $data['count']);
        $this->assertSame(0, $data['total']);
    }

    // ─── PUT /cart/items/{productId} ─────────────────────────────────────

    public function testUpdateItemRequiresAuth(): void
    {
        $res = $this->req('PUT', '/cart/items/1', [], ['quantity' => 5]);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testUpdateItemNotInCart(): void
    {
        $res = $this->req('PUT', '/cart/items/1', ['X-User-Id' => '1'], ['quantity' => 5]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testUpdateItemQuantity(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $res = $this->req('PUT', '/cart/items/1', ['X-User-Id' => '1'], ['quantity' => 10]);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(10, $data['quantity']);
        $this->assertSame(5000, $data['subtotal']); // 500 * 10
    }

    public function testUpdateItemQuantityZeroDeletesItem(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $res = $this->req('PUT', '/cart/items/1', ['X-User-Id' => '1'], ['quantity' => 0]);
        $this->assertSame(204, $res->getStatusCode());

        $cartRes = $this->req('GET', '/cart', ['X-User-Id' => '1']);
        $data = $this->json($cartRes);
        $this->assertSame(0, $data['count']);
    }

    public function testUpdateItemValidationNegativeQuantity(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $res = $this->req('PUT', '/cart/items/1', ['X-User-Id' => '1'], ['quantity' => -1]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testUpdateItemValidationNonInt(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $res = $this->req('PUT', '/cart/items/1', ['X-User-Id' => '1'], ['quantity' => '5']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ─── DELETE /cart/items/{productId} ──────────────────────────────────

    public function testRemoveItemRequiresAuth(): void
    {
        $res = $this->req('DELETE', '/cart/items/1');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testRemoveItemNotInCart(): void
    {
        $res = $this->req('DELETE', '/cart/items/1', ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testRemoveItem(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 2, 'quantity' => 1]);

        $res = $this->req('DELETE', '/cart/items/1', ['X-User-Id' => '1']);
        $this->assertSame(204, $res->getStatusCode());

        $cartRes = $this->req('GET', '/cart', ['X-User-Id' => '1']);
        $data = $this->json($cartRes);
        $this->assertSame(1, $data['count']);
        $this->assertSame(1200, $data['total']); // only Gadget remains
    }

    // ─── DELETE /cart ─────────────────────────────────────────────────────

    public function testClearCartRequiresAuth(): void
    {
        $res = $this->req('DELETE', '/cart');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testClearCart(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 2, 'quantity' => 1]);

        $res = $this->req('DELETE', '/cart', ['X-User-Id' => '1']);
        $this->assertSame(204, $res->getStatusCode());

        $cartRes = $this->req('GET', '/cart', ['X-User-Id' => '1']);
        $data = $this->json($cartRes);
        $this->assertSame(0, $data['count']);
        $this->assertSame(0, $data['total']);
    }

    public function testClearCartDoesNotAffectOtherUsers(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $this->req('POST', '/cart/items', ['X-User-Id' => '2'], ['product_id' => 2, 'quantity' => 1]);

        $this->req('DELETE', '/cart', ['X-User-Id' => '1']);

        $cartRes = $this->req('GET', '/cart', ['X-User-Id' => '2']);
        $data = $this->json($cartRes);
        $this->assertSame(1, $data['count']);
    }

    // ─── total calculation ────────────────────────────────────────────────

    public function testTotalCalculation(): void
    {
        // Widget: 500 * 3 = 1500, Gadget: 1200 * 2 = 2400, Doohickey: 300 * 1 = 300
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 3]);
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 2, 'quantity' => 2]);
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 3, 'quantity' => 1]);

        $res = $this->req('GET', '/cart', ['X-User-Id' => '1']);
        $data = $this->json($res);
        $this->assertSame(4200, $data['total']);
        $this->assertSame(3, $data['count']);
    }

    public function testTotalUpdatesAfterRemoval(): void
    {
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 1, 'quantity' => 2]);
        $this->req('POST', '/cart/items', ['X-User-Id' => '1'], ['product_id' => 2, 'quantity' => 1]);
        $this->req('DELETE', '/cart/items/1', ['X-User-Id' => '1']);

        $res = $this->req('GET', '/cart', ['X-User-Id' => '1']);
        $data = $this->json($res);
        $this->assertSame(1200, $data['total']);
    }
}
