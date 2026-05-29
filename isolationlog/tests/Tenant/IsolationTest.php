<?php

declare(strict_types=1);

namespace IsolationLog\Tests\Tenant;

use IsolationLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class IsolationTest extends TestCase
{
    private const ADMIN_KEY = 'admin-key';

    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $pdo->exec($schema);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $headers */
    private function req(string $method, string $path, array $headers = [], mixed $body = null, ?string $query = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile, self::ADMIN_KEY);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        if ($query !== null) {
            parse_str($query, $q);
            $request = $request->withQueryParams($q);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        assert(is_array($decoded));
        return $decoded;
    }

    private function tenant(string $name): int
    {
        $res = $this->req('POST', '/tenants', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => $name]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    /** @param array<string, mixed> $body */
    private function note(int $tenant, int $user, array $body): ResponseInterface
    {
        return $this->req('POST', '/notes', ['X-Tenant-Id' => (string) $tenant, 'X-User-Id' => (string) $user], $body);
    }

    // ── admin / tenant ───────────────────────────────────────────────────────

    public function testTenantCreateRequiresAdmin(): void
    {
        $this->assertSame(401, $this->req('POST', '/tenants', [], ['name' => 'x'])->getStatusCode()); // ATK-08
        $this->assertSame(401, $this->req('POST', '/tenants', ['X-Admin-Key' => 'wrong'], ['name' => 'x'])->getStatusCode()); // ATK-09
    }

    // ── ATK-01: no auth ────────────────────────────────────────────────────────

    public function testNotesRequireIdentity(): void
    {
        $this->assertSame(401, $this->req('GET', '/notes')->getStatusCode());
    }

    // ── ATK-03/06/07: header validation ─────────────────────────────────────────

    public function testInvalidTenantHeaderRejected(): void
    {
        foreach (['1.5', '+1', '1 OR 1=1', '0', '-1', str_repeat('9', 20)] as $bad) {
            $res = $this->req('GET', '/notes', ['X-Tenant-Id' => $bad, 'X-User-Id' => '1']);
            $this->assertSame(401, $res->getStatusCode(), "tenant header '{$bad}' must be rejected");
        }
    }

    // ── ATK-10: tenant must exist ────────────────────────────────────────────────

    public function testNoteForNonexistentTenant(): void
    {
        $this->assertSame(422, $this->note(999, 1, ['title' => 'x'])->getStatusCode());
    }

    // ── ATK-04: body tenant_id ignored ────────────────────────────────────────────

    public function testBodyTenantIdIgnored(): void
    {
        $t1 = $this->tenant('T1');
        $this->tenant('T2');
        $res = $this->note($t1, 1, ['title' => 'mine', 'tenant_id' => 999]);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame($t1, $this->json($res)['tenant_id']); // header wins, not 999
    }

    // ── ATK-02/05/11: cross-tenant isolation ────────────────────────────────────────

    public function testCrossTenantGetIs404(): void
    {
        $t1 = $this->tenant('T1');
        $t2 = $this->tenant('T2');
        $noteId = (int) $this->json($this->note($t1, 1, ['title' => 'secret']))['id'];

        // T2 cannot read T1's note — 404, not 403 (no existence leak)
        $res = $this->req('GET', "/notes/{$noteId}", ['X-Tenant-Id' => (string) $t2, 'X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
        // T1 can
        $this->assertSame(200, $this->req('GET', "/notes/{$noteId}", ['X-Tenant-Id' => (string) $t1, 'X-User-Id' => '1'])->getStatusCode());
    }

    public function testCrossTenantDeleteIs404AndNoteSurvives(): void
    {
        $t1 = $this->tenant('T1');
        $t2 = $this->tenant('T2');
        $noteId = (int) $this->json($this->note($t1, 1, ['title' => 'secret']))['id'];

        $this->assertSame(404, $this->req('DELETE', "/notes/{$noteId}", ['X-Tenant-Id' => (string) $t2, 'X-User-Id' => '1'])->getStatusCode());
        $this->assertSame(200, $this->req('GET', "/notes/{$noteId}", ['X-Tenant-Id' => (string) $t1, 'X-User-Id' => '1'])->getStatusCode());
    }

    public function testListIsTenantScoped(): void
    {
        $t1 = $this->tenant('T1');
        $t2 = $this->tenant('T2');
        $this->note($t1, 1, ['title' => 'a']);
        $this->note($t1, 1, ['title' => 'b']);
        $this->note($t2, 1, ['title' => 'c']);

        $this->assertSame(2, $this->json($this->req('GET', '/notes', ['X-Tenant-Id' => (string) $t1, 'X-User-Id' => '1']))['count']);
        $this->assertSame(1, $this->json($this->req('GET', '/notes', ['X-Tenant-Id' => (string) $t2, 'X-User-Id' => '1']))['count']);
    }

    // ── ATK-12: query guards ──────────────────────────────────────────────────────

    public function testListQueryGuards(): void
    {
        $t1 = $this->tenant('T1');
        $h = ['X-Tenant-Id' => (string) $t1, 'X-User-Id' => '1'];
        $this->assertSame(422, $this->req('GET', '/notes', $h, null, 'limit=-1')->getStatusCode());
        $this->assertSame(422, $this->req('GET', '/notes', $h, null, 'limit=10.5')->getStatusCode());
    }
}
