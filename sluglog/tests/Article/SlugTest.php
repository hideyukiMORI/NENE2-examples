<?php

declare(strict_types=1);

namespace Sluglog\Tests\Article;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sluglog\Article\ArticleRepository;
use Sluglog\Article\RouteRegistrar;
use Sluglog\Article\SlugHelper;

class SlugTest extends TestCase
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
        $repository = new ArticleRepository($executor);
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
    private function post(string $path, array $body = []): array
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
    private function put(string $path, array $body = []): array
    {
        $request  = (new ServerRequest('PUT', $path, ['Content-Type' => 'application/json']))
            ->withBody($this->psr17->createStream(json_encode($body) ?: '{}'));
        $response = $this->router->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    // ── SlugHelper unit tests ─────────────────────────────────────────────

    public function test_slug_helper_lowercases_and_hyphenates(): void
    {
        $this->assertSame('hello-world', SlugHelper::fromTitle('Hello World'));
    }

    public function test_slug_helper_collapses_special_chars(): void
    {
        $this->assertSame('php-8-4-new-features', SlugHelper::fromTitle('PHP 8.4: New Features!'));
    }

    public function test_slug_helper_trims_hyphens(): void
    {
        $this->assertSame('hello', SlugHelper::fromTitle('  --Hello--  '));
    }

    public function test_slug_helper_empty_title_returns_untitled(): void
    {
        $this->assertSame('untitled', SlugHelper::fromTitle(''));
        $this->assertSame('untitled', SlugHelper::fromTitle('---'));
    }

    public function test_slug_helper_make_unique_appends_counter(): void
    {
        $taken = ['hello', 'hello-2', 'hello-3'];
        $slug  = SlugHelper::makeUnique('hello', fn (string $s): bool => in_array($s, $taken, true));
        $this->assertSame('hello-4', $slug);
    }

    // ── Tests: create ─────────────────────────────────────────────────────

    public function test_create_generates_slug_from_title(): void
    {
        $res = $this->post('/articles', ['title' => 'My First Post', 'body' => 'Content.']);

        $this->assertSame(201, $res['status']);
        $this->assertSame('my-first-post', $res['body']['slug']);
    }

    public function test_create_collision_appends_counter(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'Body 1.']);
        $res = $this->post('/articles', ['title' => 'Hello', 'body' => 'Body 2.']);

        $this->assertSame(201, $res['status']);
        $this->assertSame('hello-2', $res['body']['slug']);
    }

    public function test_create_three_collisions_increments_to_three(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'Body 1.']);
        $this->post('/articles', ['title' => 'Hello', 'body' => 'Body 2.']);
        $res = $this->post('/articles', ['title' => 'Hello', 'body' => 'Body 3.']);

        $this->assertSame(201, $res['status']);
        $this->assertSame('hello-3', $res['body']['slug']);
    }

    // ── Tests: get by slug ────────────────────────────────────────────────

    public function test_get_by_current_slug(): void
    {
        $created = $this->post('/articles', ['title' => 'My Post', 'body' => 'Body.']);
        $slug    = $created['body']['slug'];

        $res = $this->get("/articles/by-slug/{$slug}");

        $this->assertSame(200, $res['status']);
        $this->assertSame('my-post', $res['body']['slug']);
    }

    public function test_get_by_slug_returns_404_for_unknown(): void
    {
        $res = $this->get('/articles/by-slug/non-existent-slug');
        $this->assertSame(404, $res['status']);
    }

    public function test_get_by_old_slug_returns_301_with_canonical(): void
    {
        $created = $this->post('/articles', ['title' => 'Old Title', 'body' => 'Body.']);
        $id      = $created['body']['id'];
        $oldSlug = $created['body']['slug'];

        // Rename — triggers slug history
        $this->put("/articles/{$id}", ['title' => 'New Title', 'body' => 'Body.']);

        $res = $this->get("/articles/by-slug/{$oldSlug}");

        $this->assertSame(301, $res['status']);
        $this->assertTrue($res['body']['redirect']);
        $this->assertSame('new-title', $res['body']['canonical_slug']);
    }

    // ── Tests: update ─────────────────────────────────────────────────────

    public function test_update_title_changes_slug_and_records_history(): void
    {
        $created = $this->post('/articles', ['title' => 'Old Title', 'body' => 'Body.']);
        $id      = $created['body']['id'];

        $res = $this->put("/articles/{$id}", ['title' => 'New Title', 'body' => 'Body.']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('new-title', $res['body']['slug']);

        $history = $this->get("/articles/{$id}/slug-history");
        $this->assertCount(1, $history['body']['slug_history']);
        $this->assertSame('old-title', $history['body']['slug_history'][0]['old_slug']);
    }

    public function test_update_with_explicit_slug(): void
    {
        $created = $this->post('/articles', ['title' => 'My Title', 'body' => 'Body.']);
        $id      = $created['body']['id'];

        $res = $this->put("/articles/{$id}", [
            'title' => 'My Title',
            'body'  => 'Body.',
            'slug'  => 'custom-url-here',
        ]);

        $this->assertSame(200, $res['status']);
        $this->assertSame('custom-url-here', $res['body']['slug']);
    }

    public function test_update_same_title_keeps_slug_unchanged(): void
    {
        $created     = $this->post('/articles', ['title' => 'Stable', 'body' => 'Body.']);
        $id          = $created['body']['id'];
        $originalSlug = $created['body']['slug'];

        $res = $this->put("/articles/{$id}", ['title' => 'Stable', 'body' => 'Updated body.']);

        $this->assertSame(200, $res['status']);
        $this->assertSame($originalSlug, $res['body']['slug']);

        $history = $this->get("/articles/{$id}/slug-history");
        $this->assertCount(0, $history['body']['slug_history']); // no slug change = no history
    }

    public function test_update_new_slug_collision_resolves_automatically(): void
    {
        // Existing article with slug 'popular'
        $this->post('/articles', ['title' => 'Popular', 'body' => 'Body.']);

        // Second article, rename to 'Popular' → should get 'popular-2'
        $second = $this->post('/articles', ['title' => 'Other', 'body' => 'Body.']);
        $id     = $second['body']['id'];

        $res = $this->put("/articles/{$id}", ['title' => 'Popular', 'body' => 'Body.']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('popular-2', $res['body']['slug']);
    }

    public function test_slug_history_not_duplicated_on_repeated_same_slug(): void
    {
        $created = $this->post('/articles', ['title' => 'A', 'body' => 'Body.']);
        $id      = $created['body']['id'];

        // First rename: a → b
        $this->put("/articles/{$id}", ['title' => 'B', 'body' => 'Body.']);
        // Second rename back to a — 'b' should go to history
        $this->put("/articles/{$id}", ['title' => 'A2', 'body' => 'Body.']);

        $history = $this->get("/articles/{$id}/slug-history");
        // Should have 'a' and 'b' in history (2 entries)
        $this->assertCount(2, $history['body']['slug_history']);
    }

    public function test_update_returns_404_for_unknown_id(): void
    {
        $res = $this->put('/articles/9999', ['title' => 'X', 'body' => 'Y']);
        $this->assertSame(404, $res['status']);
    }

    // ── Tests: slug history endpoint ──────────────────────────────────────

    public function test_slug_history_empty_for_new_article(): void
    {
        $created = $this->post('/articles', ['title' => 'Fresh', 'body' => 'Body.']);
        $id      = $created['body']['id'];

        $res = $this->get("/articles/{$id}/slug-history");

        $this->assertSame(200, $res['status']);
        $this->assertCount(0, $res['body']['slug_history']);
        $this->assertSame('fresh', $res['body']['current_slug']);
    }

    public function test_slug_history_returns_404_for_unknown_article(): void
    {
        $res = $this->get('/articles/9999/slug-history');
        $this->assertSame(404, $res['status']);
    }
}
