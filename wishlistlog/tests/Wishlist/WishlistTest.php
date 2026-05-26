<?php

declare(strict_types=1);

namespace WishlistLog\Tests\Wishlist;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use WishlistLog\Wishlist\RouteRegistrar;
use WishlistLog\Wishlist\WishlistRepository;

class WishlistTest extends TestCase
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
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Alice', '$now')");
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Bob', '$now')");
        $this->pdo->exec("INSERT INTO products (name, created_at) VALUES ('Product A', '$now')");
        $this->pdo->exec("INSERT INTO products (name, created_at) VALUES ('Product B', '$now')");
        $this->pdo->exec("INSERT INTO products (name, created_at) VALUES ('Product C', '$now')");

        $this->psr17 = new Psr17Factory();
        $this->router = $this->buildRouter($this->pdo);
    }

    private function buildRouter(\PDO $pdo): Router
    {
        $factory = new class ($pdo) implements DatabaseConnectionFactoryInterface {
            public function __construct(private readonly \PDO $pdo)
            {
            }
            public function create(): \PDO
            {
                return $this->pdo;
            }
        };
        $executor = new PdoDatabaseQueryExecutor($factory);
        $repository = new WishlistRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();
        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();
        return $router;
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
    private function put(string $path, mixed $body = [], array $headers = []): array
    {
        $allHeaders = array_merge(['Content-Type' => 'application/json'], $headers);
        $request = new ServerRequest('PUT', $path, $allHeaders);
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
    private function delete(string $path, array $headers = []): array
    {
        $request = new ServerRequest('DELETE', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /** @return array<string, mixed> */
    private function createWishlist(string $name, bool $isPublic = false, int $userId = 1): array
    {
        return $this->post('/wishlists', ['name' => $name, 'is_public' => $isPublic], ['X-User-Id' => (string) $userId]);
    }

    public function testCreateWishlist_returns201(): void
    {
        $result = $this->createWishlist('My Wishlist');
        $this->assertSame(201, $result['status']);
        $this->assertSame('My Wishlist', $result['body']['name']);
        $this->assertFalse($result['body']['is_public']);
        $this->assertSame(0, $result['body']['item_count']);
        $this->assertSame([], $result['body']['items']);
    }

    public function testCreateWishlist_public(): void
    {
        $result = $this->createWishlist('Public List', true);
        $this->assertSame(201, $result['status']);
        $this->assertTrue($result['body']['is_public']);
    }

    public function testCreateWishlist_noAuth_returns401(): void
    {
        $result = $this->post('/wishlists', ['name' => 'Test']);
        $this->assertSame(401, $result['status']);
    }

    public function testCreateWishlist_missingName_returns422(): void
    {
        $result = $this->post('/wishlists', ['is_public' => false], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);
    }

    public function testGetWishlist_ownerCanSeePrivate(): void
    {
        $create = $this->createWishlist('Private');
        $id = $create['body']['id'];

        $result = $this->get("/wishlists/$id", ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('Private', $result['body']['name']);
    }

    public function testGetWishlist_publicVisibleToOthers(): void
    {
        $create = $this->createWishlist('Public', true);
        $id = $create['body']['id'];

        $result = $this->get("/wishlists/$id", ['X-User-Id' => '2']);
        $this->assertSame(200, $result['status']);
    }

    public function testGetWishlist_privateHiddenFromOthers_returns404(): void
    {
        $create = $this->createWishlist('Private');
        $id = $create['body']['id'];

        $result = $this->get("/wishlists/$id", ['X-User-Id' => '2']);
        $this->assertSame(404, $result['status']);
    }

    public function testGetWishlist_notFound_returns404(): void
    {
        $result = $this->get('/wishlists/999', ['X-User-Id' => '1']);
        $this->assertSame(404, $result['status']);
    }

    public function testUpdateWishlist_nameAndVisibility(): void
    {
        $create = $this->createWishlist('Old Name');
        $id = $create['body']['id'];

        $result = $this->put("/wishlists/$id", ['name' => 'New Name', 'is_public' => true], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('New Name', $result['body']['name']);
        $this->assertTrue($result['body']['is_public']);
    }

    public function testUpdateWishlist_otherUserForbidden_returns403(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $result = $this->put("/wishlists/$id", ['name' => 'Hijacked'], ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testDeleteWishlist_returns200(): void
    {
        $create = $this->createWishlist('To Delete');
        $id = $create['body']['id'];

        $result = $this->delete("/wishlists/$id", ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);

        $get = $this->get("/wishlists/$id", ['X-User-Id' => '1']);
        $this->assertSame(404, $get['status']);
    }

    public function testDeleteWishlist_otherUserForbidden_returns403(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $result = $this->delete("/wishlists/$id", ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testAddItem_returns201(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $result = $this->post("/wishlists/$id/items", ['product_id' => 1], ['X-User-Id' => '1']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('medium', $result['body']['priority']);
        $this->assertNull($result['body']['note']);
    }

    public function testAddItem_withPriorityAndNote(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $result = $this->post("/wishlists/$id/items", [
            'product_id' => 1,
            'priority' => 'high',
            'note' => 'Birthday gift idea',
        ], ['X-User-Id' => '1']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('high', $result['body']['priority']);
        $this->assertSame('Birthday gift idea', $result['body']['note']);
    }

    public function testAddItem_invalidPriority_defaultsToMedium(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $result = $this->post("/wishlists/$id/items", ['product_id' => 1, 'priority' => 'urgent'], ['X-User-Id' => '1']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('medium', $result['body']['priority']);
    }

    public function testAddItem_idempotent_returns200(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $this->post("/wishlists/$id/items", ['product_id' => 1], ['X-User-Id' => '1']);
        $result = $this->post("/wishlists/$id/items", ['product_id' => 1], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('already in wishlist', $result['body']['message']);
    }

    public function testAddItem_productNotFound_returns404(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $result = $this->post("/wishlists/$id/items", ['product_id' => 999], ['X-User-Id' => '1']);
        $this->assertSame(404, $result['status']);
    }

    public function testAddItem_otherUserForbidden_returns403(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $result = $this->post("/wishlists/$id/items", ['product_id' => 1], ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testGetWishlist_includesItemsWithDetails(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $this->post("/wishlists/$id/items", ['product_id' => 1, 'priority' => 'high'], ['X-User-Id' => '1']);
        $this->post("/wishlists/$id/items", ['product_id' => 2, 'priority' => 'low', 'note' => 'Maybe'], ['X-User-Id' => '1']);

        $result = $this->get("/wishlists/$id", ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['body']['item_count']);
        $items = $result['body']['items'];
        $this->assertCount(2, $items);
        $this->assertSame('Product A', $items[0]['product_name']);
        $this->assertSame('high', $items[0]['priority']);
        $this->assertSame('low', $items[1]['priority']);
        $this->assertSame('Maybe', $items[1]['note']);
    }

    public function testRemoveItem_returns200(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $this->post("/wishlists/$id/items", ['product_id' => 1], ['X-User-Id' => '1']);
        $this->post("/wishlists/$id/items", ['product_id' => 2], ['X-User-Id' => '1']);

        $result = $this->delete("/wishlists/$id/items/1", ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);

        $get = $this->get("/wishlists/$id", ['X-User-Id' => '1']);
        $this->assertSame(1, $get['body']['item_count']);
        $this->assertSame(2, $get['body']['items'][0]['product_id']);
    }

    public function testRemoveItem_notInWishlist_returns404(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $result = $this->delete("/wishlists/$id/items/1", ['X-User-Id' => '1']);
        $this->assertSame(404, $result['status']);
    }

    public function testRemoveItem_otherUserForbidden_returns403(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $this->post("/wishlists/$id/items", ['product_id' => 1], ['X-User-Id' => '1']);
        $result = $this->delete("/wishlists/$id/items/1", ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testDeleteWishlist_alsoDeletesItems(): void
    {
        $create = $this->createWishlist('My List');
        $id = $create['body']['id'];

        $this->post("/wishlists/$id/items", ['product_id' => 1], ['X-User-Id' => '1']);
        $this->delete("/wishlists/$id", ['X-User-Id' => '1']);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM wishlist_items WHERE wishlist_id = $id");
        assert($stmt instanceof \PDOStatement);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
}
