<?php

declare(strict_types=1);

namespace Limitlog\Tests\Article;

use Limitlog\Article\ArticleRepository;
use Limitlog\Article\RouteRegistrar;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * FT177 — Pagination Boundary & Limit Injection
 *
 * Attack difficulty: Lv.4 (Type Confusion) + Lv.5 (Integer Boundary)
 *
 * VULN-A through VULN-L: systematic vulnerability assessment covering
 * offset/cursor/filter parameter surfaces.
 */
class LimitTest extends TestCase
{
    private \PDO $pdo;
    private Router $router;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));
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
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(string $path, array $body = [], string $userId = '1'): array
    {
        $psr17   = new Psr17Factory();
        $request = (new ServerRequest('POST', $path, [
            'Content-Type' => 'application/json',
            'X-User-Id'    => $userId,
        ]))->withBody($psr17->createStream(json_encode($body) ?: '{}'));
        $response = $this->router->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * Seed N articles and return their ids newest-first.
     *
     * @return list<int>
     */
    private function seed(int $count, int $authorId = 1): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $res = $this->post('/articles', ['title' => "Article {$i}", 'body' => "Body {$i}"], (string) $authorId);
            $this->assertSame(201, $res['status']);
            $ids[] = $res['body']['id'];
        }

        return array_reverse($ids); // newest first
    }

    // ══════════════════════════════════════════════════════════════════════
    // Functional Tests
    // ══════════════════════════════════════════════════════════════════════

    public function test_offset_pagination_first_page(): void
    {
        $this->seed(25);

        $res = $this->get('/articles?page=1&limit=10');

        $this->assertSame(200, $res['status']);
        $this->assertCount(10, $res['body']['data']);
        $this->assertSame(25, $res['body']['total']);
        $this->assertTrue($res['body']['has_more']);
        $this->assertSame(10, $res['body']['limit']);
    }

    public function test_offset_pagination_last_page(): void
    {
        $this->seed(25);

        $res = $this->get('/articles?page=3&limit=10');

        $this->assertSame(200, $res['status']);
        $this->assertCount(5, $res['body']['data']);
        $this->assertFalse($res['body']['has_more']);
    }

    public function test_offset_pagination_default_limit(): void
    {
        $this->seed(5);

        $res = $this->get('/articles');

        $this->assertSame(200, $res['status']);
        $this->assertSame(ArticleRepository::DEFAULT_LIMIT, $res['body']['limit']);
    }

    public function test_cursor_pagination_first_page(): void
    {
        $ids = $this->seed(15);

        $res = $this->get('/articles/cursor?limit=5');

        $this->assertSame(200, $res['status']);
        $this->assertCount(5, $res['body']['data']);
        $this->assertTrue($res['body']['has_more']);
        $this->assertNotNull($res['body']['next_cursor']);
        // First page must be newest 5
        $this->assertSame($ids[0], $res['body']['data'][0]['id']);
    }

    public function test_cursor_pagination_traversal(): void
    {
        $this->seed(7);

        $page1 = $this->get('/articles/cursor?limit=3');
        $this->assertCount(3, $page1['body']['data']);
        $this->assertTrue($page1['body']['has_more']);

        $cursor = $page1['body']['next_cursor'];
        $page2  = $this->get("/articles/cursor?after={$cursor}&limit=3");
        $this->assertCount(3, $page2['body']['data']);
        $this->assertTrue($page2['body']['has_more']);

        $cursor = $page2['body']['next_cursor'];
        $page3  = $this->get("/articles/cursor?after={$cursor}&limit=3");
        $this->assertCount(1, $page3['body']['data']);
        $this->assertFalse($page3['body']['has_more']);
        $this->assertNull($page3['body']['next_cursor']);
    }

    public function test_cursor_no_results_returns_empty(): void
    {
        $res = $this->get('/articles/cursor?after=1&limit=10');

        $this->assertSame(200, $res['status']);
        $this->assertSame([], $res['body']['data']);
        $this->assertFalse($res['body']['has_more']);
        $this->assertNull($res['body']['next_cursor']);
    }

    public function test_by_author_filter_isolates_data(): void
    {
        $this->seed(3, 1);
        $this->seed(5, 2);

        $res = $this->get('/articles/by-author?author_id=2&limit=10');

        $this->assertSame(200, $res['status']);
        $this->assertCount(5, $res['body']['data']);

        foreach ($res['body']['data'] as $item) {
            $this->assertSame(2, $item['author_id']);
        }
    }

    public function test_max_limit_is_respected(): void
    {
        $this->seed(ArticleRepository::MAX_LIMIT + 10);

        // Requesting exactly MAX_LIMIT should work
        $res = $this->get('/articles?limit=' . ArticleRepository::MAX_LIMIT);

        $this->assertSame(200, $res['status']);
        $this->assertCount(ArticleRepository::MAX_LIMIT, $res['body']['data']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Vulnerability Assessment: VULN-A through VULN-L
    // Difficulty: Lv.4 Type Confusion + Lv.5 Integer Boundary
    // ══════════════════════════════════════════════════════════════════════

    /**
     * VULN-A: limit OVER MAX — attacker requests limit=999999 to dump entire DB.
     *
     * Expectation: 422 — enforced cap, not silent truncation.
     * Why hard: some servers silently clamp; we require explicit 422 rejection.
     */
    public function test_vuln_a_limit_over_max_returns_422(): void
    {
        $overLimits = [
            ArticleRepository::MAX_LIMIT + 1,
            1000,
            PHP_INT_MAX,
            999999999,
        ];

        foreach ($overLimits as $v) {
            $res = $this->get("/articles?limit={$v}");
            $this->assertSame(422, $res['status'], "VULN-A: limit={$v} must return 422");
        }
    }

    /**
     * VULN-B: limit = 0 or negative — attacker sends limit=0 or limit=-1.
     *
     * Expectation: 422.
     * Why hard: ctype_digit() rejects '-' sign, but '0' must also be rejected.
     */
    public function test_vuln_b_limit_zero_or_negative_returns_422(): void
    {
        // ctype_digit rejects '-', so negative signed ints naturally fail.
        // '0' passes ctype_digit but violates min=1.
        foreach (['0'] as $v) {
            $res = $this->get("/articles?limit={$v}");
            $this->assertSame(422, $res['status'], "VULN-B: limit={$v} must return 422");
        }

        // Signed negative — these fail ctype_digit, also must be 422
        // (query string value is still a string when parsed)
        $res = $this->get('/articles?limit=-1');
        $this->assertSame(422, $res['status'], 'VULN-B: limit=-1 must return 422');
    }

    /**
     * VULN-C: limit as FLOAT string — "10.5", "1e2", "1.0".
     *
     * Expectation: 422 — ctype_digit rejects '.' and 'e'.
     * Why hard: (int)"10.5" === 10 in PHP — implicit cast succeeds; explicit check required.
     */
    public function test_vuln_c_float_limit_rejected(): void
    {
        foreach (['10.5', '1e2', '1.0', '20.0', '100.'] as $v) {
            $res = $this->get("/articles?limit={$v}");
            $this->assertSame(422, $res['status'], "VULN-C: limit='{$v}' (float) must return 422");
        }
    }

    /**
     * VULN-D: limit with whitespace or padding — " 10", "10 ", "+10".
     *
     * Expectation: 422.
     * Why hard: (int)" 10" === 10 and trim(" 10") === "10"; ctype_digit catches leading space.
     */
    public function test_vuln_d_padded_limit_rejected(): void
    {
        // URL-encoded space %20 is decoded by the server; + is decoded as space
        foreach (['%2010', '10%20', '%2B10'] as $encoded) {
            $res = $this->get("/articles?limit={$encoded}");
            $this->assertSame(422, $res['status'], "VULN-D: limit='{$encoded}' (padded) must return 422");
        }
    }

    /**
     * VULN-E: Integer OVERFLOW string — 20-digit number that wraps PHP_INT.
     *
     * Expectation: 422.
     * Why hard: (int)"99999999999999999999" silently wraps to a negative/wrong value in PHP.
     *            strlen > 18 guard is required before the cast.
     */
    public function test_vuln_e_integer_overflow_string_rejected(): void
    {
        $overflows = [
            '99999999999999999999',           // 20 digits — overflows 64-bit int
            '9223372036854775808',            // PHP_INT_MAX + 1
            '18446744073709551615',           // UINT64_MAX
            str_repeat('9', 30),             // 30 nines
        ];

        foreach ($overflows as $v) {
            $res = $this->get("/articles?limit={$v}");
            $this->assertSame(422, $res['status'], "VULN-E: limit='{$v}' (overflow) must return 422");
        }
    }

    /**
     * VULN-F: Non-numeric limit — SQL injection, path traversal, unicode.
     *
     * Expectation: 422 for all.
     * Why hard: some frameworks pass raw query params to sprintf/PDO without validation.
     */
    public function test_vuln_f_non_numeric_limit_rejected(): void
    {
        $bad = [
            'abc',
            "1;DROP TABLE articles;--",
            '../etc',
            '0x10',    // hex — ctype_digit rejects 'x'
            "\n10",    // newline prefix
            '10%00',   // null byte — URL-encoded
        ];

        foreach ($bad as $v) {
            $res = $this->get('/articles?limit=' . rawurlencode($v));
            $this->assertSame(422, $res['status'], "VULN-F: limit='{$v}' must return 422");
        }
    }

    /**
     * VULN-G: page = 0 in offset pagination.
     *
     * Expectation: 422 — page < 1 means negative OFFSET (-limit), which corrupts the query.
     */
    public function test_vuln_g_page_zero_returns_422(): void
    {
        $res = $this->get('/articles?page=0&limit=10');
        $this->assertSame(422, $res['status'], 'VULN-G: page=0 must return 422 (would produce negative OFFSET)');
    }

    /**
     * VULN-H: Cursor FORGERY — attacker passes after=0 (valid floor) to start from
     * the very beginning, then a cursor pointing past INT_MAX.
     *
     * Expectation: after=0 → 200 (returns all); after=9999999999999999999 (19-digit, overflow) → 422.
     */
    public function test_vuln_h_cursor_boundary_values(): void
    {
        $this->seed(3);

        // after=0 is valid (means "start from newest" — nothing has id < 0+1 = after exclusive start)
        // Actually after=0 means WHERE id < 0 → empty result, which is valid
        $res = $this->get('/articles/cursor?after=0&limit=10');
        $this->assertSame(200, $res['status'], 'VULN-H: after=0 is valid, returns empty list');
        $this->assertSame([], $res['body']['data']);

        // Overflow cursor
        $res = $this->get('/articles/cursor?after=' . str_repeat('9', 20) . '&limit=10');
        $this->assertSame(422, $res['status'], 'VULN-H: Overflow cursor must return 422');
    }

    /**
     * VULN-I: author_id = 0 or negative in by-author filter.
     *
     * Expectation: 422.
     * Why hard: author_id=0 would match no rows (valid SQL) but is semantically invalid;
     *            authors are positive integers.
     */
    public function test_vuln_i_invalid_author_id_rejected(): void
    {
        foreach (['0', '-1', 'abc', '1.5'] as $v) {
            $res = $this->get("/articles/by-author?author_id={$v}&limit=10");
            $this->assertSame(422, $res['status'], "VULN-I: author_id='{$v}' must return 422");
        }
    }

    /**
     * VULN-J: Very large page number (OFFSET overflow).
     *
     * page=999999999 with limit=100 → OFFSET = 99999999900 — valid SQL but may expose
     * performance issues or wrap to negative. Must not crash.
     *
     * Expectation: 200 with empty data (no articles at that offset).
     */
    public function test_vuln_j_huge_page_returns_empty_not_error(): void
    {
        $this->seed(3);

        $res = $this->get('/articles?page=999999&limit=10');

        $this->assertSame(200, $res['status'], 'VULN-J: Huge page must return 200 with empty data, not crash');
        $this->assertSame([], $res['body']['data']);
        $this->assertFalse($res['body']['has_more']);
    }

    /**
     * VULN-K: Multiple values for same param — ?limit=5&limit=1000.
     *
     * PHP's parse_str / getQueryParams takes the LAST value; attacker may try
     * to shadow a validated param with a second occurrence.
     *
     * Expectation: either 200 with limit≤MAX or 422 — never silently use 1000.
     */
    public function test_vuln_k_duplicate_param_cannot_bypass_limit_cap(): void
    {
        $this->seed(ArticleRepository::MAX_LIMIT + 10);

        // Most PSR-7 implementations (Nyholm) keep only the last value for duplicate keys.
        // The last value '1000' exceeds MAX_LIMIT → must return 422.
        $res = $this->get('/articles?limit=5&limit=1000');

        if ($res['status'] === 200) {
            // If server chose the first value (5), that is also acceptable
            $this->assertLessThanOrEqual(
                ArticleRepository::MAX_LIMIT,
                $res['body']['limit'],
                'VULN-K: Duplicate param must not bypass MAX_LIMIT cap',
            );
        } else {
            $this->assertSame(422, $res['status'], 'VULN-K: Must be 200 (safe value chosen) or 422');
        }
    }

    /**
     * VULN-L: ReDoS-safe — limit param with catastrophic input for naive regex validators.
     *
     * A pattern like /^\d+$/ on "(1+)+" inputs can trigger exponential backtracking.
     * Our ctype_digit() is O(n) — immune to ReDoS. Verify with a crafted string.
     *
     * Expectation: 422 returned quickly (no hang), confirming ctype_digit not regex.
     */
    public function test_vuln_l_redos_safe_limit_validation(): void
    {
        // Classic ReDoS payload for /^\d+$/ — alternation that causes backtracking
        // on non-matching suffix character
        $redosPayload = str_repeat('1', 50) . 'x';  // "111...1x" — matches \d+ prefix then fails

        $start = microtime(true);
        $res   = $this->get('/articles?limit=' . rawurlencode($redosPayload));
        $elapsed = microtime(true) - $start;

        $this->assertSame(422, $res['status'], 'VULN-L: ReDoS payload must return 422');
        $this->assertLessThan(0.1, $elapsed, 'VULN-L: Validation must complete in <100ms (ctype_digit is O(n), not exponential)');
    }
}
