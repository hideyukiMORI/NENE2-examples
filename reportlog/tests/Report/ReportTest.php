<?php

declare(strict_types=1);

namespace ReportLog\Tests\Report;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use ReportLog\Report\ReportRepository;
use ReportLog\Report\RouteRegistrar;

class ReportTest extends TestCase
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
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Alice', 'user', '$now')");
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Bob', 'user', '$now')");
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Moderator', 'moderator', '$now')");
        $this->pdo->exec("INSERT INTO articles (title, created_at) VALUES ('Article 1', '$now')");
        $this->pdo->exec("INSERT INTO articles (title, created_at) VALUES ('Article 2', '$now')");

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
        $repository = new ReportRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();
        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();
        return $router;
    }

    private function pdoQuery(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        assert($stmt instanceof \PDOStatement);
        return $stmt;
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

    public function testCreateReport_new(): void
    {
        $result = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('pending', $result['body']['status']);
        $this->assertSame(1, $result['body']['reporter_id']);
        $this->assertSame(1, $result['body']['article_id']);
        $this->assertSame('spam', $result['body']['reason']);
    }

    public function testCreateReport_idempotent(): void
    {
        $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $result = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('pending', $result['body']['status']);

        $count = (int) $this->pdoQuery('SELECT COUNT(*) as c FROM reports')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCreateReport_invalidReason(): void
    {
        $result = $this->post('/reports', ['article_id' => 1, 'reason' => 'hatecrime'], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);
        $this->assertArrayHasKey('valid_reasons', $result['body']);
    }

    public function testCreateReport_articleNotFound(): void
    {
        $result = $this->post('/reports', ['article_id' => 999, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $this->assertSame(404, $result['status']);
    }

    public function testCreateReport_missingFields(): void
    {
        $result = $this->post('/reports', ['reason' => 'spam'], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);

        $result2 = $this->post('/reports', ['article_id' => 1], ['X-User-Id' => '1']);
        $this->assertSame(422, $result2['status']);
    }

    public function testCreateReport_noAuth(): void
    {
        $result = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam']);
        $this->assertSame(401, $result['status']);
    }

    public function testCreateReport_withDetails(): void
    {
        $result = $this->post('/reports', ['article_id' => 1, 'reason' => 'other', 'details' => 'Some detail'], ['X-User-Id' => '1']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('Some detail', $result['body']['details']);
    }

    public function testGetReport_byReporter(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $id = $create['body']['id'];

        $result = $this->get("/reports/$id", ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame($id, $result['body']['id']);
    }

    public function testGetReport_byModerator(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $id = $create['body']['id'];

        $result = $this->get("/reports/$id", ['X-User-Id' => '3']);
        $this->assertSame(200, $result['status']);
    }

    public function testGetReport_otherUserForbidden(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $id = $create['body']['id'];

        $result = $this->get("/reports/$id", ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testGetReport_notFound(): void
    {
        $result = $this->get('/reports/999', ['X-User-Id' => '3']);
        $this->assertSame(404, $result['status']);
    }

    public function testListReports_byModerator(): void
    {
        $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $this->post('/reports', ['article_id' => 2, 'reason' => 'harassment'], ['X-User-Id' => '2']);

        $result = $this->get('/reports', ['X-User-Id' => '3']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['body']['count']);
        $this->assertCount(2, $result['body']['reports']);
    }

    public function testListReports_byRegularUser_forbidden(): void
    {
        $result = $this->get('/reports', ['X-User-Id' => '1']);
        $this->assertSame(403, $result['status']);
    }

    public function testResolveReport(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $id = $create['body']['id'];

        $result = $this->put("/reports/$id/resolve", [], ['X-User-Id' => '3']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('resolved', $result['body']['status']);
        $this->assertSame(3, $result['body']['resolved_by']);
        $this->assertNotNull($result['body']['resolved_at']);
    }

    public function testResolveReport_withNote(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $id = $create['body']['id'];

        $result = $this->put("/reports/$id/resolve", ['resolution_note' => 'Content removed'], ['X-User-Id' => '3']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('Content removed', $result['body']['resolution_note']);
    }

    public function testDismissReport(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $id = $create['body']['id'];

        $result = $this->put("/reports/$id/dismiss", [], ['X-User-Id' => '3']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('dismissed', $result['body']['status']);
    }

    public function testResolveAlreadyResolved(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $id = $create['body']['id'];
        $this->put("/reports/$id/resolve", [], ['X-User-Id' => '3']);

        $result = $this->put("/reports/$id/resolve", [], ['X-User-Id' => '3']);
        $this->assertSame(422, $result['status']);
        $this->assertSame('resolved', $result['body']['current_status']);
    }

    public function testDismissAlreadyDismissed(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $id = $create['body']['id'];
        $this->put("/reports/$id/dismiss", [], ['X-User-Id' => '3']);

        $result = $this->put("/reports/$id/dismiss", [], ['X-User-Id' => '3']);
        $this->assertSame(422, $result['status']);
    }

    public function testResolveReport_unauthorizedUser(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $id = $create['body']['id'];

        $result = $this->put("/reports/$id/resolve", [], ['X-User-Id' => '1']);
        $this->assertSame(403, $result['status']);
    }

    public function testDismissReport_unauthorizedUser(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $id = $create['body']['id'];

        $result = $this->put("/reports/$id/dismiss", [], ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }
}
