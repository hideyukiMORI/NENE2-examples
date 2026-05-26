<?php

declare(strict_types=1);

namespace Hierarchylog\Tests\Category;

use Hierarchylog\AppFactory;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Hierarchylog\Category\CategoryRepository;
use Hierarchylog\Category\RouteRegistrar;

class CategoryTest extends TestCase
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
        $executor   = new PdoDatabaseQueryExecutor($factory);
        $repository = new CategoryRepository($executor);
        $psr17      = new Psr17Factory();
        $response   = new JsonResponseFactory($psr17, $psr17);
        $router     = new Router();
        $registrar  = new RouteRegistrar($router, $repository, $response);
        $registrar->register();

        return $router;
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    /** @return array{status: int, body: array<string, mixed>} */
    private function get(string $path): array
    {
        $request  = new ServerRequest('GET', $path);
        $response = $this->router->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(string $path, array $body): array
    {
        $request  = (new ServerRequest('POST', $path, ['Content-Type' => 'application/json']))
            ->withBody($this->psr17->createStream(json_encode($body) ?: '{}'));
        $response = $this->router->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function put(string $path, array $body): array
    {
        $request  = (new ServerRequest('PUT', $path, ['Content-Type' => 'application/json']))
            ->withBody($this->psr17->createStream(json_encode($body) ?: '{}'));
        $response = $this->router->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function patch(string $path, array $body): array
    {
        $request  = (new ServerRequest('PATCH', $path, ['Content-Type' => 'application/json']))
            ->withBody($this->psr17->createStream(json_encode($body) ?: '{}'));
        $response = $this->router->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function delete(string $path): array
    {
        $request  = new ServerRequest('DELETE', $path);
        $response = $this->router->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    // ── Tests: create ─────────────────────────────────────────────────────

    public function test_create_root_category(): void
    {
        $res = $this->post('/categories', ['name' => 'Root']);

        $this->assertSame(201, $res['status']);
        $this->assertSame('Root', $res['body']['name']);
        $this->assertNull($res['body']['parent_id']);
        $this->assertSame(0, $res['body']['depth']);
        // Materialized path for root should be "/1/"
        $this->assertMatchesRegularExpression('/^\/\d+\/$/', $res['body']['path']);
    }

    public function test_create_child_category(): void
    {
        $root  = $this->post('/categories', ['name' => 'Root']);
        $child = $this->post('/categories', ['name' => 'Child', 'parent_id' => $root['body']['id']]);

        $this->assertSame(201, $child['status']);
        $this->assertSame('Child', $child['body']['name']);
        $this->assertSame($root['body']['id'], $child['body']['parent_id']);
        $this->assertSame(1, $child['body']['depth']);
        // Child path should start with root path
        $this->assertStringStartsWith($root['body']['path'], $child['body']['path']);
    }

    public function test_create_returns_422_when_name_missing(): void
    {
        $res = $this->post('/categories', []);
        $this->assertSame(422, $res['status']);
    }

    public function test_create_returns_404_when_parent_not_found(): void
    {
        $res = $this->post('/categories', ['name' => 'Orphan', 'parent_id' => 9999]);
        $this->assertSame(404, $res['status']);
    }

    public function test_create_returns_422_when_depth_exceeded(): void
    {
        // Build a chain of MAX_DEPTH (5) levels: depth 0 to 4
        $parentId = null;
        for ($i = 0; $i < CategoryRepository::MAX_DEPTH; $i++) {
            $res      = $this->post('/categories', ['name' => "Level {$i}", 'parent_id' => $parentId]);
            $parentId = $res['body']['id'];
        }

        // One more level should be rejected
        $res = $this->post('/categories', ['name' => 'TooDeep', 'parent_id' => $parentId]);
        $this->assertSame(422, $res['status']);
    }

    // ── Tests: list ───────────────────────────────────────────────────────

    public function test_list_root_categories(): void
    {
        $this->post('/categories', ['name' => 'A']);
        $this->post('/categories', ['name' => 'B']);

        $res = $this->get('/categories');

        $this->assertSame(200, $res['status']);
        $this->assertCount(2, $res['body']['data']);
    }

    public function test_list_children_by_parent_id(): void
    {
        $root = $this->post('/categories', ['name' => 'Root']);
        $this->post('/categories', ['name' => 'Child1', 'parent_id' => $root['body']['id']]);
        $this->post('/categories', ['name' => 'Child2', 'parent_id' => $root['body']['id']]);

        $res = $this->get('/categories?parent_id=' . $root['body']['id']);

        $this->assertSame(200, $res['status']);
        $this->assertCount(2, $res['body']['data']);
    }

    // ── Tests: get single with ancestors ─────────────────────────────────

    public function test_get_category_with_ancestors(): void
    {
        $root  = $this->post('/categories', ['name' => 'Root']);
        $child = $this->post('/categories', ['name' => 'Child', 'parent_id' => $root['body']['id']]);
        $grand = $this->post('/categories', ['name' => 'Grand', 'parent_id' => $child['body']['id']]);

        $res = $this->get('/categories/' . $grand['body']['id']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('Grand', $res['body']['data']['name']);
        $this->assertCount(2, $res['body']['ancestors']); // Root + Child
        $this->assertSame('Root', $res['body']['ancestors'][0]['name']);
        $this->assertSame('Child', $res['body']['ancestors'][1]['name']);
    }

    public function test_get_returns_404_for_unknown_id(): void
    {
        $res = $this->get('/categories/9999');
        $this->assertSame(404, $res['status']);
    }

    // ── Tests: subtree ────────────────────────────────────────────────────

    public function test_subtree_returns_all_descendants(): void
    {
        $root  = $this->post('/categories', ['name' => 'Root']);
        $id    = $root['body']['id'];
        $child = $this->post('/categories', ['name' => 'Child', 'parent_id' => $id]);
        $this->post('/categories', ['name' => 'Grand', 'parent_id' => $child['body']['id']]);

        $res = $this->get("/categories/{$id}/subtree");

        $this->assertSame(200, $res['status']);
        $this->assertCount(2, $res['body']['data']); // Child + Grand, not Root itself
    }

    public function test_subtree_returns_404_for_unknown_id(): void
    {
        $res = $this->get('/categories/9999/subtree');
        $this->assertSame(404, $res['status']);
    }

    // ── Tests: update name ───────────────────────────────────────────────

    public function test_update_name(): void
    {
        $res = $this->post('/categories', ['name' => 'Old Name']);
        $id  = $res['body']['id'];

        $updated = $this->put("/categories/{$id}", ['name' => 'New Name']);

        $this->assertSame(200, $updated['status']);
        $this->assertSame('New Name', $updated['body']['name']);
    }

    public function test_update_returns_404_for_unknown_id(): void
    {
        $res = $this->put('/categories/9999', ['name' => 'X']);
        $this->assertSame(404, $res['status']);
    }

    // ── Tests: move ───────────────────────────────────────────────────────

    public function test_move_to_another_parent(): void
    {
        $rootA = $this->post('/categories', ['name' => 'A']);
        $rootB = $this->post('/categories', ['name' => 'B']);
        $child = $this->post('/categories', ['name' => 'Child', 'parent_id' => $rootA['body']['id']]);

        $moved = $this->patch('/categories/' . $child['body']['id'] . '/move', [
            'parent_id' => $rootB['body']['id'],
        ]);

        $this->assertSame(200, $moved['status']);
        $this->assertSame($rootB['body']['id'], $moved['body']['parent_id']);
        $this->assertStringStartsWith($rootB['body']['path'], $moved['body']['path']);
    }

    public function test_move_to_root(): void
    {
        $root  = $this->post('/categories', ['name' => 'Root']);
        $child = $this->post('/categories', ['name' => 'Child', 'parent_id' => $root['body']['id']]);

        $moved = $this->patch('/categories/' . $child['body']['id'] . '/move', ['parent_id' => null]);

        $this->assertSame(200, $moved['status']);
        $this->assertNull($moved['body']['parent_id']);
        $this->assertSame(0, $moved['body']['depth']);
    }

    public function test_move_circular_to_self_returns_422(): void
    {
        $root = $this->post('/categories', ['name' => 'Root']);
        $id   = $root['body']['id'];

        $res = $this->patch("/categories/{$id}/move", ['parent_id' => $id]);
        $this->assertSame(422, $res['status']);
    }

    public function test_move_circular_to_descendant_returns_422(): void
    {
        $root  = $this->post('/categories', ['name' => 'Root']);
        $child = $this->post('/categories', ['name' => 'Child', 'parent_id' => $root['body']['id']]);

        // Try to move root under its own child → circular
        $res = $this->patch('/categories/' . $root['body']['id'] . '/move', [
            'parent_id' => $child['body']['id'],
        ]);
        $this->assertSame(422, $res['status']);
    }

    public function test_move_missing_parent_id_field_returns_422(): void
    {
        $root = $this->post('/categories', ['name' => 'Root']);
        $res  = $this->patch('/categories/' . $root['body']['id'] . '/move', []); // no parent_id key
        $this->assertSame(422, $res['status']);
    }

    // ── Tests: delete ─────────────────────────────────────────────────────

    public function test_delete_leaf_category(): void
    {
        $res = $this->post('/categories', ['name' => 'Leaf']);
        $id  = $res['body']['id'];

        $del = $this->delete("/categories/{$id}");

        $this->assertSame(200, $del['status']);
        $this->assertTrue($del['body']['deleted']);

        // Confirm it's gone
        $get = $this->get("/categories/{$id}");
        $this->assertSame(404, $get['status']);
    }

    public function test_delete_with_children_returns_409(): void
    {
        $root  = $this->post('/categories', ['name' => 'Root']);
        $rootId = $root['body']['id'];
        $this->post('/categories', ['name' => 'Child', 'parent_id' => $rootId]);

        $res = $this->delete("/categories/{$rootId}");
        $this->assertSame(409, $res['status']);
    }

    public function test_delete_returns_404_for_unknown_id(): void
    {
        $res = $this->delete('/categories/9999');
        $this->assertSame(404, $res['status']);
    }
}
