<?php

declare(strict_types=1);

namespace ProductLog\Tests\Product;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use ProductLog\AppFactory;
use Psr\Http\Message\ResponseInterface;

class ProductTest extends TestCase
{
    private const ADMIN_KEY = 'admin-secret-key';

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
    private function req(string $method, string $path, array $headers = [], mixed $body = null, ?string $query = null, string $adminKey = self::ADMIN_KEY): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile, $adminKey);
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

    /** @param array<string, mixed> $overrides */
    private function create(array $overrides = []): ResponseInterface
    {
        $body = array_merge(['sku' => 'WIDGET-1', 'name' => 'Widget', 'price_cents' => 999], $overrides);
        return $this->req('POST', '/products', ['X-Admin-Key' => self::ADMIN_KEY], $body);
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function testCreateAndGet(): void
    {
        $id = (int) $this->json($this->create())['id'];
        $res = $this->req('GET', '/products/' . $id);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('WIDGET-1', $this->json($res)['sku']);
    }

    public function testDuplicateSkuIs409(): void
    {
        $this->create();
        $this->assertSame(409, $this->create()->getStatusCode());
    }

    // ── ATK-02 / ATK-10: admin auth ────────────────────────────────────────────

    public function testCreateRequiresAdmin(): void
    {
        $this->assertSame(403, $this->req('POST', '/products', [], ['sku' => 'X-1', 'name' => 'n', 'price_cents' => 1])->getStatusCode());
    }

    public function testWrongAdminKeyForbidden(): void
    {
        $this->assertSame(403, $this->req('POST', '/products', ['X-Admin-Key' => 'wrong'], ['sku' => 'X-1', 'name' => 'n', 'price_cents' => 1])->getStatusCode());
    }

    public function testEmptyConfiguredKeyFailsClosed(): void // ATK-02
    {
        $res = $this->req('POST', '/products', ['X-Admin-Key' => ''], ['sku' => 'X-1', 'name' => 'n', 'price_cents' => 1], null, '');
        $this->assertSame(403, $res->getStatusCode());
    }

    // ── ATK-05: float price ────────────────────────────────────────────────────

    public function testFloatPriceRejected(): void
    {
        $this->assertSame(422, $this->create(['price_cents' => 9.99])->getStatusCode());
    }

    public function testNegativePriceRejected(): void
    {
        $this->assertSame(422, $this->create(['price_cents' => -5])->getStatusCode());
    }

    // ── ATK-06 / ATK-09: SKU validation ────────────────────────────────────────

    public function testSkuInjectionRejected(): void
    {
        foreach (["'; DROP TABLE products; --", 'lower-case', 'has space', 'unicode✓', str_repeat('A', 33)] as $bad) {
            $this->assertSame(422, $this->create(['sku' => $bad])->getStatusCode(), "sku '{$bad}' must be 422");
        }
    }

    // ── ATK-01 / ATK-07: search injection ──────────────────────────────────────

    public function testSearchInjectionIsInert(): void
    {
        $this->create(['sku' => 'A-1', 'name' => 'Apple']);
        $this->create(['sku' => 'B-1', 'name' => 'Banana']);
        // injection payload as keyword → just a literal LIKE pattern, table intact, 0 matches
        $res = $this->req('GET', '/products', [], null, 'q=' . rawurlencode("'; DROP TABLE products; --"));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, $this->json($res)['count']);
        // table still there
        $this->assertSame(2, $this->json($this->req('GET', '/products'))['count']);
    }

    public function testWildcardSearchMatchesBroadly(): void // ATK-07
    {
        $this->create(['sku' => 'A-1', 'name' => 'Apple']);
        $this->create(['sku' => 'B-1', 'name' => 'Banana']);
        // a bare % matches everything (intentional, parameterized)
        $this->assertSame(2, $this->json($this->req('GET', '/products', [], null, 'q=%'))['count']);
    }

    public function testKeywordLengthGuard(): void
    {
        $res = $this->req('GET', '/products', [], null, 'q=' . str_repeat('a', 101));
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testSearchFindsByName(): void
    {
        $this->create(['sku' => 'A-1', 'name' => 'Red Apple']);
        $this->create(['sku' => 'B-1', 'name' => 'Banana']);
        $data = $this->json($this->req('GET', '/products', [], null, 'q=apple'));
        $this->assertSame(1, $data['count']);
        $this->assertSame('A-1', $data['products'][0]['sku']);
    }

    // ── ATK-03 / ATK-04: id guards ──────────────────────────────────────────────

    public function testOverlongIdIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/products/' . str_repeat('9', 20))->getStatusCode());
    }

    public function testNegativeIdIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/products/-1')->getStatusCode());
    }

    // ── ATK-08 / soft delete ────────────────────────────────────────────────────

    public function testSoftDeleteHidesProduct(): void
    {
        $id = (int) $this->json($this->create())['id'];
        $this->assertSame(200, $this->req('DELETE', '/products/' . $id, ['X-Admin-Key' => self::ADMIN_KEY])->getStatusCode());
        // hidden from reads and list
        $this->assertSame(404, $this->req('GET', '/products/' . $id)->getStatusCode());
        $this->assertSame(0, $this->json($this->req('GET', '/products'))['count']);
    }

    public function testDoubleDeleteIs404(): void // ATK-08
    {
        $id = (int) $this->json($this->create())['id'];
        $this->req('DELETE', '/products/' . $id, ['X-Admin-Key' => self::ADMIN_KEY]);
        $this->assertSame(404, $this->req('DELETE', '/products/' . $id, ['X-Admin-Key' => self::ADMIN_KEY])->getStatusCode());
    }

    public function testDeleteRequiresAdmin(): void
    {
        $id = (int) $this->json($this->create())['id'];
        $this->assertSame(403, $this->req('DELETE', '/products/' . $id)->getStatusCode());
    }
}
