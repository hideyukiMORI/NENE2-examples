<?php

declare(strict_types=1);

namespace CollectionLog\Tests\Collection;

use CollectionLog\Collection\CollectionRepository;
use CollectionLog\Collection\RouteRegistrar;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
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
        $this->pdo->exec("INSERT INTO articles (title, created_at) VALUES ('Article 1', '$now')");
        $this->pdo->exec("INSERT INTO articles (title, created_at) VALUES ('Article 2', '$now')");
        $this->pdo->exec("INSERT INTO articles (title, created_at) VALUES ('Article 3', '$now')");

        $this->psr17 = new Psr17Factory();
        $this->router = $this->buildRouterWithPdo($this->pdo);
    }

    private function buildRouterWithPdo(\PDO $pdo): Router
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
        $repository = new CollectionRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();
        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();
        return $router;
    }

    /** @param array<string, string> $headers */
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

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = []): array
    {
        $request = new ServerRequest('GET', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /** @param array<string, string> $headers */
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

    /** @param array<string, string> $headers */
    private function delete(string $path, array $headers = []): array
    {
        $request = new ServerRequest('DELETE', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    private function createCollection(string $name, bool $isPublic = false, int $userId = 1): array
    {
        return $this->post('/collections', ['name' => $name, 'is_public' => $isPublic], ['X-User-Id' => (string) $userId]);
    }

    public function testCreateCollection_returns201(): void
    {
        $result = $this->createCollection('My Reading List');
        $this->assertSame(201, $result['status']);
        $this->assertSame('My Reading List', $result['body']['name']);
        $this->assertFalse($result['body']['is_public']);
        $this->assertSame(0, $result['body']['item_count']);
    }

    public function testCreateCollection_public(): void
    {
        $result = $this->createCollection('Public List', true);
        $this->assertSame(201, $result['status']);
        $this->assertTrue($result['body']['is_public']);
    }

    public function testCreateCollection_missingName_returns422(): void
    {
        $result = $this->post('/collections', ['is_public' => false], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);
    }

    public function testCreateCollection_noAuth_returns401(): void
    {
        $result = $this->post('/collections', ['name' => 'Test']);
        $this->assertSame(401, $result['status']);
    }

    public function testGetCollection_ownerCanSeePrivate(): void
    {
        $create = $this->createCollection('Private List', false);
        $id = $create['body']['id'];

        $result = $this->get("/collections/$id", ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('Private List', $result['body']['name']);
    }

    public function testGetCollection_publicVisibleToOthers(): void
    {
        $create = $this->createCollection('Public List', true);
        $id = $create['body']['id'];

        $result = $this->get("/collections/$id", ['X-User-Id' => '2']);
        $this->assertSame(200, $result['status']);
    }

    public function testGetCollection_privateHiddenFromOthers(): void
    {
        $create = $this->createCollection('Private List', false);
        $id = $create['body']['id'];

        $result = $this->get("/collections/$id", ['X-User-Id' => '2']);
        $this->assertSame(404, $result['status'], 'Private collection must return 404 to non-owners');
    }

    public function testGetCollection_notFound(): void
    {
        $result = $this->get('/collections/999', ['X-User-Id' => '1']);
        $this->assertSame(404, $result['status']);
    }

    public function testUpdateCollection_nameAndVisibility(): void
    {
        $create = $this->createCollection('Old Name', false);
        $id = $create['body']['id'];

        $result = $this->put("/collections/$id", ['name' => 'New Name', 'is_public' => true], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('New Name', $result['body']['name']);
        $this->assertTrue($result['body']['is_public']);
    }

    public function testUpdateCollection_otherUserForbidden(): void
    {
        $create = $this->createCollection('My List', false, 1);
        $id = $create['body']['id'];

        $result = $this->put("/collections/$id", ['name' => 'Hijacked'], ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testDeleteCollection(): void
    {
        $create = $this->createCollection('To Delete');
        $id = $create['body']['id'];

        $result = $this->delete("/collections/$id", ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);

        $get = $this->get("/collections/$id", ['X-User-Id' => '1']);
        $this->assertSame(404, $get['status']);
    }

    public function testDeleteCollection_otherUserForbidden(): void
    {
        $create = $this->createCollection('My List');
        $id = $create['body']['id'];

        $result = $this->delete("/collections/$id", ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testAddItem_returns201(): void
    {
        $create = $this->createCollection('My List');
        $id = $create['body']['id'];

        $result = $this->post("/collections/$id/items", ['article_id' => 1], ['X-User-Id' => '1']);
        $this->assertSame(201, $result['status']);
    }

    public function testAddItem_idempotent_returns200(): void
    {
        $create = $this->createCollection('My List');
        $id = $create['body']['id'];

        $this->post("/collections/$id/items", ['article_id' => 1], ['X-User-Id' => '1']);
        $result = $this->post("/collections/$id/items", ['article_id' => 1], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
    }

    public function testAddItem_articleNotFound(): void
    {
        $create = $this->createCollection('My List');
        $id = $create['body']['id'];

        $result = $this->post("/collections/$id/items", ['article_id' => 999], ['X-User-Id' => '1']);
        $this->assertSame(404, $result['status']);
    }

    public function testAddItem_otherUserForbidden(): void
    {
        $create = $this->createCollection('My List');
        $id = $create['body']['id'];

        $result = $this->post("/collections/$id/items", ['article_id' => 1], ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testGetCollection_includesItemsInOrder(): void
    {
        $create = $this->createCollection('My List');
        $id = $create['body']['id'];

        $this->post("/collections/$id/items", ['article_id' => 3], ['X-User-Id' => '1']);
        $this->post("/collections/$id/items", ['article_id' => 1], ['X-User-Id' => '1']);

        $result = $this->get("/collections/$id", ['X-User-Id' => '1']);
        $items = $result['body']['items'];
        $this->assertCount(2, $items);
        $this->assertSame(3, $items[0]['article_id']);
        $this->assertSame(1, $items[1]['article_id']);
        $this->assertSame(1, $items[0]['position']);
        $this->assertSame(2, $items[1]['position']);
    }

    public function testRemoveItem(): void
    {
        $create = $this->createCollection('My List');
        $id = $create['body']['id'];

        $this->post("/collections/$id/items", ['article_id' => 1], ['X-User-Id' => '1']);
        $this->post("/collections/$id/items", ['article_id' => 2], ['X-User-Id' => '1']);

        $result = $this->delete("/collections/$id/items/1", ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);

        $get = $this->get("/collections/$id", ['X-User-Id' => '1']);
        $this->assertSame(1, $get['body']['item_count']);
        $this->assertSame(1, $get['body']['items'][0]['position'], 'Position must compact after removal');
    }

    public function testRemoveItem_notInCollection(): void
    {
        $create = $this->createCollection('My List');
        $id = $create['body']['id'];

        $result = $this->delete("/collections/$id/items/1", ['X-User-Id' => '1']);
        $this->assertSame(404, $result['status']);
    }

    public function testRemoveItem_otherUserForbidden(): void
    {
        $create = $this->createCollection('My List');
        $id = $create['body']['id'];

        $this->post("/collections/$id/items", ['article_id' => 1], ['X-User-Id' => '1']);
        $result = $this->delete("/collections/$id/items/1", ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }
}
