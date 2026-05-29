<?php

declare(strict_types=1);

namespace PriceLog\Tests\Price;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use PriceLog\AppFactory;
use Psr\Http\Message\ResponseInterface;

class PriceTest extends TestCase
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

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    private function req(string $method, string $path, array $headers = [], mixed $body = null, array $query = []): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile, self::ADMIN_KEY);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($query !== []) {
            $request = $request->withQueryParams($query);
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

    private function product(string $name = 'Widget'): int
    {
        $res = $this->req('POST', '/products', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => $name]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    private function setPrice(int $id, int $amount, string $from, string $currency = 'USD'): ResponseInterface
    {
        return $this->req('POST', "/products/{$id}/prices", ['X-Admin-Key' => self::ADMIN_KEY], ['amount' => $amount, 'currency' => $currency, 'effective_from' => $from]);
    }

    // ── admin gating (hardens ATK-01) ──────────────────────────────────────

    public function testCreateProductRequiresAdmin(): void
    {
        $this->assertSame(403, $this->req('POST', '/products', [], ['name' => 'x'])->getStatusCode());
    }

    public function testSetPriceRequiresAdmin(): void
    {
        $id = $this->product();
        $this->assertSame(403, $this->req('POST', "/products/{$id}/prices", [], ['amount' => 1, 'currency' => 'USD', 'effective_from' => '2026-01-01T00:00:00Z'])->getStatusCode());
    }

    // ── price timeline ────────────────────────────────────────────────────────

    public function testSetPriceClosesPreviousTier(): void
    {
        $id = $this->product();
        $this->assertSame(201, $this->setPrice($id, 1000, '2026-01-01T00:00:00Z')->getStatusCode());
        $this->assertSame(201, $this->setPrice($id, 1500, '2026-06-01T00:00:00Z')->getStatusCode());

        $history = $this->json($this->req('GET', "/products/{$id}/prices"))['prices'];
        $this->assertCount(2, $history);
        // newest first; previous tier now closed at the new effective_from
        $this->assertNull($history[0]['effective_to']);
        $this->assertSame('2026-06-01T00:00:00Z', $history[1]['effective_to']);
    }

    public function testPriceAtPointInTime(): void
    {
        $id = $this->product();
        $this->setPrice($id, 1000, '2026-01-01T00:00:00Z');
        $this->setPrice($id, 1500, '2026-06-01T00:00:00Z');

        $jan = $this->json($this->req('GET', "/products/{$id}/prices/at", [], null, ['datetime' => '2026-03-01T00:00:00Z']));
        $this->assertSame(1000, $jan['amount']);
        $jul = $this->json($this->req('GET', "/products/{$id}/prices/at", [], null, ['datetime' => '2026-07-01T00:00:00Z']));
        $this->assertSame(1500, $jul['amount']);
    }

    public function testCurrentPrice(): void
    {
        $id = $this->product();
        $this->setPrice($id, 1000, '2020-01-01T00:00:00Z');
        $data = $this->json($this->req('GET', "/products/{$id}/prices/current"));
        $this->assertSame(1000, $data['amount']);
    }

    // ── validation (hardens ATK-05/06/08) ─────────────────────────────────────

    public function testNegativeAmountRejected(): void
    {
        $id = $this->product();
        $this->assertSame(422, $this->setPrice($id, -100, '2026-01-01T00:00:00Z')->getStatusCode());
    }

    public function testFloatAmountRejected(): void
    {
        $id = $this->product();
        $this->assertSame(422, $this->req('POST', "/products/{$id}/prices", ['X-Admin-Key' => self::ADMIN_KEY], ['amount' => 9.99, 'currency' => 'USD', 'effective_from' => '2026-01-01T00:00:00Z'])->getStatusCode());
    }

    public function testZeroAmountAllowed(): void
    {
        $id = $this->product();
        $this->assertSame(201, $this->setPrice($id, 0, '2026-01-01T00:00:00Z')->getStatusCode());
    }

    public function testUnknownCurrencyRejected(): void
    {
        $id = $this->product();
        $this->assertSame(422, $this->setPrice($id, 100, '2026-01-01T00:00:00Z', 'NOTCURRENCY')->getStatusCode());
    }

    public function testInvalidEffectiveFromRejected(): void
    {
        $id = $this->product();
        $this->assertSame(422, $this->setPrice($id, 100, 'not-a-date')->getStatusCode());
        // semantically invalid calendar date
        $this->assertSame(422, $this->setPrice($id, 100, '2026-02-30T00:00:00Z')->getStatusCode());
    }

    public function testInvalidDatetimeQueryRejected(): void
    {
        $id = $this->product();
        $this->setPrice($id, 100, '2026-01-01T00:00:00Z');
        $this->assertSame(422, $this->req('GET', "/products/{$id}/prices/at", [], null, ['datetime' => 'not-a-date'])->getStatusCode());
    }

    // ── not found (ATK-11/12) ──────────────────────────────────────────────────

    public function testSetPriceUnknownProduct(): void
    {
        $this->assertSame(404, $this->setPrice(999, 100, '2026-01-01T00:00:00Z')->getStatusCode());
    }

    public function testNonNumericPathIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/products/abc')->getStatusCode());
    }
}
