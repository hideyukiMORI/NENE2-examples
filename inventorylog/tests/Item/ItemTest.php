<?php

declare(strict_types=1);

namespace InventoryLog\Tests\Item;

use InventoryLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class ItemTest extends TestCase
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
    private function req(string $method, string $path, array $headers = [], mixed $body = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile, self::ADMIN_KEY);
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

    private function makeItem(string $sku = 'SKU-1', int $qty = 10): int
    {
        $res = $this->req('POST', '/items', ['X-Admin-Key' => self::ADMIN_KEY], ['sku' => $sku, 'name' => 'Widget', 'quantity' => $qty, 'price_cents' => 500]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    // ── create / admin ──────────────────────────────────────────────────────

    public function testCreateRequiresAdmin(): void
    {
        $this->assertSame(403, $this->req('POST', '/items', [], ['sku' => 'A', 'name' => 'x'])->getStatusCode());
    }

    public function testCreateWrongKeyIsForbidden(): void
    {
        $this->assertSame(403, $this->req('POST', '/items', ['X-Admin-Key' => 'nope'], ['sku' => 'A1', 'name' => 'x'])->getStatusCode());
    }

    public function testCreateAndGet(): void
    {
        $id = $this->makeItem('ABC-123', 7);
        $data = $this->json($this->req('GET', '/items/' . $id));
        $this->assertSame('ABC-123', $data['sku']);
        $this->assertSame(7, $data['quantity']);
    }

    public function testDuplicateSkuConflicts(): void
    {
        $this->makeItem('DUP-1');
        $res = $this->req('POST', '/items', ['X-Admin-Key' => self::ADMIN_KEY], ['sku' => 'DUP-1', 'name' => 'y', 'quantity' => 1, 'price_cents' => 1]);
        $this->assertSame(409, $res->getStatusCode());
    }

    // ── ATK: validation ──────────────────────────────────────────────────────

    public function testAtkSqlInjectionInSkuRejected(): void
    {
        $res = $this->req('POST', '/items', ['X-Admin-Key' => self::ADMIN_KEY], ['sku' => "x'; DROP TABLE items;--", 'name' => 'n', 'quantity' => 1, 'price_cents' => 1]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testAtkFloatPriceRejected(): void
    {
        $res = $this->req('POST', '/items', ['X-Admin-Key' => self::ADMIN_KEY], ['sku' => 'F1', 'name' => 'n', 'quantity' => 1, 'price_cents' => 1.5]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testAtkOversizedQuantityRejected(): void
    {
        $res = $this->req('POST', '/items', ['X-Admin-Key' => self::ADMIN_KEY], ['sku' => 'Q1', 'name' => 'n', 'quantity' => 2000000, 'price_cents' => 1]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testAtkNonDigitPathIdIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/items/abc')->getStatusCode());
    }

    // ── adjust ────────────────────────────────────────────────────────────────

    public function testRestockIncreasesQuantity(): void
    {
        $id = $this->makeItem('R1', 5);
        $res = $this->req('POST', '/items/' . $id . '/adjust', ['X-Admin-Key' => self::ADMIN_KEY], ['delta' => 10, 'reason' => 'restock']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(15, $this->json($res)['quantity']);
    }

    public function testConsumeDecreasesQuantity(): void
    {
        $id = $this->makeItem('C1', 5);
        $res = $this->req('POST', '/items/' . $id . '/adjust', ['X-Admin-Key' => self::ADMIN_KEY], ['delta' => -3, 'reason' => 'sale']);
        $this->assertSame(2, $this->json($res)['quantity']);
    }

    public function testDrainToZeroAllowed(): void
    {
        $id = $this->makeItem('Z1', 5);
        $res = $this->req('POST', '/items/' . $id . '/adjust', ['X-Admin-Key' => self::ADMIN_KEY], ['delta' => -5]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, $this->json($res)['quantity']);
    }

    public function testAtkOverDrainConflictsAndKeepsStock(): void
    {
        $id = $this->makeItem('O1', 5);
        $res = $this->req('POST', '/items/' . $id . '/adjust', ['X-Admin-Key' => self::ADMIN_KEY], ['delta' => -10]);
        $this->assertSame(409, $res->getStatusCode());
        // stock unchanged
        $this->assertSame(5, $this->json($this->req('GET', '/items/' . $id))['quantity']);
    }

    public function testAtkFloatDeltaRejected(): void
    {
        $id = $this->makeItem('FD1');
        $this->assertSame(422, $this->req('POST', '/items/' . $id . '/adjust', ['X-Admin-Key' => self::ADMIN_KEY], ['delta' => 1.0])->getStatusCode());
    }

    public function testAtkZeroDeltaRejected(): void
    {
        $id = $this->makeItem('ZD1');
        $this->assertSame(422, $this->req('POST', '/items/' . $id . '/adjust', ['X-Admin-Key' => self::ADMIN_KEY], ['delta' => 0])->getStatusCode());
    }

    public function testAdjustRequiresAdmin(): void
    {
        $id = $this->makeItem('AD1');
        $this->assertSame(403, $this->req('POST', '/items/' . $id . '/adjust', [], ['delta' => 1])->getStatusCode());
    }

    // ── history ────────────────────────────────────────────────────────────────

    public function testHistoryRecordsAdjustments(): void
    {
        $id = $this->makeItem('H1', 10);
        $this->req('POST', '/items/' . $id . '/adjust', ['X-Admin-Key' => self::ADMIN_KEY], ['delta' => 5, 'reason' => 'restock']);
        $this->req('POST', '/items/' . $id . '/adjust', ['X-Admin-Key' => self::ADMIN_KEY], ['delta' => -3, 'reason' => 'sale']);

        $data = $this->json($this->req('GET', '/items/' . $id . '/history'));
        $this->assertSame(2, $data['count']);
        $this->assertSame([15, 12], array_map(static fn (array $h): int => $h['quantity_after'], $data['history']));
        $this->assertSame(['restock', 'sale'], array_map(static fn (array $h): string => $h['reason'], $data['history']));
    }
}
