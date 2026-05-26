<?php

declare(strict_types=1);

namespace Grantlog\Tests\Grant;

use Grantlog\Grant\GrantRepository;
use Grantlog\Grant\RouteRegistrar;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class GrantTest extends TestCase
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
        $repository = new GrantRepository($executor);
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
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>}
     */
    private function delete(string $path, array $headers = []): array
    {
        $request  = new ServerRequest('DELETE', $path, $headers);
        $response = $this->router->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function createGrant(
        int    $grantorId = 1,
        int    $granteeId = 2,
        string $resource  = 'doc:42',
        string $scope     = 'read',
        string $expiresAt = '',
    ): array {
        if ($expiresAt === '') {
            $expiresAt = date('c', strtotime('+1 hour'));
        }
        $res = $this->post('/grants', [
            'grantee_id' => $granteeId,
            'resource'   => $resource,
            'scope'      => $scope,
            'expires_at' => $expiresAt,
        ], ['X-User-Id' => (string) $grantorId]);

        $this->assertSame(201, $res['status'], 'createGrant failed: ' . json_encode($res['body']));

        return $res['body'];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Functional Tests
    // ══════════════════════════════════════════════════════════════════════

    public function test_create_grant_returns_201(): void
    {
        $res = $this->post('/grants', [
            'grantee_id' => 2,
            'resource'   => 'doc:42',
            'scope'      => 'read',
            'expires_at' => date('c', strtotime('+1 hour')),
        ], ['X-User-Id' => '1']);

        $this->assertSame(201, $res['status']);
        $this->assertSame(1, $res['body']['grantor_id']);
        $this->assertSame(2, $res['body']['grantee_id']);
        $this->assertSame('doc:42', $res['body']['resource']);
        $this->assertSame('read', $res['body']['scope']);
        $this->assertNull($res['body']['revoked_at']);
    }

    public function test_grantee_can_use_active_grant(): void
    {
        $grant = $this->createGrant(grantorId: 1, granteeId: 2);
        $id    = $grant['id'];

        $res = $this->post("/grants/{$id}/use", [], ['X-User-Id' => '2']);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['granted']);
        $this->assertSame('doc:42', $res['body']['resource']);
        $this->assertSame(1, $res['body']['used_count']);
    }

    public function test_used_count_increments_on_each_use(): void
    {
        $grant = $this->createGrant(grantorId: 1, granteeId: 2);
        $id    = $grant['id'];

        $this->post("/grants/{$id}/use", [], ['X-User-Id' => '2']);
        $this->post("/grants/{$id}/use", [], ['X-User-Id' => '2']);
        $res = $this->post("/grants/{$id}/use", [], ['X-User-Id' => '2']);

        $this->assertSame(3, $res['body']['used_count']);
    }

    public function test_grantor_can_list_issued_grants(): void
    {
        $this->createGrant(grantorId: 1, granteeId: 2, resource: 'doc:1');
        $this->createGrant(grantorId: 1, granteeId: 3, resource: 'doc:2');

        $res = $this->get('/grants/issued', ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertCount(2, $res['body']['data']);
    }

    public function test_grantee_can_list_received_grants(): void
    {
        $this->createGrant(grantorId: 1, granteeId: 2, resource: 'doc:A');
        $this->createGrant(grantorId: 3, granteeId: 2, resource: 'doc:B');

        $res = $this->get('/grants/received', ['X-User-Id' => '2']);

        $this->assertSame(200, $res['status']);
        $this->assertCount(2, $res['body']['data']);
    }

    public function test_grantor_can_revoke_grant(): void
    {
        $grant = $this->createGrant(grantorId: 1, granteeId: 2);
        $id    = $grant['id'];

        $res = $this->delete("/grants/{$id}", ['X-User-Id' => '1']);

        $this->assertSame(200, $res['status']);
        $this->assertNotNull($res['body']['revoked_at']);
    }

    public function test_scope_satisfies_hierarchy(): void
    {
        // Admin grant satisfies write and read requirements
        $grant = $this->createGrant(grantorId: 1, granteeId: 2, scope: 'admin');
        $this->assertSame('admin', $grant['scope']);

        // Grantee can use it
        $res = $this->post("/grants/{$grant['id']}/use", [], ['X-User-Id' => '2']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('admin', $res['body']['scope']);
    }

    public function test_duplicate_grant_returns_409(): void
    {
        $this->createGrant(grantorId: 1, granteeId: 2, resource: 'doc:42');
        $res = $this->post('/grants', [
            'grantee_id' => 2,
            'resource'   => 'doc:42',
            'scope'      => 'write',
            'expires_at' => date('c', strtotime('+2 hours')),
        ], ['X-User-Id' => '1']);

        $this->assertSame(409, $res['status']);
    }

    public function test_create_requires_auth(): void
    {
        $res = $this->post('/grants', [
            'grantee_id' => 2,
            'resource'   => 'doc:42',
            'expires_at' => date('c', strtotime('+1 hour')),
        ]);

        $this->assertSame(401, $res['status']);
    }

    public function test_issued_listing_requires_auth(): void
    {
        $res = $this->get('/grants/issued');
        $this->assertSame(401, $res['status']);
    }

    public function test_received_listing_requires_auth(): void
    {
        $res = $this->get('/grants/received');
        $this->assertSame(401, $res['status']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Cracker Attack Tests: ATK-01 through ATK-12
    //
    // Difficulty: Lv.2 (State Machine) + Lv.3 (Multi-party auth) +
    //             Lv.4 (Type confusion) + Lv.5 (Encoding)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * ATK-01: Use an EXPIRED grant — clock-boundary attack.
     *
     * Attacker creates a grant expiring 1 second in the future, waits until
     * it's past, then tries to use it.  We simulate with a direct DB update.
     *
     * Expectation: 403 with an "expired" error, NOT 200.
     */
    public function test_atk_01_expired_grant_rejected(): void
    {
        $grant = $this->createGrant(grantorId: 1, granteeId: 2);
        $id    = $grant['id'];

        // Force-expire the grant by back-dating its expires_at
        $past = date('c', strtotime('-1 second'));
        $this->pdo->exec("UPDATE grants SET expires_at='{$past}' WHERE id={$id}");

        $res = $this->post("/grants/{$id}/use", [], ['X-User-Id' => '2']);

        $this->assertSame(403, $res['status'], 'ATK-01: Expired grant must return 403');
        $this->assertStringContainsStringIgnoringCase('expired', (string) json_encode($res['body']), 'ATK-01: Error must mention expiry');
    }

    /**
     * ATK-02: Use a REVOKED grant — state-machine bypass attempt.
     *
     * Attacker obtains a grant, sees it revoked, then tries to use it anyway.
     * A naive implementation might only check expiry.
     *
     * Expectation: 403 with a "revoked" error.
     */
    public function test_atk_02_revoked_grant_rejected(): void
    {
        $grant = $this->createGrant(grantorId: 1, granteeId: 2);
        $id    = $grant['id'];

        // Revoke it
        $this->delete("/grants/{$id}", ['X-User-Id' => '1']);

        // Try to use after revocation
        $res = $this->post("/grants/{$id}/use", [], ['X-User-Id' => '2']);

        $this->assertSame(403, $res['status'], 'ATK-02: Revoked grant must return 403');
        $this->assertStringContainsStringIgnoringCase('revoked', (string) json_encode($res['body']), 'ATK-02: Error must mention revocation');
    }

    /**
     * ATK-03: SELF-GRANT attempt — grantor == grantee.
     *
     * Attacker sets X-User-Id=1 and body grantee_id=1, trying to grant
     * themselves access.
     *
     * Expectation: 422 — both app and DB CHECK reject it.
     */
    public function test_atk_03_self_grant_rejected(): void
    {
        $res = $this->post('/grants', [
            'grantee_id' => 1,
            'resource'   => 'doc:99',
            'scope'      => 'admin',
            'expires_at' => date('c', strtotime('+1 hour')),
        ], ['X-User-Id' => '1']);

        $this->assertSame(422, $res['status'], 'ATK-03: Self-grant must return 422');
    }

    /**
     * ATK-04: Use another user's grant (wrong grantee) — IDOR on POST /use.
     *
     * User 3 tries to use a grant that was issued to user 2.
     * Must return 404, not "access denied" (to avoid existence enumeration).
     *
     * Expectation: 404.
     */
    public function test_atk_04_wrong_grantee_gets_404(): void
    {
        $grant = $this->createGrant(grantorId: 1, granteeId: 2);
        $id    = $grant['id'];

        // User 3 attempts to use user 2's grant
        $res = $this->post("/grants/{$id}/use", [], ['X-User-Id' => '3']);

        $this->assertSame(404, $res['status'], 'ATK-04: Wrong grantee must get 404, not 403');
    }

    /**
     * ATK-05: Revoke another user's grant — IDOR on DELETE.
     *
     * Attacker (user 3) tries to revoke a grant belonging to grantor 1.
     * Must return 404 — not expose whether the grant exists.
     *
     * Expectation: 404.
     */
    public function test_atk_05_non_grantor_cannot_revoke(): void
    {
        $grant = $this->createGrant(grantorId: 1, granteeId: 2);
        $id    = $grant['id'];

        $res = $this->delete("/grants/{$id}", ['X-User-Id' => '3']);

        $this->assertSame(404, $res['status'], 'ATK-05: Non-grantor revoke must return 404 (IDOR)');

        // Grant must still be usable by the real grantee
        $useRes = $this->post("/grants/{$id}/use", [], ['X-User-Id' => '2']);
        $this->assertSame(200, $useRes['status'], 'ATK-05: Grant must still be active after failed revoke');
    }

    /**
     * ATK-06: Past expires_at — backdating the grant creation.
     *
     * Attacker submits a grant with expires_at in the past, hoping to create
     * an immediately-expired (and thus unusable) "test" grant, or to probe
     * whether the server validates the timestamp.
     *
     * Expectation: 422.
     */
    public function test_atk_06_past_expires_at_rejected(): void
    {
        foreach (['-1 second', '-1 day', '-1 year'] as $delta) {
            $past = date('c', strtotime($delta));
            $res  = $this->post('/grants', [
                'grantee_id' => 2,
                'resource'   => 'doc:42',
                'expires_at' => $past,
            ], ['X-User-Id' => '1']);

            $this->assertSame(422, $res['status'], "ATK-06: expires_at '{$delta}' (past) must return 422");
        }
    }

    /**
     * ATK-07: TYPE CONFUSION on grantee_id — string "2", null, true, float.
     *
     * Many PHP APIs accept string "2" as if it were int 2 via implicit coercion.
     * Our strict intField() must refuse non-int JSON values.
     *
     * Expectation: 422 for all non-integer types.
     */
    public function test_atk_07_type_confusion_on_grantee_id(): void
    {
        $future = date('c', strtotime('+1 hour'));

        // Each body represents a distinct type-confusion attack:
        // "2" = string that looks like a number (JSON string vs int)
        // null = null injection instead of integer
        // true = boolean coercible to 1 in many languages
        // 2.5 = float — note: 2.0 is indistinguishable from 2 after PHP json_encode
        // missing key = absent field
        $bodies = [
            ['grantee_id' => '2',  'resource' => 'doc:x', 'expires_at' => $future],
            ['grantee_id' => null, 'resource' => 'doc:x', 'expires_at' => $future],
            ['grantee_id' => true, 'resource' => 'doc:x', 'expires_at' => $future],
            ['grantee_id' => 2.5,  'resource' => 'doc:x', 'expires_at' => $future],
            // Missing key entirely
            [                      'resource' => 'doc:x', 'expires_at' => $future],
        ];

        foreach ($bodies as $body) {
            $res = $this->post('/grants', $body, ['X-User-Id' => '1']);
            $this->assertSame(
                422,
                $res['status'],
                'ATK-07: Non-int grantee_id must return 422; body=' . json_encode($body),
            );
        }
    }

    /**
     * ATK-08: PATH TRAVERSAL in resource name.
     *
     * Attacker uses resource names like "../../etc/passwd" or "doc:1/../admin"
     * hoping the server treats resource as a filesystem path.
     * The resource is an opaque string — it must be stored and returned verbatim.
     *
     * Expectation: 201 (stored safely as a literal string).
     */
    public function test_atk_08_path_traversal_in_resource_stored_safely(): void
    {
        $malicious = '../../etc/passwd';

        $res = $this->post('/grants', [
            'grantee_id' => 2,
            'resource'   => $malicious,
            'expires_at' => date('c', strtotime('+1 hour')),
        ], ['X-User-Id' => '1']);

        $this->assertSame(201, $res['status'], 'ATK-08: Path-traversal resource must be stored, not interpreted');
        $this->assertSame($malicious, $res['body']['resource'], 'ATK-08: Resource must be returned verbatim');

        // Ensure the grant is usable (not silently broken)
        $id  = $res['body']['id'];
        $use = $this->post("/grants/{$id}/use", [], ['X-User-Id' => '2']);
        $this->assertSame(200, $use['status'], 'ATK-08: Grant with path-traversal resource must still be usable');
    }

    /**
     * ATK-09: SQL INJECTION in resource and scope fields.
     *
     * Attacker sends resource = "'; DROP TABLE grants; --" and
     * scope = "read' OR '1'='1".  Parameterised queries must neutralise these.
     *
     * Expectation: scope injection → 422 (scope not in enum); resource injection → 201 (stored safely).
     */
    public function test_atk_09_sql_injection_in_fields(): void
    {
        $future = date('c', strtotime('+1 hour'));

        // Scope injection — must fail enum validation
        $resScope = $this->post('/grants', [
            'grantee_id' => 2,
            'resource'   => 'doc:42',
            'scope'      => "read' OR '1'='1",
            'expires_at' => $future,
        ], ['X-User-Id' => '1']);

        $this->assertSame(422, $resScope['status'], 'ATK-09: Injected scope must fail enum validation with 422');

        // Resource injection — stored verbatim, table must survive
        $sqlResource = "'; DROP TABLE grants; --";
        $resResource = $this->post('/grants', [
            'grantee_id' => 2,
            'resource'   => $sqlResource,
            'scope'      => 'read',
            'expires_at' => $future,
        ], ['X-User-Id' => '1']);

        $this->assertSame(201, $resResource['status'], 'ATK-09: SQL in resource must be stored safely, not executed');

        // Table must still exist
        $list = $this->get('/grants/issued', ['X-User-Id' => '1']);
        $this->assertSame(200, $list['status'], 'ATK-09: grants table must survive SQL injection attempt');
    }

    /**
     * ATK-10: UNICODE / BIDI resource names — homoglyph + bidirectional text.
     *
     * "doc:42" stored with Latin chars vs with Cyrillic homoglyphs are distinct
     * resources.  Also test BIDI override chars that could confuse log readers.
     * Both must be stored verbatim and treated as different resources.
     *
     * Expectation: 201 each (different resources), no crash or normalisation.
     */
    public function test_atk_10_unicode_and_bidi_in_resource(): void
    {
        $future = date('c', strtotime('+1 hour'));

        // Cyrillic 'о' (U+043E) looks identical to Latin 'o' but is a different codepoint
        $homoglyphResource = "doc:4\u{043E}"; // "doc:4о" — Cyrillic o

        $res1 = $this->post('/grants', [
            'grantee_id' => 2,
            'resource'   => 'doc:42',
            'scope'      => 'read',
            'expires_at' => $future,
        ], ['X-User-Id' => '1']);

        $this->assertSame(201, $res1['status'], 'ATK-10: Latin resource must be created');

        // BIDI override character — could be used to disguise resource names in logs
        $bidiResource = "doc:\u{202E}legitimate"; // U+202E RIGHT-TO-LEFT OVERRIDE

        $res2 = $this->post('/grants', [
            'grantee_id' => 2,
            'resource'   => $bidiResource,
            'scope'      => 'read',
            'expires_at' => $future,
        ], ['X-User-Id' => '1']);

        $this->assertSame(201, $res2['status'], 'ATK-10: BIDI resource must be stored verbatim without normalisation');
        $this->assertSame($bidiResource, $res2['body']['resource'], 'ATK-10: BIDI chars must be returned as-is');

        // The homoglyph resource is a DIFFERENT resource — must not collide
        $res3 = $this->post('/grants', [
            'grantee_id' => 2,
            'resource'   => $homoglyphResource,
            'scope'      => 'read',
            'expires_at' => $future,
        ], ['X-User-Id' => '1']);

        $this->assertSame(201, $res3['status'], 'ATK-10: Homoglyph resource must be treated as distinct from Latin variant');
    }

    /**
     * ATK-11: DOUBLE REVOKE — state-machine attack.
     *
     * Attacker revokes a grant twice (e.g. via concurrent race or retry),
     * hoping to trigger an unhandled state or reset revoked_at to a new timestamp.
     *
     * Expectation: first revoke → 200; second revoke → 409.
     */
    public function test_atk_11_double_revoke_returns_409(): void
    {
        $grant = $this->createGrant(grantorId: 1, granteeId: 2);
        $id    = $grant['id'];

        $first = $this->delete("/grants/{$id}", ['X-User-Id' => '1']);
        $this->assertSame(200, $first['status'], 'ATK-11: First revoke must succeed');
        $revokedAt = $first['body']['revoked_at'];

        $second = $this->delete("/grants/{$id}", ['X-User-Id' => '1']);
        $this->assertSame(409, $second['status'], 'ATK-11: Double revoke must return 409');

        // revoked_at must not change after the first revoke
        $stmt = $this->pdo->query("SELECT revoked_at FROM grants WHERE id={$id}");
        $this->assertNotFalse($stmt, 'ATK-11: query must succeed');
        $refetched = $stmt->fetchColumn();
        $this->assertSame($revokedAt, $refetched, 'ATK-11: revoked_at must not change on second revoke attempt');
    }

    /**
     * ATK-12: GRANTOR USES OWN GRANT — party confusion.
     *
     * The grantor (user 1) tries to use a grant they issued to user 2,
     * impersonating the grantee role.
     *
     * Expectation: 404 — grantor is not the grantee.
     */
    public function test_atk_12_grantor_cannot_use_own_grant(): void
    {
        $grant = $this->createGrant(grantorId: 1, granteeId: 2);
        $id    = $grant['id'];

        // Grantor (user 1) tries to use the grant themselves
        $res = $this->post("/grants/{$id}/use", [], ['X-User-Id' => '1']);

        $this->assertSame(404, $res['status'], 'ATK-12: Grantor must not be able to use their own grant as grantee');

        // Confirm the actual grantee can still use it normally
        $legit = $this->post("/grants/{$id}/use", [], ['X-User-Id' => '2']);
        $this->assertSame(200, $legit['status'], 'ATK-12: Legitimate grantee must still be able to use the grant');
    }
}
