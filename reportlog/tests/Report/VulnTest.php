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

/**
 * Vulnerability assessment for FT147: Content Report & Moderation.
 * Verifies RBAC enforcement, IDOR prevention, status transition integrity,
 * and auth bypass resistance.
 */
class VulnTest extends TestCase
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

    private function createReportAsUser1(): int
    {
        $result = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        return (int) $result['body']['id'];
    }

    /**
     * VULN-A: A regular user cannot view another user's report (IDOR).
     * Bob (user_id=2) must not be able to read Alice's report (reporter_id=1).
     */
    public function testVULN_A_userCannotViewOtherUsersReport(): void
    {
        $id = $this->createReportAsUser1();

        $result = $this->get("/reports/$id", ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status'], 'Bob must not access Alice\'s report');
    }

    /**
     * VULN-B: Regular user cannot list all reports (GET /reports).
     * Report index is moderator-only; leaking it enables surveillance.
     */
    public function testVULN_B_regularUserCannotListAllReports(): void
    {
        $this->createReportAsUser1();

        $result = $this->get('/reports', ['X-User-Id' => '1']);
        $this->assertSame(403, $result['status'], 'Regular user must not access report list');
    }

    /**
     * VULN-C: Regular user cannot resolve reports.
     * Resolving is a moderation action; non-moderators must be blocked.
     */
    public function testVULN_C_regularUserCannotResolveReport(): void
    {
        $id = $this->createReportAsUser1();

        $result = $this->put("/reports/$id/resolve", [], ['X-User-Id' => '1']);
        $this->assertSame(403, $result['status'], 'Reporter must not resolve own report');
    }

    /**
     * VULN-D: Regular user cannot dismiss reports.
     * Dismissal is a moderation action; non-moderators must be blocked.
     */
    public function testVULN_D_regularUserCannotDismissReport(): void
    {
        $id = $this->createReportAsUser1();

        $result = $this->put("/reports/$id/dismiss", [], ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status'], 'Regular user must not dismiss reports');
    }

    /**
     * VULN-E: Duplicate report is idempotent and must not cause a 500 error.
     * The UNIQUE constraint (reporter_id, article_id) is handled gracefully.
     */
    public function testVULN_E_duplicateReportIsIdempotent(): void
    {
        $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '1']);
        $result = $this->post('/reports', ['article_id' => 1, 'reason' => 'harassment'], ['X-User-Id' => '1']);

        $this->assertSame(200, $result['status'], 'Duplicate report must return 200, not 500');

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM reports WHERE reporter_id = 1 AND article_id = 1');
        assert($stmt instanceof \PDOStatement);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Only one report row must exist');
    }

    /**
     * VULN-F: A resolved report cannot be re-resolved.
     * Status transitions are one-directional: pending → resolved/dismissed only.
     */
    public function testVULN_F_resolvedReportCannotBeReResolved(): void
    {
        $id = $this->createReportAsUser1();
        $this->put("/reports/$id/resolve", [], ['X-User-Id' => '3']);

        $result = $this->put("/reports/$id/resolve", [], ['X-User-Id' => '3']);
        $this->assertSame(422, $result['status'], 'Re-resolving must be rejected');
        $this->assertSame('resolved', $result['body']['current_status']);
    }

    /**
     * VULN-G: A dismissed report cannot be dismissed again.
     * Prevents status from being toggled or manipulated after closure.
     */
    public function testVULN_G_dismissedReportCannotBeReDismissed(): void
    {
        $id = $this->createReportAsUser1();
        $this->put("/reports/$id/dismiss", [], ['X-User-Id' => '3']);

        $result = $this->put("/reports/$id/dismiss", [], ['X-User-Id' => '3']);
        $this->assertSame(422, $result['status'], 'Re-dismissing must be rejected');
        $this->assertSame('dismissed', $result['body']['current_status']);
    }

    /**
     * VULN-H: reporter_id is always derived from X-User-Id header, not request body.
     * An attacker cannot forge a report on behalf of another user.
     */
    public function testVULN_H_reporterIdCannotBeSpoofedViaBody(): void
    {
        $body = ['article_id' => 1, 'reason' => 'spam', 'reporter_id' => 999];
        $result = $this->post('/reports', $body, ['X-User-Id' => '1']);

        $this->assertSame(201, $result['status']);
        $this->assertSame(1, $result['body']['reporter_id'], 'reporter_id must be 1 (from header), not 999');
    }

    /**
     * VULN-I: X-User-Id: 0 is treated as unauthenticated (→ 401).
     * Zero is an invalid user ID and must not grant access.
     */
    public function testVULN_I_zeroUserIdIsUnauthenticated(): void
    {
        $result = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam'], ['X-User-Id' => '0']);
        $this->assertSame(401, $result['status'], 'X-User-Id=0 must be rejected');
    }

    /**
     * VULN-J: Missing X-User-Id header returns 401 on all write endpoints.
     * No anonymous access to any moderation resource.
     */
    public function testVULN_J_missingUserIdHeaderReturns401(): void
    {
        $create = $this->post('/reports', ['article_id' => 1, 'reason' => 'spam']);
        $this->assertSame(401, $create['status'], 'POST /reports without auth must return 401');

        $list = $this->get('/reports');
        $this->assertSame(401, $list['status'], 'GET /reports without auth must return 401');
    }

    /**
     * VULN-K: Moderator can access any report by ID regardless of reporter.
     * Ensures access control works bidirectionally: restriction AND permission.
     */
    public function testVULN_K_moderatorCanViewAnyReport(): void
    {
        $id = $this->createReportAsUser1();

        $result = $this->get("/reports/$id", ['X-User-Id' => '3']);
        $this->assertSame(200, $result['status'], 'Moderator must be able to read any report');
        $this->assertSame($id, $result['body']['id']);
    }

    /**
     * VULN-L: Non-existent report returns 404, not a DB error or stack trace.
     * No internal error details must leak to clients.
     */
    public function testVULN_L_nonExistentReportReturns404(): void
    {
        $result = $this->get('/reports/99999', ['X-User-Id' => '3']);
        $this->assertSame(404, $result['status'], 'Non-existent report must return 404');
        $this->assertArrayNotHasKey('trace', $result['body']);
        $this->assertArrayNotHasKey('sql', $result['body']);
    }
}
