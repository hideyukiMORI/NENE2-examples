<?php

declare(strict_types=1);

namespace ReorderLog\Tests\Reorder;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReorderLog\AppFactory;

class ReorderTest extends TestCase
{
    private PDO $pdo;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $this->pdo->exec($schema);

        // Users
        $this->pdo->exec("INSERT INTO users (id, name, created_at) VALUES (1, 'Alice', '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO users (id, name, created_at) VALUES (2, 'Bob', '2026-01-01T00:00:00Z')");

        // Boards: 1 owned by Alice, 2 owned by Bob
        $this->pdo->exec("INSERT INTO boards (id, owner_id, name, created_at) VALUES (1, 1, 'Alice board', '2026-01-01T00:00:00Z')");
        $this->pdo->exec("INSERT INTO boards (id, owner_id, name, created_at) VALUES (2, 2, 'Bob board', '2026-01-01T00:00:00Z')");

        // Board 1 items: ids 10,11,12 at positions 0,1,2
        $this->pdo->exec("INSERT INTO items (id, board_id, title, position) VALUES (10, 1, 'A', 0)");
        $this->pdo->exec("INSERT INTO items (id, board_id, title, position) VALUES (11, 1, 'B', 1)");
        $this->pdo->exec("INSERT INTO items (id, board_id, title, position) VALUES (12, 1, 'C', 2)");

        // Board 2 items: ids 20,21
        $this->pdo->exec("INSERT INTO items (id, board_id, title, position) VALUES (20, 2, 'X', 0)");
        $this->pdo->exec("INSERT INTO items (id, board_id, title, position) VALUES (21, 2, 'Y', 1)");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @param array<string, string> $headers */
    private function req(
        string $method,
        string $path,
        array $headers = [],
        mixed $body = null,
    ): ResponseInterface {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();

        $uri = $psr17->createUri('http://localhost' . $path);
        /** @var \Psr\Http\Message\ServerRequestInterface $request */
        $request = $psr17->createServerRequest($method, $uri);

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

    /**
     * The ids of board 1 in stored position order, read directly from the DB.
     *
     * @return list<int>
     */
    private function orderOfBoard1(): array
    {
        $stmt = $this->pdo->query('SELECT id FROM items WHERE board_id = 1 ORDER BY position ASC');
        assert($stmt !== false);
        return array_map(static fn (string $id): int => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ─── happy path ──────────────────────────────────────────────────────────

    public function testListRequiresAuth(): void
    {
        $this->assertSame(401, $this->req('GET', '/boards/1/items')->getStatusCode());
    }

    public function testListReturnsItemsInOrder(): void
    {
        $res = $this->req('GET', '/boards/1/items', ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
        $ids = array_map(static fn (array $i): int => (int) $i['id'], $this->json($res)['items']);
        $this->assertSame([10, 11, 12], $ids);
    }

    public function testReorderFull(): void
    {
        $res = $this->req('PUT', '/boards/1/order', ['X-User-Id' => '1'], ['ids' => [12, 10, 11]]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([12, 10, 11], $this->orderOfBoard1());

        // positions are reassigned 0..n-1 from the array index
        $positions = array_map(static fn (array $i): int => (int) $i['position'], $this->json($res)['items']);
        $this->assertSame([0, 1, 2], $positions);
    }

    /**
     * The key correctness test: swapping adjacent positions (0↔1) would raise a
     * transient UNIQUE(board_id, position) violation under a naive single-row
     * or single-statement update. The two-phase transaction must not collide.
     */
    public function testReorderAdjacentSwapDoesNotCollide(): void
    {
        $res = $this->req('PUT', '/boards/1/order', ['X-User-Id' => '1'], ['ids' => [11, 10, 12]]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([11, 10, 12], $this->orderOfBoard1());
    }

    public function testReorderReverseDoesNotCollide(): void
    {
        $res = $this->req('PUT', '/boards/1/order', ['X-User-Id' => '1'], ['ids' => [12, 11, 10]]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([12, 11, 10], $this->orderOfBoard1());
    }

    // ─── ATK assessment (executable) ──────────────────────────────────────────

    /** ATK-01 — reorder a board you do not own (IDOR). */
    public function testAtk01ReorderUnownedBoardIs404(): void
    {
        $res = $this->req('PUT', '/boards/1/order', ['X-User-Id' => '2'], ['ids' => [12, 10, 11]]);
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame([10, 11, 12], $this->orderOfBoard1()); // unchanged
    }

    /** ATK-02 — smuggle an item id from another board. */
    public function testAtk02ForeignItemIsRejected(): void
    {
        $res = $this->req('PUT', '/boards/1/order', ['X-User-Id' => '1'], ['ids' => [10, 11, 20]]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame([10, 11, 12], $this->orderOfBoard1());
    }

    /** ATK-03 — partial order (omit ids). */
    public function testAtk03PartialOrderIsRejected(): void
    {
        $res = $this->req('PUT', '/boards/1/order', ['X-User-Id' => '1'], ['ids' => [10, 11]]);
        $this->assertSame(422, $res->getStatusCode());
    }

    /** ATK-05/08 — non-integer / negative ids. */
    public function testAtk05NonIntegerIdsAreRejected(): void
    {
        $res = $this->req('PUT', '/boards/1/order', ['X-User-Id' => '1'], ['ids' => ['10; DROP TABLE items', 11, 12]]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testAtk08NegativeIdsAreRejected(): void
    {
        $res = $this->req('PUT', '/boards/1/order', ['X-User-Id' => '1'], ['ids' => [-10, 11, 12]]);
        $this->assertSame(422, $res->getStatusCode());
    }

    /** ATK-06 — duplicate ids. */
    public function testAtk06DuplicateIdsAreRejected(): void
    {
        $res = $this->req('PUT', '/boards/1/order', ['X-User-Id' => '1'], ['ids' => [10, 10, 12]]);
        $this->assertSame(422, $res->getStatusCode());
    }

    /** ATK-11 — empty order. */
    public function testAtk11EmptyOrderIsRejected(): void
    {
        $res = $this->req('PUT', '/boards/1/order', ['X-User-Id' => '1'], ['ids' => []]);
        $this->assertSame(422, $res->getStatusCode());
    }

    /** ATK-12 — unknown board and unowned board are indistinguishable (both 404). */
    public function testAtk12BoardEnumerationIsBlocked(): void
    {
        $unknown = $this->req('PUT', '/boards/999/order', ['X-User-Id' => '1'], ['ids' => [1]]);
        $unowned = $this->req('PUT', '/boards/2/order', ['X-User-Id' => '1'], ['ids' => [20, 21]]);
        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame(404, $unowned->getStatusCode());
        $this->assertSame((string) $unknown->getBody(), (string) $unowned->getBody());
    }
}
