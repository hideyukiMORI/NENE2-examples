<?php

declare(strict_types=1);

namespace Meterlog\Tests\Meter;

use Meterlog\Meter\MeterRepository;
use Meterlog\Meter\RouteRegistrar;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class MeterTest extends TestCase
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
        $repository = new MeterRepository($executor);
        $psr17      = new Psr17Factory();
        $response   = new JsonResponseFactory($psr17, $psr17);
        $router     = new Router();
        $registrar  = new RouteRegistrar($router, $repository, $response);
        $registrar->register();

        return $router;
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>}
     */
    private function get(string $path, array $headers = []): array
    {
        $request  = new ServerRequest('GET', $path, $headers);
        $response = $this->router->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(string $path, array $body = [], array $headers = []): array
    {
        $allHeaders = array_merge(['Content-Type' => 'application/json'], $headers);
        $request    = (new ServerRequest('POST', $path, $allHeaders))
            ->withBody($this->psr17->createStream(json_encode($body) ?: '{}'));
        $response   = $this->router->handle($request);
        $data       = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function adminUpsertQuota(int $userId, int $dailyLimit): array
    {
        $res = $this->post('/quotas', [
            'user_id'     => $userId,
            'daily_limit' => $dailyLimit,
        ], ['X-Admin-Key' => 'admin-secret']);

        $this->assertSame(200, $res['status'], 'adminUpsertQuota failed: ' . json_encode($res['body']));

        return $res['body'];
    }

    private function recordUsage(int $userId, string $endpoint): void
    {
        $res = $this->post('/usage', [
            'user_id'  => $userId,
            'endpoint' => $endpoint,
        ], ['X-Machine-Key' => 'machine-secret']);

        $this->assertSame(201, $res['status'], 'recordUsage failed: ' . json_encode($res['body']));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Functional Tests
    // ══════════════════════════════════════════════════════════════════════

    public function test_admin_can_create_quota(): void
    {
        $res = $this->post('/quotas', [
            'user_id'     => 1,
            'daily_limit' => 500,
        ], ['X-Admin-Key' => 'admin-secret']);

        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['body']['user_id']);
        $this->assertSame(500, $res['body']['daily_limit']);
    }

    public function test_admin_can_update_quota(): void
    {
        $this->adminUpsertQuota(1, 500);
        $res = $this->adminUpsertQuota(1, 1000);

        $this->assertSame(1000, $res['daily_limit']);
    }

    public function test_quota_status_returns_default_when_no_quota_row(): void
    {
        // User 99 has no quota row — should use DEFAULT_DAILY_LIMIT (1000)
        $res = $this->get('/quotas/99');

        $this->assertSame(200, $res['status']);
        $this->assertSame(99, $res['body']['user_id']);
        $this->assertSame(MeterRepository::DEFAULT_DAILY_LIMIT, $res['body']['daily_limit']);
        $this->assertSame(0, $res['body']['used']);
        $this->assertSame(MeterRepository::DEFAULT_DAILY_LIMIT, $res['body']['remaining']);
        $this->assertTrue($res['body']['allowed']);
    }

    public function test_quota_status_reflects_usage(): void
    {
        $this->adminUpsertQuota(1, 10);
        $this->recordUsage(1, 'GET /articles');
        $this->recordUsage(1, 'GET /articles');
        $this->recordUsage(1, 'POST /articles');

        $res = $this->get('/quotas/1');

        $this->assertSame(200, $res['status']);
        $this->assertSame(3, $res['body']['used']);
        $this->assertSame(7, $res['body']['remaining']);
        $this->assertTrue($res['body']['allowed']);
    }

    public function test_record_usage_requires_machine_key(): void
    {
        $res = $this->post('/usage', ['user_id' => 1, 'endpoint' => 'GET /articles']);

        $this->assertSame(401, $res['status']);
    }

    public function test_record_usage_returns_created(): void
    {
        $res = $this->post('/usage', [
            'user_id'  => 2,
            'endpoint' => 'GET /articles',
        ], ['X-Machine-Key' => 'machine-secret']);

        $this->assertSame(201, $res['status']);
        $this->assertTrue($res['body']['recorded']);
        $this->assertSame(2, $res['body']['user_id']);
        $this->assertSame('GET /articles', $res['body']['endpoint']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $res['body']['day_key']);
    }

    public function test_breakdown_shows_per_endpoint_counts(): void
    {
        $this->recordUsage(1, 'GET /articles');
        $this->recordUsage(1, 'GET /articles');
        $this->recordUsage(1, 'POST /articles');

        $today = date('Y-m-d');
        $res   = $this->get("/usage/1/breakdown?date={$today}", ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['body']['user_id']);
        $this->assertSame($today, $res['body']['date']);
        $this->assertSame(3, $res['body']['total']);

        // First entry should be GET /articles (highest count)
        $this->assertSame('GET /articles', $res['body']['breakdown'][0]['endpoint']);
        $this->assertSame(2, $res['body']['breakdown'][0]['count']);
        $this->assertSame('POST /articles', $res['body']['breakdown'][1]['endpoint']);
        $this->assertSame(1, $res['body']['breakdown'][1]['count']);
    }

    public function test_breakdown_defaults_to_today_when_no_date_param(): void
    {
        $this->recordUsage(1, 'GET /articles');

        $res = $this->get('/usage/1/breakdown', ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame(date('Y-m-d'), $res['body']['date']);
        $this->assertSame(1, $res['body']['total']);
    }

    public function test_check_returns_allowed_within_quota(): void
    {
        $this->adminUpsertQuota(1, 5);

        $res = $this->post('/usage/check', ['user_id' => 1], ['X-Machine-Key' => 'machine-secret']);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['allowed']);
        $this->assertSame(5, $res['body']['remaining']);
        $this->assertSame(0, $res['body']['used']);
    }

    public function test_check_returns_denied_when_quota_exhausted(): void
    {
        $this->adminUpsertQuota(1, 2);
        $this->recordUsage(1, 'GET /articles');
        $this->recordUsage(1, 'GET /articles');

        $res = $this->post('/usage/check', ['user_id' => 1], ['X-Machine-Key' => 'machine-secret']);

        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['body']['allowed']);
        $this->assertSame(0, $res['body']['remaining']);
        $this->assertSame(2, $res['body']['used']);
    }

    public function test_check_requires_machine_key(): void
    {
        $res = $this->post('/usage/check', ['user_id' => 1]);

        $this->assertSame(401, $res['status']);
    }

    public function test_breakdown_empty_when_no_events(): void
    {
        $res = $this->get('/usage/1/breakdown?date=' . date('Y-m-d'), ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame([], $res['body']['breakdown']);
        $this->assertSame(0, $res['body']['total']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Vulnerability Assessment: VULN-A through VULN-L
    // ══════════════════════════════════════════════════════════════════════

    /**
     * VULN-A: Quota upsert without admin key must be rejected.
     * Expectation: 401.
     */
    public function test_vuln_a_upsert_quota_without_admin_key_returns_401(): void
    {
        $res = $this->post('/quotas', ['user_id' => 1, 'daily_limit' => 500]);

        $this->assertSame(401, $res['status'], 'VULN-A: No admin key must return 401');
    }

    /**
     * VULN-B: Wrong admin key must be rejected.
     * Expectation: 401 for all wrong-key variants.
     */
    public function test_vuln_b_wrong_admin_key_rejected(): void
    {
        foreach (['wrong', 'ADMIN-SECRET', 'admin_secret', 'adminsecret', ''] as $bad) {
            $res = $this->post('/quotas', [
                'user_id'     => 1,
                'daily_limit' => 500,
            ], $bad !== '' ? ['X-Admin-Key' => $bad] : []);

            $this->assertSame(401, $res['status'], "VULN-B: Admin key '{$bad}' must return 401");
        }
    }

    /**
     * VULN-C: daily_limit <= 0 must be rejected.
     * Expectation: 422 for zero, negative, and missing values.
     */
    public function test_vuln_c_non_positive_daily_limit_rejected(): void
    {
        foreach ([0, -1, -1000] as $bad) {
            $res = $this->post('/quotas', [
                'user_id'     => 1,
                'daily_limit' => $bad,
            ], ['X-Admin-Key' => 'admin-secret']);

            $this->assertSame(422, $res['status'], "VULN-C: daily_limit={$bad} must return 422");
        }
    }

    /**
     * VULN-D: Record usage without machine key must be rejected.
     * Expectation: 401.
     */
    public function test_vuln_d_record_usage_without_machine_key_returns_401(): void
    {
        $res = $this->post('/usage', ['user_id' => 1, 'endpoint' => 'GET /articles']);

        $this->assertSame(401, $res['status'], 'VULN-D: No machine key must return 401');
    }

    /**
     * VULN-E: Wrong machine key must be rejected.
     * Expectation: 401.
     */
    public function test_vuln_e_wrong_machine_key_rejected(): void
    {
        foreach (['wrong', 'MACHINE-SECRET', 'machine_secret', ''] as $bad) {
            $res = $this->post('/usage', [
                'user_id'  => 1,
                'endpoint' => 'GET /articles',
            ], $bad !== '' ? ['X-Machine-Key' => $bad] : []);

            $this->assertSame(401, $res['status'], "VULN-E: Machine key '{$bad}' must return 401");
        }
    }

    /**
     * VULN-F: Endpoint field with SQL injection attempts must be stored safely (no crash).
     * Expectation: 201 (stored as literal string, no SQL error).
     */
    public function test_vuln_f_sql_injection_in_endpoint_stored_safely(): void
    {
        $malicious = "'; DROP TABLE usage_events; --";

        $res = $this->post('/usage', [
            'user_id'  => 1,
            'endpoint' => $malicious,
        ], ['X-Machine-Key' => 'machine-secret']);

        $this->assertSame(201, $res['status'], 'VULN-F: SQL injection must not crash, should return 201');
        $this->assertSame($malicious, $res['body']['endpoint']);

        // Table must still be queryable after the injection attempt
        $status = $this->get('/quotas/1');
        $this->assertSame(200, $status['status'], 'VULN-F: usage_events table must still exist after injection attempt');
    }

    /**
     * VULN-G: user_id <= 0 in POST /usage must be rejected.
     * Expectation: 422.
     */
    public function test_vuln_g_non_positive_user_id_in_usage_rejected(): void
    {
        foreach ([0, -1] as $bad) {
            $res = $this->post('/usage', [
                'user_id'  => $bad,
                'endpoint' => 'GET /articles',
            ], ['X-Machine-Key' => 'machine-secret']);

            $this->assertSame(422, $res['status'], "VULN-G: user_id={$bad} must return 422");
        }
    }

    /**
     * VULN-H: Empty endpoint must be rejected.
     * Expectation: 422.
     */
    public function test_vuln_h_empty_endpoint_rejected(): void
    {
        $res = $this->post('/usage', [
            'user_id'  => 1,
            'endpoint' => '',
        ], ['X-Machine-Key' => 'machine-secret']);

        $this->assertSame(422, $res['status'], 'VULN-H: Empty endpoint must return 422');
    }

    /**
     * VULN-I: IDOR on breakdown — user 2 cannot see user 1's breakdown.
     * Expectation: 403.
     */
    public function test_vuln_i_idor_on_breakdown_returns_403(): void
    {
        $this->recordUsage(1, 'GET /articles');

        $today = date('Y-m-d');
        $res   = $this->get("/usage/1/breakdown?date={$today}", ['X-User-Id' => '2']);

        $this->assertSame(403, $res['status'], 'VULN-I: IDOR on breakdown must return 403 for non-owner');
    }

    /**
     * VULN-J: Admin can see any user's breakdown (bypass user restriction).
     * Expectation: 200 for admin accessing another user's data.
     */
    public function test_vuln_j_admin_can_access_any_breakdown(): void
    {
        $this->recordUsage(1, 'GET /articles');

        $today = date('Y-m-d');
        $res   = $this->get("/usage/1/breakdown?date={$today}", ['X-Admin-Key' => 'admin-secret']);

        $this->assertSame(200, $res['status'], 'VULN-J: Admin must be able to access any user breakdown');
        $this->assertSame(1, $res['body']['total']);
    }

    /**
     * VULN-K: Invalid date format in breakdown must be rejected.
     * Expectation: 422 for garbage dates.
     */
    public function test_vuln_k_invalid_date_format_rejected(): void
    {
        $badDates = ['not-a-date', '2024/01/15', '2024-13-01', '2024-02-30', '../../etc'];

        foreach ($badDates as $bad) {
            $res = $this->get("/usage/1/breakdown?date={$bad}", ['X-User-Id' => '1']);

            $this->assertSame(422, $res['status'], "VULN-K: Date '{$bad}' must return 422");
        }
    }

    /**
     * VULN-L: Remaining quota never goes below zero even when over-consumed.
     * Expectation: remaining === 0 (not negative) when used > limit.
     */
    public function test_vuln_l_remaining_never_negative(): void
    {
        $this->adminUpsertQuota(1, 2);

        // Record more events than the quota allows (bypass not guarded in record() — gate is in check)
        for ($i = 0; $i < 5; $i++) {
            $this->post('/usage', [
                'user_id'  => 1,
                'endpoint' => 'GET /articles',
            ], ['X-Machine-Key' => 'machine-secret']);
        }

        $res = $this->get('/quotas/1');

        $this->assertSame(200, $res['status']);
        $this->assertSame(5, $res['body']['used']);
        $this->assertSame(0, $res['body']['remaining'], 'VULN-L: remaining must be 0, never negative');
        $this->assertFalse($res['body']['allowed'], 'VULN-L: allowed must be false when over quota');
    }
}
