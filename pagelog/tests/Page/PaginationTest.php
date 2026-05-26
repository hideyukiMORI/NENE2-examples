<?php

declare(strict_types=1);

namespace Page\Tests\Page;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Page\RouteRegistrar;
use Page\SqliteArticleRepository;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PaginationTest extends TestCase
{
    private RequestHandlerInterface $app;
    private SqliteArticleRepository $repo;
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/pagelog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig  = new DatabaseConfig(
            url:         null,
            environment: 'test',
            adapter:     'sqlite',
            host:        '',
            port:        1,
            name:        $this->dbFile,
            user:        '',
            password:    '',
            charset:     '',
        );
        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $this->repo = new SqliteArticleRepository($executor);
        $registrar  = new RouteRegistrar($this->repo, $json, $problems);

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
        ))->create();
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // --- helpers ---

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle(new ServerRequest('GET', $path));
    }

    private function post(string $path, mixed $body): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('POST', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- create ---

    public function testCreateArticle(): void
    {
        $res  = $this->post('/articles', ['title' => 'Hello', 'author' => 'Alice']);
        $data = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Hello', $data['title']);
        $this->assertSame('Alice', $data['author']);
        $this->assertSame('general', $data['category']);
    }

    public function testCreateArticleMissingFields(): void
    {
        $res = $this->post('/articles', []);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- offset pagination ---

    public function testOffsetFirstPage(): void
    {
        $this->repo->seed(25);
        $res  = $this->get('/articles/offset?limit=10&offset=0');
        $data = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(10, $data['items']);
        $this->assertSame(25, $data['total']);
        $this->assertTrue($data['has_more']);
        $this->assertSame(10, $data['next_offset']);
    }

    public function testOffsetSecondPage(): void
    {
        $this->repo->seed(25);
        $res  = $this->get('/articles/offset?limit=10&offset=10');
        $data = $this->json($res);

        $this->assertCount(10, $data['items']);
        $this->assertTrue($data['has_more']);
        $this->assertSame(20, $data['next_offset']);
    }

    public function testOffsetLastPage(): void
    {
        $this->repo->seed(25);
        $res  = $this->get('/articles/offset?limit=10&offset=20');
        $data = $this->json($res);

        $this->assertCount(5, $data['items']);
        $this->assertFalse($data['has_more']);
        $this->assertNull($data['next_offset']);
    }

    public function testOffsetEmptyResult(): void
    {
        $this->repo->seed(5);
        $res  = $this->get('/articles/offset?limit=10&offset=100');
        $data = $this->json($res);

        $this->assertCount(0, $data['items']);
        $this->assertFalse($data['has_more']);
    }

    // --- cursor pagination ---

    public function testCursorFirstPage(): void
    {
        $this->repo->seed(25);
        $res  = $this->get('/articles/cursor?limit=10');
        $data = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(10, $data['items']);
        $this->assertTrue($data['has_more']);
        $this->assertNotNull($data['next_cursor']);
    }

    public function testCursorSecondPage(): void
    {
        $this->repo->seed(25);
        $first  = $this->json($this->get('/articles/cursor?limit=10'));
        $cursor = (int) $first['next_cursor'];

        $res  = $this->get("/articles/cursor?limit=10&after={$cursor}");
        $data = $this->json($res);

        $this->assertCount(10, $data['items']);
        // All IDs should be < cursor
        foreach ($data['items'] as $item) {
            $this->assertLessThan($cursor, (int) $item['id']);
        }
    }

    public function testCursorLastPage(): void
    {
        $this->repo->seed(25);
        // Page 1 → 10, page 2 → 10, page 3 → 5
        $p1     = $this->json($this->get('/articles/cursor?limit=10'));
        $p2     = $this->json($this->get('/articles/cursor?limit=10&after=' . (int) $p1['next_cursor']));
        $res    = $this->get('/articles/cursor?limit=10&after=' . (int) $p2['next_cursor']);
        $data   = $this->json($res);

        $this->assertCount(5, $data['items']);
        $this->assertFalse($data['has_more']);
        $this->assertNull($data['next_cursor']);
    }

    public function testCursorNoOverlapBetweenPages(): void
    {
        $this->repo->seed(30);

        $p1 = $this->json($this->get('/articles/cursor?limit=10'));
        $p2 = $this->json($this->get('/articles/cursor?limit=10&after=' . (int) $p1['next_cursor']));
        $p3 = $this->json($this->get('/articles/cursor?limit=10&after=' . (int) $p2['next_cursor']));

        $ids1 = array_column($p1['items'], 'id');
        $ids2 = array_column($p2['items'], 'id');
        $ids3 = array_column($p3['items'], 'id');

        // No duplicates between pages
        $this->assertEmpty(array_intersect($ids1, $ids2));
        $this->assertEmpty(array_intersect($ids2, $ids3));
        // All 30 articles accounted for
        $this->assertCount(30, array_merge($ids1, $ids2, $ids3));
    }

    // --- limit clamping ---

    public function testLimitClampedAt100(): void
    {
        $this->repo->seed(200);
        $offset = $this->json($this->get('/articles/offset?limit=999'));
        $cursor = $this->json($this->get('/articles/cursor?limit=999'));

        $this->assertCount(100, $offset['items']);
        $this->assertCount(100, $cursor['items']);
    }

    public function testLimitMinimumIs1(): void
    {
        $this->repo->seed(5);
        $offset = $this->json($this->get('/articles/offset?limit=0'));
        $cursor = $this->json($this->get('/articles/cursor?limit=0'));

        $this->assertCount(1, $offset['items']);
        $this->assertCount(1, $cursor['items']);
    }

    // --- count ---

    public function testCountEndpoint(): void
    {
        $this->repo->seed(50);
        $res  = $this->get('/articles/count');
        $data = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(50, $data['count']);
    }

    // --- ordering consistency ---

    /** Offset and cursor first pages should return the same articles */
    public function testOffsetAndCursorFirstPageMatch(): void
    {
        $this->repo->seed(20);

        $offset = $this->json($this->get('/articles/offset?limit=10&offset=0'));
        $cursor = $this->json($this->get('/articles/cursor?limit=10'));

        $offsetIds = array_column($offset['items'], 'id');
        $cursorIds = array_column($cursor['items'], 'id');
        $this->assertSame($offsetIds, $cursorIds);
    }

    // --- large dataset: correctness at depth ---

    /**
     * With 500 rows, fetch page 50 (offset=490) via both methods.
     * Correctness check — ensures both approaches return the same tail rows.
     * (Performance difference is not measurable in unit tests but is documented in the FT report.)
     */
    public function testLargeDatasetDeepPageCorrectness(): void
    {
        $this->repo->seed(500);

        // OFFSET deep page: rows 491–500 (the last 10)
        $offsetPage = $this->json($this->get('/articles/offset?limit=10&offset=490'));

        // Cursor deep page: walk to the same position
        // Rows are DESC by id. Row 491-500 means ids 10–1 (the oldest 10).
        // Fastest way: get id of row 490 from offset endpoint, use as cursor.
        $anchor     = $this->json($this->get('/articles/offset?limit=1&offset=489'));
        $anchorId   = (int) $anchor['items'][0]['id'];
        $cursorPage = $this->json($this->get("/articles/cursor?limit=10&after={$anchorId}"));

        $offsetIds = array_column($offsetPage['items'], 'id');
        $cursorIds = array_column($cursorPage['items'], 'id');
        $this->assertSame($offsetIds, $cursorIds);
    }
}
