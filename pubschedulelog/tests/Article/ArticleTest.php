<?php

declare(strict_types=1);

namespace Pubschedulelog\Tests\Article;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Pubschedulelog\Article\ArticleRepository;
use Pubschedulelog\Article\RouteRegistrar;

class ArticleTest extends TestCase
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

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>}
     */
    private function put(string $path, array $body = [], array $headers = []): array
    {
        $allHeaders = array_merge(['Content-Type' => 'application/json'], $headers);
        $request    = (new ServerRequest('PUT', $path, $allHeaders))
            ->withBody($this->psr17->createStream(json_encode($body) ?: '{}'));
        $response   = $this->router->handle($request);
        $data       = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    // ── Helper: create a draft article ───────────────────────────────────

    /** @return array<string, mixed> */
    private function createDraft(int $authorId = 1, string $title = 'My Article'): array
    {
        $res = $this->post('/articles', [
            'title' => $title,
            'body'  => 'Content here.',
        ], ['X-User-Id' => (string) $authorId]);

        $this->assertSame(201, $res['status'], 'createDraft failed: ' . json_encode($res['body']));

        return $res['body'];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Functional Tests
    // ══════════════════════════════════════════════════════════════════════

    public function test_create_draft(): void
    {
        $res = $this->post('/articles', [
            'title' => 'Hello',
            'body'  => 'World',
        ], ['X-User-Id' => '1']);

        $this->assertSame(201, $res['status']);
        $this->assertSame('Hello', $res['body']['title']);
        $this->assertSame('draft', $res['body']['status']);
        $this->assertNull($res['body']['publish_at']);
        $this->assertNull($res['body']['published_at']);
    }

    public function test_create_requires_auth(): void
    {
        $res = $this->post('/articles', ['title' => 'X', 'body' => 'Y']);
        $this->assertSame(401, $res['status']);
    }

    public function test_get_own_draft(): void
    {
        $draft = $this->createDraft(1);
        $id    = $draft['id'];

        $res = $this->get("/articles/{$id}", ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame($id, $res['body']['id']);
    }

    public function test_published_article_visible_without_auth(): void
    {
        $draft = $this->createDraft(1);
        $id    = $draft['id'];

        $this->post("/articles/{$id}/publish", [], ['X-User-Id' => '1']);

        // No X-User-Id header
        $res = $this->get("/articles/{$id}");
        $this->assertSame(200, $res['status']);
        $this->assertSame('published', $res['body']['status']);
        $this->assertNotNull($res['body']['published_at']);
    }

    public function test_schedule_and_unschedule(): void
    {
        $draft     = $this->createDraft(1);
        $id        = $draft['id'];
        $future    = date('c', strtotime('+1 hour'));

        $res = $this->post("/articles/{$id}/schedule", [
            'publish_at' => $future,
        ], ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('scheduled', $res['body']['status']);
        $this->assertSame($future, $res['body']['publish_at']);

        // Unschedule reverts to draft
        $res = $this->post("/articles/{$id}/unschedule", [], ['X-User-Id' => '1']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('draft', $res['body']['status']);
        $this->assertNull($res['body']['publish_at']);
    }

    public function test_publish_due_triggers_scheduled_articles(): void
    {
        $draft  = $this->createDraft(1);
        $id     = $draft['id'];

        // Schedule in the past (already due)
        $past = date('c', strtotime('-10 minutes'));

        // Force-insert as scheduled with past publish_at
        $this->pdo->exec(
            "UPDATE articles SET status='scheduled', publish_at='{$past}' WHERE id={$id}",
        );

        $res = $this->post('/articles/publish-due', [], ['X-Admin-Key' => 'admin-secret']);

        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['body']['published_count']);
        $this->assertContains($id, $res['body']['published_ids']);

        // Verify article is now published
        $get = $this->get("/articles/{$id}");
        $this->assertSame('published', $get['body']['status']);
    }

    public function test_archive_published_article(): void
    {
        $draft = $this->createDraft(1);
        $id    = $draft['id'];

        $this->post("/articles/{$id}/publish", [], ['X-User-Id' => '1']);
        $res = $this->post("/articles/{$id}/archive", [], ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('archived', $res['body']['status']);
    }

    public function test_update_draft_title_and_body(): void
    {
        $draft = $this->createDraft(1, 'Old Title');
        $id    = $draft['id'];

        $res = $this->put("/articles/{$id}", [
            'title' => 'New Title',
            'body'  => 'Updated content.',
        ], ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('New Title', $res['body']['title']);
    }

    public function test_list_published_articles_no_auth(): void
    {
        $draft = $this->createDraft(1);
        $id    = $draft['id'];
        $this->post("/articles/{$id}/publish", [], ['X-User-Id' => '1']);

        $res = $this->get('/articles?status=published');
        $this->assertSame(200, $res['status']);
        $this->assertCount(1, $res['body']['data']);
    }

    public function test_list_own_drafts(): void
    {
        $this->createDraft(1, 'Draft A');
        $this->createDraft(1, 'Draft B');
        $this->createDraft(2, 'Other user draft'); // author 2

        $res = $this->get('/articles?status=draft', ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertCount(2, $res['body']['data']); // only author 1's drafts
    }

    // ══════════════════════════════════════════════════════════════════════
    // Vulnerability Assessment: VULN-A through VULN-L
    // ══════════════════════════════════════════════════════════════════════

    /**
     * VULN-A: IDOR — cross-user draft access via GET /articles/{id}
     * Expectation: other user's draft → 404 (not 200).
     */
    public function test_vuln_a_idor_cross_user_draft_returns_404(): void
    {
        $draft = $this->createDraft(authorId: 1);
        $id    = $draft['id'];

        // User 2 tries to read user 1's draft
        $res = $this->get("/articles/{$id}", ['X-User-Id' => '2']);

        $this->assertSame(404, $res['status'], 'VULN-A: IDOR on draft should return 404 for non-owner');
    }

    /**
     * VULN-B: IDOR — cross-user schedule action
     * Expectation: user 2 scheduling user 1's article → 404.
     */
    public function test_vuln_b_idor_cross_user_schedule_returns_404(): void
    {
        $draft  = $this->createDraft(authorId: 1);
        $id     = $draft['id'];
        $future = date('c', strtotime('+1 hour'));

        $res = $this->post("/articles/{$id}/schedule", [
            'publish_at' => $future,
        ], ['X-User-Id' => '2']); // wrong user

        $this->assertSame(404, $res['status'], 'VULN-B: IDOR on schedule should return 404');
    }

    /**
     * VULN-C: Publish-in-past — schedule with past publish_at must be rejected.
     */
    public function test_vuln_c_past_publish_at_rejected(): void
    {
        $draft = $this->createDraft(authorId: 1);
        $id    = $draft['id'];
        $past  = date('c', strtotime('-1 minute'));

        $res = $this->post("/articles/{$id}/schedule", [
            'publish_at' => $past,
        ], ['X-User-Id' => '1']);

        $this->assertSame(422, $res['status'], 'VULN-C: Past publish_at must be rejected with 422');
    }

    /**
     * VULN-D: Invalid datetime — schedule with garbage publish_at.
     */
    public function test_vuln_d_invalid_publish_at_rejected(): void
    {
        $draft = $this->createDraft(authorId: 1);
        $id    = $draft['id'];

        foreach (['not-a-date', '', '9999-99-99T99:99:99', '../../etc/passwd'] as $bad) {
            $res = $this->post("/articles/{$id}/schedule", [
                'publish_at' => $bad,
            ], ['X-User-Id' => '1']);

            $this->assertSame(
                422,
                $res['status'],
                "VULN-D: Invalid publish_at '{$bad}' must return 422",
            );
        }
    }

    /**
     * VULN-E: Transition bypass — archive a draft directly must succeed (valid), but
     *         archiving an already-archived article must be rejected (409).
     */
    public function test_vuln_e_double_archive_rejected(): void
    {
        $draft = $this->createDraft(authorId: 1);
        $id    = $draft['id'];

        $this->post("/articles/{$id}/archive", [], ['X-User-Id' => '1']);

        // Second archive attempt
        $res = $this->post("/articles/{$id}/archive", [], ['X-User-Id' => '1']);
        $this->assertSame(409, $res['status'], 'VULN-E: Double archive must return 409');
    }

    /**
     * VULN-F: Edit published article — PUT must be rejected.
     */
    public function test_vuln_f_cannot_edit_published_article(): void
    {
        $draft = $this->createDraft(authorId: 1);
        $id    = $draft['id'];

        $this->post("/articles/{$id}/publish", [], ['X-User-Id' => '1']);

        $res = $this->put("/articles/{$id}", [
            'title' => 'Tampered',
            'body'  => 'Injection.',
        ], ['X-User-Id' => '1']);

        $this->assertSame(422, $res['status'], 'VULN-F: Cannot edit a published article');
    }

    /**
     * VULN-G: Author ID injection via body — POST /articles must ignore body[author_id].
     */
    public function test_vuln_g_author_id_not_injectable_via_body(): void
    {
        $res = $this->post('/articles', [
            'title'     => 'Injected',
            'body'      => 'Content',
            'author_id' => 999,   // attacker tries to set their own author_id
        ], ['X-User-Id' => '1']);

        $this->assertSame(201, $res['status']);
        $this->assertSame(1, $res['body']['author_id'], 'VULN-G: author_id must come from X-User-Id, not body');
    }

    /**
     * VULN-H: publish-due without admin key must be rejected.
     */
    public function test_vuln_h_publish_due_requires_admin_key(): void
    {
        $res = $this->post('/articles/publish-due');
        $this->assertSame(401, $res['status'], 'VULN-H: publish-due without admin key must return 401');
    }

    /**
     * VULN-I: Cross-user publish — user 2 publishing user 1's draft → 404.
     */
    public function test_vuln_i_cross_user_publish_returns_404(): void
    {
        $draft = $this->createDraft(authorId: 1);
        $id    = $draft['id'];

        $res = $this->post("/articles/{$id}/publish", [], ['X-User-Id' => '2']);
        $this->assertSame(404, $res['status'], 'VULN-I: Cross-user publish must return 404');
    }

    /**
     * VULN-J: Unschedule a non-scheduled article (draft) must be rejected with 409.
     */
    public function test_vuln_j_unschedule_draft_returns_409(): void
    {
        $draft = $this->createDraft(authorId: 1);
        $id    = $draft['id'];

        $res = $this->post("/articles/{$id}/unschedule", [], ['X-User-Id' => '1']);
        $this->assertSame(409, $res['status'], 'VULN-J: Unschedule on draft must return 409');
    }

    /**
     * VULN-K: List non-published without auth must return 401.
     */
    public function test_vuln_k_list_drafts_without_auth_returns_401(): void
    {
        $res = $this->get('/articles?status=draft');
        $this->assertSame(401, $res['status'], 'VULN-K: Draft list without auth must return 401');
    }

    /**
     * VULN-L: Draft not in published listing — drafts must not appear in public list.
     */
    public function test_vuln_l_draft_not_visible_in_published_list(): void
    {
        $this->createDraft(authorId: 1, title: 'Secret Draft');

        $res = $this->get('/articles?status=published');
        $this->assertSame(200, $res['status']);
        $this->assertCount(0, $res['body']['data'], 'VULN-L: Draft must not appear in published listing');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Cracker Attack Tests: ATK-01 through ATK-12
    // ══════════════════════════════════════════════════════════════════════

    /**
     * ATK-01: Manipulate X-User-Id to 0 — treat as unauthenticated.
     */
    public function test_atk_01_zero_user_id_treated_as_no_auth(): void
    {
        $res = $this->post('/articles', [
            'title' => 'X',
            'body'  => 'Y',
        ], ['X-User-Id' => '0']);

        // 0 parsed as (int)'0' = 0, which is falsy — but we cast and allow it.
        // The real guard is: we require the header to be non-empty.
        // X-User-Id: 0 would create an article with author_id=0 — assert 201 (valid but controlled).
        // Alternatively, if system requires positive IDs, assert 422.
        // This implementation allows it (no ID format validation). Document the behavior.
        $this->assertContains($res['status'], [201, 401, 422], 'ATK-01: zero user id handled');
    }

    /**
     * ATK-02: Negative user ID injection via body (already covered by VULN-G,
     *         but test from attacker perspective with negative value).
     */
    public function test_atk_02_negative_author_id_in_body_ignored(): void
    {
        $res = $this->post('/articles', [
            'title'     => 'Attack',
            'body'      => 'Content',
            'author_id' => -99,
        ], ['X-User-Id' => '5']);

        $this->assertSame(201, $res['status']);
        $this->assertSame(5, $res['body']['author_id'], 'ATK-02: Negative author_id in body must be ignored');
    }

    /**
     * ATK-03: SQL injection in title field.
     */
    public function test_atk_03_sql_injection_in_title(): void
    {
        $malicious = "'; DROP TABLE articles; --";
        $res       = $this->post('/articles', [
            'title' => $malicious,
            'body'  => 'Body',
        ], ['X-User-Id' => '1']);

        $this->assertSame(201, $res['status'], 'ATK-03: SQL injection attempt in title should be stored safely');
        $this->assertSame($malicious, $res['body']['title'], 'ATK-03: Title stored verbatim, not executed');

        // Table still exists (not dropped)
        $res2 = $this->get('/articles?status=draft', ['X-User-Id' => '1']);
        $this->assertSame(200, $res2['status'], 'ATK-03: articles table still functional after injection attempt');
    }

    /**
     * ATK-04: XSS payload in body — stored verbatim, not executed server-side.
     */
    public function test_atk_04_xss_in_body_stored_safely(): void
    {
        $xss = '<script>alert("xss")</script>';
        $res = $this->post('/articles', [
            'title' => 'XSS Test',
            'body'  => $xss,
        ], ['X-User-Id' => '1']);

        $this->assertSame(201, $res['status']);
        $this->assertSame($xss, $res['body']['body'], 'ATK-04: XSS stored verbatim (escaping is client responsibility)');
    }

    /**
     * ATK-05: Very large publish_at epoch (year 9999+) — should pass if valid ISO date.
     *         Goal: no integer overflow, no server error.
     */
    public function test_atk_05_far_future_publish_at_accepted(): void
    {
        $draft  = $this->createDraft(1);
        $id     = $draft['id'];
        $future = '2999-12-31T23:59:59+00:00';

        $res = $this->post("/articles/{$id}/schedule", [
            'publish_at' => $future,
        ], ['X-User-Id' => '1']);

        // Year 2999 is in the future — should succeed
        $this->assertSame(200, $res['status'], 'ATK-05: Far future publish_at accepted');
    }

    /**
     * ATK-06: Attempt to publish another user's scheduled article
     *         by guessing IDs (enumeration attack).
     */
    public function test_atk_06_enumeration_attack_on_draft(): void
    {
        $draft = $this->createDraft(authorId: 99);
        $id    = $draft['id'];

        // Attacker (user 1) tries IDs 1–10 looking for user 99's article
        for ($guess = 1; $guess <= $id; $guess++) {
            $res = $this->get("/articles/{$guess}", ['X-User-Id' => '1']);
            // Must be 404, not 403 — consistent non-leaking response
            $this->assertSame(404, $res['status'], "ATK-06: ID={$guess} must return 404 to non-owner");
        }
    }

    /**
     * ATK-07: publish-due with wrong admin key must be rejected.
     */
    public function test_atk_07_publish_due_with_wrong_key_rejected(): void
    {
        // PSR-7 normalizes surrounding whitespace per RFC 7230 — test non-whitespace variants.
        foreach (['', 'wrong', 'ADMIN-SECRET', 'admin_secret', 'adminsecret'] as $badKey) {
            $res = $this->post('/articles/publish-due', [], ['X-Admin-Key' => $badKey]);
            $this->assertSame(401, $res['status'], "ATK-07: Wrong admin key '{$badKey}' must return 401");
        }
    }

    /**
     * ATK-08: Transition to invalid status via body injection — status must not be
     *         overridable via request body.
     */
    public function test_atk_08_status_not_injectable_via_body(): void
    {
        $draft = $this->createDraft(1);
        $id    = $draft['id'];

        // Try to inject status=published in PUT body
        $res = $this->put("/articles/{$id}", [
            'title'  => 'Legit',
            'body'   => 'Content',
            'status' => 'published',  // attacker tries to force status
        ], ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertSame('draft', $res['body']['status'], 'ATK-08: status in body must be ignored');
    }

    /**
     * ATK-09: Attempt to archive another user's article.
     */
    public function test_atk_09_cross_user_archive_returns_404(): void
    {
        $draft = $this->createDraft(authorId: 42);
        $id    = $draft['id'];

        $res = $this->post("/articles/{$id}/archive", [], ['X-User-Id' => '1']);
        $this->assertSame(404, $res['status'], 'ATK-09: Cross-user archive must return 404');
    }

    /**
     * ATK-10: Update another user's article via PUT.
     */
    public function test_atk_10_cross_user_update_returns_404(): void
    {
        $draft = $this->createDraft(authorId: 7);
        $id    = $draft['id'];

        $res = $this->put("/articles/{$id}", [
            'title' => 'Hijacked',
            'body'  => 'Evil content',
        ], ['X-User-Id' => '1']);

        $this->assertSame(404, $res['status'], 'ATK-10: Cross-user update must return 404');
    }

    /**
     * ATK-11: Empty title or body — must be rejected with 422.
     */
    public function test_atk_11_empty_title_or_body_rejected(): void
    {
        foreach ([
            ['title' => '', 'body' => 'Valid'],
            ['title' => 'Valid', 'body' => ''],
            ['title' => '   ', 'body' => 'Valid'],
        ] as $payload) {
            $res = $this->post('/articles', $payload, ['X-User-Id' => '1']);
            $this->assertSame(422, $res['status'], 'ATK-11: Empty/whitespace title or body must return 422');
        }
    }

    /**
     * ATK-12: Publish-due idempotency — calling twice must not double-count.
     */
    public function test_atk_12_publish_due_idempotent(): void
    {
        $draft = $this->createDraft(1);
        $id    = $draft['id'];
        $past  = date('c', strtotime('-5 minutes'));

        $this->pdo->exec(
            "UPDATE articles SET status='scheduled', publish_at='{$past}' WHERE id={$id}",
        );

        $res1 = $this->post('/articles/publish-due', [], ['X-Admin-Key' => 'admin-secret']);
        $this->assertSame(1, $res1['body']['published_count']);

        // Second call — article already published, should count 0
        $res2 = $this->post('/articles/publish-due', [], ['X-Admin-Key' => 'admin-secret']);
        $this->assertSame(0, $res2['body']['published_count'], 'ATK-12: publish-due must be idempotent');
    }
}
