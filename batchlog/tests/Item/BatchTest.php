<?php

declare(strict_types=1);

namespace BatchLog\Tests\Item;

use BatchLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class BatchTest extends TestCase
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

    private function item(string $name, mixed $qty, mixed $price): mixed
    {
        return ['name' => $name, 'quantity' => $qty, 'price_cents' => $price];
    }

    // ── batch-level (422 before iterating) ────────────────────────────────────

    public function testRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/batch', [], ['items' => []])->getStatusCode());
    }

    public function testItemsMustBeArray(): void
    {
        $this->assertSame(422, $this->req('POST', '/batch', ['X-User-Id' => '1'], ['items' => 'nope'])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/batch', ['X-User-Id' => '1'], ['items' => ['k' => 'v']])->getStatusCode()); // assoc, not list
    }

    public function testEmptyBatchRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/batch', ['X-User-Id' => '1'], ['items' => []])->getStatusCode());
    }

    public function testOversizeBatchRejected(): void
    {
        $items = array_fill(0, 51, $this->item('x', 1, 1));
        $this->assertSame(422, $this->req('POST', '/batch', ['X-User-Id' => '1'], ['items' => $items])->getStatusCode());
    }

    // ── partial success (200) ─────────────────────────────────────────────────

    public function testPartialSuccess(): void
    {
        $items = [
            $this->item('Widget A', 3, 999),     // valid
            $this->item('Widget B', '5', 4999),  // ATK: quantity string → invalid
            $this->item('', 1, 100),             // invalid: empty name
        ];
        $res = $this->req('POST', '/batch', ['X-User-Id' => '1'], ['items' => $items]);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(1, $data['total_created']);
        $this->assertSame(2, $data['total_errors']);
        // index preserved
        $this->assertSame([1, 2], array_map(static fn (array $e): int => $e['index'], $data['errors']));
    }

    public function testAllValidCreated(): void
    {
        $items = [$this->item('A', 1, 100), $this->item('B', 2, 200)];
        $data = $this->json($this->req('POST', '/batch', ['X-User-Id' => '1'], ['items' => $items]));
        $this->assertSame(2, $data['total_created']);
        $this->assertSame(0, $data['total_errors']);
    }

    public function testAllInvalidStill200(): void
    {
        $items = [$this->item('', 1, 1), $this->item('x', 0, 1)];
        $res = $this->req('POST', '/batch', ['X-User-Id' => '1'], ['items' => $items]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, $this->json($res)['total_created']);
        $this->assertSame(2, $this->json($res)['total_errors']);
    }

    // ── type-confusion / object guards ────────────────────────────────────────

    public function testTypeConfusionRejectedPerItem(): void
    {
        $items = [
            $this->item('float qty', 5.5, 100),  // float → invalid
            $this->item('bool qty', true, 100),   // bool → invalid
            ['name' => 'neg price', 'quantity' => 1, 'price_cents' => -5], // negative
        ];
        $data = $this->json($this->req('POST', '/batch', ['X-User-Id' => '1'], ['items' => $items]));
        $this->assertSame(0, $data['total_created']);
        $this->assertSame(3, $data['total_errors']);
    }

    public function testNonObjectItemRejected(): void
    {
        $items = ['scalar', [1, 2, 3]]; // string + JSON array (list) — both not objects
        $data = $this->json($this->req('POST', '/batch', ['X-User-Id' => '1'], ['items' => $items]));
        $this->assertSame(0, $data['total_created']);
        $this->assertSame(2, $data['total_errors']);
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListIsOwnerScoped(): void
    {
        $this->req('POST', '/batch', ['X-User-Id' => '1'], ['items' => [$this->item('a', 1, 1)]]);
        $this->req('POST', '/batch', ['X-User-Id' => '2'], ['items' => [$this->item('b', 1, 1)]]);
        $this->assertCount(1, $this->json($this->req('GET', '/items', ['X-User-Id' => '1']))['items']);
    }

    public function testListLimitGuard(): void
    {
        $this->assertSame(422, $this->req('GET', '/items', ['X-User-Id' => '1'], null, 'limit=10.5')->getStatusCode());
    }
}
