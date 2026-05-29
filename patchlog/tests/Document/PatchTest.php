<?php

declare(strict_types=1);

namespace PatchLog\Tests\Document;

use Nyholm\Psr7\Factory\Psr17Factory;
use PatchLog\AppFactory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class PatchTest extends TestCase
{
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
        $app = AppFactory::createSqlite($this->dbFile);
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

    /** @return array{int, string} id + ETag */
    private function create(string $title = 'Doc', string $user = '1'): array
    {
        $res = $this->req('POST', '/documents', ['X-User-Id' => $user], ['title' => $title]);
        assert($res->getStatusCode() === 201);
        return [(int) $this->json($res)['id'], $res->getHeaderLine('ETag')];
    }

    // ── create / get / etag ────────────────────────────────────────────────

    public function testCreateRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/documents', [], ['title' => 'x'])->getStatusCode());
    }

    public function testGetReturnsEtag(): void
    {
        [$id] = $this->create();
        $res = $this->req('GET', '/documents/' . $id);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('"' . $id . '-1"', $res->getHeaderLine('ETag'));
        $this->assertSame('draft', $this->json($res)['status']);
    }

    // ── ATK-01..03: immutable fields ─────────────────────────────────────────

    public function testImmutableFieldsRejected(): void
    {
        [$id, $etag] = $this->create();
        foreach (['id' => 999, 'owner_id' => 99, 'version' => 999, 'created_at' => 'x'] as $field => $value) {
            $res = $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], [$field => $value]);
            $this->assertSame(422, $res->getStatusCode(), "field {$field} must be rejected");
        }
    }

    // ── ATK-04 / ATK-10: type confusion ──────────────────────────────────────

    public function testTitleTypeConfusionRejected(): void
    {
        [$id, $etag] = $this->create();
        $this->assertSame(422, $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], ['title' => 42])->getStatusCode());
    }

    public function testStatusTypeConfusionRejected(): void
    {
        [$id, $etag] = $this->create();
        $this->assertSame(422, $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], ['status' => 2])->getStatusCode());
    }

    // ── ATK-05: IDOR ──────────────────────────────────────────────────────────

    public function testPatchByNonOwnerIs404(): void
    {
        [$id, $etag] = $this->create('Doc', '1');
        $res = $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '99', 'If-Match' => $etag], ['title' => 'Hacked']);
        $this->assertSame(404, $res->getStatusCode());
    }

    // ── ATK-06: optimistic lock ────────────────────────────────────────────────

    public function testStaleEtagYields412(): void
    {
        [$id, $etag] = $this->create();
        // first patch bumps version → etag stale
        $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], ['title' => 'v2']);
        $res = $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], ['title' => 'v3']);
        $this->assertSame(412, $res->getStatusCode());
    }

    public function testMissingIfMatchYields428(): void
    {
        [$id] = $this->create();
        $res = $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1'], ['title' => 'x']);
        $this->assertSame(428, $res->getStatusCode());
    }

    public function testFreshEtagSucceedsAndBumpsVersion(): void
    {
        [$id, $etag] = $this->create();
        $res = $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], ['status' => 'published']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('published', $this->json($res)['status']);
        $this->assertSame(2, $this->json($res)['version']);
        $this->assertSame('"' . $id . '-2"', $res->getHeaderLine('ETag'));
    }

    // ── ATK-08 / 09 / 11: merge-patch semantics ──────────────────────────────

    public function testEmptyPatchIsNoOp(): void
    {
        [$id, $etag] = $this->create();
        $res = $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], []);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, $this->json($res)['version']); // no-op → version unchanged
    }

    public function testNullStatusResetsToDraft(): void
    {
        [$id, $etag] = $this->create();
        $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], ['status' => 'published']);
        $cur = $this->json($this->req('GET', '/documents/' . $id));
        $res = $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $cur['version'] === 2 ? '"' . $id . '-2"' : ''], ['status' => null]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('draft', $this->json($res)['status']);
    }

    public function testUnknownKeysIgnored(): void
    {
        [$id, $etag] = $this->create();
        $res = $this->req('PATCH', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], ['__proto__' => ['x' => 1], 'random' => 'y']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, $this->json($res)['version']); // no recognised change → no-op
    }

    // ── PUT full replace ────────────────────────────────────────────────────────

    public function testPutRequiresTitle(): void
    {
        [$id, $etag] = $this->create();
        $res = $this->req('PUT', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], ['status' => 'published']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testPutReplaces(): void
    {
        [$id, $etag] = $this->create('Old');
        $res = $this->req('PUT', '/documents/' . $id, ['X-User-Id' => '1', 'If-Match' => $etag], ['title' => 'New', 'status' => 'archived']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('New', $this->json($res)['title']);
        $this->assertSame('archived', $this->json($res)['status']);
    }

    // ── ATK-12: list query guards ─────────────────────────────────────────────

    public function testListQueryGuards(): void
    {
        $this->create();
        $this->assertSame(422, $this->req('GET', '/documents', [], null, 'limit=999999')->getStatusCode());
        $this->assertSame(422, $this->req('GET', '/documents', [], null, 'page=-1')->getStatusCode());
        $ok = $this->req('GET', '/documents', [], null, 'limit=10&page=1');
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(1, $this->json($ok)['total']);
    }

    public function testDeleteByNonOwnerIs404(): void
    {
        [$id] = $this->create('Doc', '1');
        $this->assertSame(404, $this->req('DELETE', '/documents/' . $id, ['X-User-Id' => '99'])->getStatusCode());
        $this->assertSame(204, $this->req('DELETE', '/documents/' . $id, ['X-User-Id' => '1'])->getStatusCode());
    }
}
