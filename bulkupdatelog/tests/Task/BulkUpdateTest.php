<?php

declare(strict_types=1);

namespace BulkUpdateLog\Tests\Task;

use BulkUpdateLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class BulkUpdateTest extends TestCase
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
    private function req(string $method, string $path, array $headers = [], mixed $body = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
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

    private function create(string $title, string $user = '1'): int
    {
        $res = $this->req('POST', '/tasks', ['X-User-Id' => $user], ['title' => $title]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    private function statusOf(int $id, string $user = '1'): string
    {
        foreach ($this->json($this->req('GET', '/tasks', ['X-User-Id' => $user]))['tasks'] as $t) {
            if ((int) $t['id'] === $id) {
                return (string) $t['status'];
            }
        }
        return '<absent>';
    }

    // ── auth (V-01 hardening) ───────────────────────────────────────────────────

    public function testCreateRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/tasks', [], ['title' => 'x'])->getStatusCode());
    }

    public function testBulkStatusRequiresUser(): void
    {
        $this->assertSame(401, $this->req('PATCH', '/tasks/status', [], ['updates' => []])->getStatusCode());
    }

    // ── per-item bulk update ──────────────────────────────────────────────────────

    public function testPerItemPartialSuccess(): void
    {
        $a = $this->create('A');
        $b = $this->create('B');
        $res = $this->req('PATCH', '/tasks/status', ['X-User-Id' => '1'], ['updates' => [
            ['id' => $a, 'status' => 'done'],
            ['id' => $b, 'status' => 'cancelled'],
            ['id' => 9999, 'status' => 'done'],       // not found
            ['id' => $a, 'status' => 'teleported'],    // invalid status
            ['status' => 'done'],                       // missing id
        ]]);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame([$a, $b], $data['updated']);
        $this->assertCount(3, $data['failed']);
        $this->assertSame('done', $this->statusOf($a));
        $this->assertSame('cancelled', $this->statusOf($b));
    }

    public function testAllFailStill200(): void
    {
        $res = $this->req('PATCH', '/tasks/status', ['X-User-Id' => '1'], ['updates' => [
            ['id' => 9999, 'status' => 'done'],
        ]]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([], $this->json($res)['updated']);
        $this->assertCount(1, $this->json($res)['failed']);
    }

    public function testEmptyUpdatesRejected(): void
    {
        $this->assertSame(422, $this->req('PATCH', '/tasks/status', ['X-User-Id' => '1'], ['updates' => []])->getStatusCode());
    }

    public function testOversizeBatchRejected(): void // V-02
    {
        $updates = array_fill(0, 101, ['id' => 1, 'status' => 'done']);
        $this->assertSame(422, $this->req('PATCH', '/tasks/status', ['X-User-Id' => '1'], ['updates' => $updates])->getStatusCode());
    }

    // ── ownership scoping (V-01) ────────────────────────────────────────────────────

    public function testCannotUpdateOthersTask(): void
    {
        $a = $this->create('owned by 1', '1');
        // user 2 tries to cancel user 1's task → reported as not found, not mutated
        $res = $this->req('PATCH', '/tasks/status', ['X-User-Id' => '2'], ['updates' => [['id' => $a, 'status' => 'cancelled']]]);
        $data = $this->json($res);
        $this->assertSame([], $data['updated']);
        $this->assertSame($a, $data['failed'][0]['id']);
        $this->assertSame('pending', $this->statusOf($a, '1')); // untouched
    }

    // ── homogeneous bulk done ──────────────────────────────────────────────────────

    public function testBulkDone(): void
    {
        $a = $this->create('A');
        $b = $this->create('B');
        $res = $this->req('PATCH', '/tasks/done', ['X-User-Id' => '1'], ['ids' => [$a, $b]]);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        sort($data['updated']);
        $this->assertSame([$a, $b], $data['updated']);
        $this->assertSame('done', $this->statusOf($a));
    }

    public function testBulkDoneFiltersNonIntegers(): void
    {
        $a = $this->create('A');
        // mixed: valid int + junk; junk dropped, valid applied
        $res = $this->req('PATCH', '/tasks/done', ['X-User-Id' => '1'], ['ids' => [$a, 'x', 1.5, null]]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([$a], $this->json($res)['updated']);
    }

    public function testBulkDoneEmptyAfterFilterRejected(): void
    {
        $this->assertSame(422, $this->req('PATCH', '/tasks/done', ['X-User-Id' => '1'], ['ids' => ['x', 1.5]])->getStatusCode());
    }

    public function testBulkDoneOwnerScoped(): void
    {
        $a = $this->create('owned by 1', '1');
        // user 2 cannot mark user 1's task done
        $res = $this->req('PATCH', '/tasks/done', ['X-User-Id' => '2'], ['ids' => [$a]]);
        $this->assertSame([], $this->json($res)['updated']);
        $this->assertSame('pending', $this->statusOf($a, '1'));
    }
}
