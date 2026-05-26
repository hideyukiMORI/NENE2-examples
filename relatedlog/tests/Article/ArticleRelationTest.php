<?php

declare(strict_types=1);

namespace Relatedlog\Tests\Article;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Relatedlog\Article\ArticleRepository;
use Relatedlog\Article\RouteRegistrar;

class ArticleRelationTest extends TestCase
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

    /** @return array{status: int, body: array<string, mixed>} */
    private function delete(string $path): array
    {
        $request  = new ServerRequest('DELETE', $path);
        $response = $this->router->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    // ── Helper: create articles ───────────────────────────────────────────

    /** @return array<string, mixed> */
    private function createArticle(string $title = 'Article', string $body = 'Body.'): array
    {
        $res = $this->post('/articles', ['title' => $title, 'body' => $body]);
        $this->assertSame(201, $res['status']);

        return $res['body'];
    }

    // ── Tests: create article ─────────────────────────────────────────────

    public function test_create_article(): void
    {
        $res = $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);

        $this->assertSame(201, $res['status']);
        $this->assertSame('Hello', $res['body']['title']);
    }

    public function test_create_requires_title(): void
    {
        $res = $this->post('/articles', ['body' => 'No title']);
        $this->assertSame(422, $res['status']);
    }

    public function test_create_requires_body(): void
    {
        $res = $this->post('/articles', ['title' => 'No body']);
        $this->assertSame(422, $res['status']);
    }

    // ── Tests: get with relations ─────────────────────────────────────────

    public function test_get_article_no_relations(): void
    {
        $a  = $this->createArticle('Alpha');
        $id = $a['id'];

        $res = $this->get("/articles/{$id}");

        $this->assertSame(200, $res['status']);
        $this->assertSame('Alpha', $res['body']['data']['title']);
        $this->assertSame([], $res['body']['relations']);
    }

    public function test_get_returns_404_for_unknown(): void
    {
        $res = $this->get('/articles/9999');
        $this->assertSame(404, $res['status']);
    }

    // ── Tests: add relation ───────────────────────────────────────────────

    public function test_add_related_relation_is_symmetric(): void
    {
        $a = $this->createArticle('A');
        $b = $this->createArticle('B');

        $res = $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'related',
        ]);

        $this->assertSame(201, $res['status']);
        $this->assertSame('related', $res['body']['relation_type']);

        // B should also see A in its relations (inverse auto-inserted)
        $bRel = $this->get("/articles/{$b['id']}/relations");
        $this->assertSame(200, $bRel['status']);
        $this->assertCount(1, $bRel['body']['data']);
        $this->assertSame($a['id'], $bRel['body']['data'][0]['related_id']);
        $this->assertSame('related', $bRel['body']['data'][0]['relation_type']);
    }

    public function test_add_sequel_creates_prequel_inverse(): void
    {
        $a = $this->createArticle('Part 1');
        $b = $this->createArticle('Part 2');

        $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'sequel',
        ]);

        // B should see A as a prequel
        $bRel = $this->get("/articles/{$b['id']}/relations");
        $this->assertSame(200, $bRel['status']);
        $this->assertCount(1, $bRel['body']['data']);
        $this->assertSame('prequel', $bRel['body']['data'][0]['relation_type']);
    }

    public function test_add_relation_returns_404_when_article_not_found(): void
    {
        $a = $this->createArticle();

        $res = $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => 9999,
            'relation_type' => 'related',
        ]);

        $this->assertSame(404, $res['status']);
    }

    public function test_add_relation_returns_409_on_duplicate(): void
    {
        $a = $this->createArticle('A');
        $b = $this->createArticle('B');

        $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'related',
        ]);

        $dup = $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'related',
        ]);

        $this->assertSame(409, $dup['status']);
    }

    public function test_add_relation_returns_422_for_self_relation(): void
    {
        $a = $this->createArticle('Solo');

        $res = $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $a['id'],
            'relation_type' => 'related',
        ]);

        $this->assertSame(422, $res['status']);
    }

    public function test_add_relation_returns_422_for_invalid_type(): void
    {
        $a = $this->createArticle();
        $b = $this->createArticle();

        $res = $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'not-a-type',
        ]);

        $this->assertSame(422, $res['status']);
    }

    // ── Tests: list relations ─────────────────────────────────────────────

    public function test_list_all_relations(): void
    {
        $a = $this->createArticle('A');
        $b = $this->createArticle('B');
        $c = $this->createArticle('C');

        $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'related',
        ]);
        $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $c['id'],
            'relation_type' => 'reference',
        ]);

        $res = $this->get("/articles/{$a['id']}/relations");

        $this->assertSame(200, $res['status']);
        $this->assertCount(2, $res['body']['data']);
    }

    public function test_list_relations_filtered_by_type(): void
    {
        $a = $this->createArticle('A');
        $b = $this->createArticle('B');
        $c = $this->createArticle('C');

        $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'related',
        ]);
        $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $c['id'],
            'relation_type' => 'sequel',
        ]);

        $res = $this->get("/articles/{$a['id']}/relations?type=sequel");

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
        $this->assertSame('sequel', $res['body']['data'][0]['relation_type']);
    }

    public function test_list_relations_returns_404_for_unknown_article(): void
    {
        $res = $this->get('/articles/9999/relations');
        $this->assertSame(404, $res['status']);
    }

    // ── Tests: remove relation ────────────────────────────────────────────

    public function test_remove_relation_also_removes_inverse(): void
    {
        $a = $this->createArticle('A');
        $b = $this->createArticle('B');

        $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'related',
        ]);

        $del = $this->delete("/articles/{$a['id']}/relations/{$b['id']}?type=related");

        $this->assertSame(200, $del['status']);
        $this->assertTrue($del['body']['deleted']);

        // A has no relations
        $aRel = $this->get("/articles/{$a['id']}/relations");
        $this->assertCount(0, $aRel['body']['data']);

        // B has no relations (inverse also removed)
        $bRel = $this->get("/articles/{$b['id']}/relations");
        $this->assertCount(0, $bRel['body']['data']);
    }

    public function test_remove_relation_returns_404_when_not_found(): void
    {
        $a = $this->createArticle('A');
        $b = $this->createArticle('B');

        $res = $this->delete("/articles/{$a['id']}/relations/{$b['id']}?type=related");
        $this->assertSame(404, $res['status']);
    }

    public function test_remove_relation_requires_type_param(): void
    {
        $a = $this->createArticle('A');
        $b = $this->createArticle('B');

        $res = $this->delete("/articles/{$a['id']}/relations/{$b['id']}");
        $this->assertSame(422, $res['status']);
    }

    // ── Tests: GET includes embedded related stubs ────────────────────────

    public function test_get_embeds_related_article_stubs(): void
    {
        $a = $this->createArticle('Intro');
        $b = $this->createArticle('Follow-up');

        $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'sequel',
        ]);

        $res = $this->get("/articles/{$a['id']}");

        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['relations']);
        $this->assertSame('Follow-up', $res['body']['relations'][0]['related']['title']);
        $this->assertSame('sequel', $res['body']['relations'][0]['relation']['relation_type']);
    }

    // ── Tests: multiple relation types between same pair ─────────────────

    public function test_multiple_types_between_same_pair(): void
    {
        $a = $this->createArticle('A');
        $b = $this->createArticle('B');

        // Same pair, different types → both valid
        $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'related',
        ]);
        $res = $this->post("/articles/{$a['id']}/relations", [
            'related_id'    => $b['id'],
            'relation_type' => 'reference',
        ]);

        $this->assertSame(201, $res['status']);

        $list = $this->get("/articles/{$a['id']}/relations");
        $this->assertCount(2, $list['body']['data']);
    }
}
