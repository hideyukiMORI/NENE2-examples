<?php

declare(strict_types=1);

namespace DedupLog\Tests\Dedup;

use DedupLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * FT170 クラッカー攻撃試験: ATK-01〜12
 * Request Deduplication セキュリティ耐久性評価
 */
final class AttackTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/deduplog_atk_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $this->app = AppFactory::createSqlite($this->dbFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function req(
        string $method,
        string $path,
        mixed $body = null,
        string $idempotencyKey = '',
    ): ResponseInterface {
        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($idempotencyKey !== '') {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $res->getBody(), true);
    }

    // ATK-01: SQL injection in Idempotency-Key header
    public function testAtk01SqlInjectionInIdempotencyKey(): void
    {
        $maliciousKey = "key'; DROP TABLE payments; --";
        $res = $this->req('POST', '/payments', ['amount' => 100], $maliciousKey);
        $this->assertSame(201, $res->getStatusCode());
        // Table still exists — subsequent operations work
        $res2 = $this->req('POST', '/payments', ['amount' => 200], 'normal-key');
        $this->assertSame(201, $res2->getStatusCode());
    }

    // ATK-02: SQL injection in amount field (non-numeric → rejected)
    public function testAtk02SqlInjectionInAmountRejected(): void
    {
        $res = $this->req('POST', '/payments', ['amount' => "100; DROP TABLE payments; --"], 'atk-02');
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-03: SQL injection in item field stored safely
    public function testAtk03SqlInjectionInItemStoredSafely(): void
    {
        $maliciousItem = "Widget'); DROP TABLE orders; --";
        $res = $this->req('POST', '/orders', ['item' => $maliciousItem, 'quantity' => 1], 'atk-03');
        $this->assertSame(201, $res->getStatusCode());
        // Table intact
        $res2 = $this->req('POST', '/orders', ['item' => 'Normal', 'quantity' => 1], 'atk-03b');
        $this->assertSame(201, $res2->getStatusCode());
    }

    // ATK-04: Replay attack — sending same request 10 times creates only 1 record
    public function testAtk04ReplayAttackCreatesOneRecord(): void
    {
        $key = 'replay-attack-key';
        for ($i = 0; $i < 10; $i++) {
            $res = $this->req('POST', '/payments', ['amount' => 500], $key);
            $this->assertContains($res->getStatusCode(), [201]);
        }
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query('SELECT COUNT(*) FROM payments');
        assert($stmt !== false);
        $this->assertSame('1', (string) $stmt->fetchColumn());
    }

    // ATK-05: Empty Idempotency-Key (whitespace only) → 400
    public function testAtk05WhitespaceOnlyKeyRejected(): void
    {
        $res = $this->req('POST', '/payments', ['amount' => 100], '   ');
        $this->assertSame(400, $res->getStatusCode());
    }

    // ATK-06: Extremely long Idempotency-Key — no crash
    public function testAtk06VeryLongKeyHandled(): void
    {
        $longKey = str_repeat('k', 10000);
        $res = $this->req('POST', '/payments', ['amount' => 100], $longKey);
        // Should succeed or fail gracefully
        $this->assertNotSame(500, $res->getStatusCode());
    }

    // ATK-07: Negative quantity in order → 422
    public function testAtk07NegativeQuantityRejected(): void
    {
        $res = $this->req('POST', '/orders', ['item' => 'Widget', 'quantity' => -5], 'atk-07');
        $this->assertSame(422, $res->getStatusCode());
        // No order created
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query('SELECT COUNT(*) FROM orders');
        assert($stmt !== false);
        $this->assertSame('0', (string) $stmt->fetchColumn());
    }

    // ATK-08: XSS in item field stored as literal
    public function testAtk08XssInItemStoredLiteral(): void
    {
        $xssItem = '<script>alert("xss")</script>';
        $res  = $this->req('POST', '/orders', ['item' => $xssItem, 'quantity' => 1], 'atk-08');
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame($xssItem, $data['item']);
        $this->assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
    }

    // ATK-09: Concurrent duplicate keys (simulated sequential) — only one record
    public function testAtk09ConcurrentDuplicateKeys(): void
    {
        $key = 'concurrent-key';
        $firstId = null;
        for ($i = 0; $i < 5; $i++) {
            $res  = $this->req('POST', '/payments', ['amount' => 100], $key);
            $data = $this->json($res);
            if ($firstId === null) {
                $firstId = (int) $data['id'];
            } else {
                $this->assertSame($firstId, (int) $data['id']);
            }
        }
        $pdo  = new \PDO('sqlite:' . $this->dbFile);
        $stmt = $pdo->query('SELECT COUNT(*) FROM payments');
        assert($stmt !== false);
        $this->assertSame('1', (string) $stmt->fetchColumn());
    }

    // ATK-10: Integer overflow in amount (very large number)
    public function testAtk10IntegerOverflowInAmount(): void
    {
        $res = $this->req('POST', '/payments', ['amount' => PHP_INT_MAX], 'atk-10');
        // Should succeed (large positive int is valid) or fail gracefully
        $this->assertNotSame(500, $res->getStatusCode());
    }

    // ATK-11: NULL amount → treated as 0 → 422
    public function testAtk11NullAmountRejected(): void
    {
        $res = $this->req('POST', '/payments', ['amount' => null], 'atk-11');
        $this->assertSame(422, $res->getStatusCode());
    }

    // ATK-12: Response never contains internal path or stack trace
    public function testAtk12NoInternalInfoLeaked(): void
    {
        // Missing key → 400
        $res  = $this->req('POST', '/payments', ['amount' => 100]);
        $body = (string) $res->getBody();
        $this->assertStringNotContainsString('/home/', $body);
        $this->assertStringNotContainsString('stack', strtolower($body));
        $this->assertStringNotContainsString('sqlite', strtolower($body));

        // Validation error → 422
        $res2  = $this->req('POST', '/payments', ['amount' => -1], 'atk-12');
        $body2 = (string) $res2->getBody();
        $this->assertStringNotContainsString('/home/', $body2);
    }
}
