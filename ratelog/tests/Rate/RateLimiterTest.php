<?php

declare(strict_types=1);

namespace RateLog\Tests\Rate;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RateLog\AppFactory;

class RateLimiterTest extends TestCase
{
    private const ADMIN_KEY = 'admin-secret';
    private const LIMIT = 3;
    private const WINDOW = 60;

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
    private function req(string $method, string $path, array $headers = [], mixed $body = null, array $query = [], string $adminKey = self::ADMIN_KEY): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile, $adminKey, self::LIMIT, self::WINDOW);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        if ($query !== []) {
            $request = $request->withQueryParams($query);
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

    private function check(string $user, string $endpoint, string $now): ResponseInterface
    {
        return $this->req('POST', '/rate/check', ['X-User-Id' => $user, 'X-Now' => $now], ['endpoint' => $endpoint]);
    }

    // ── basic limit ───────────────────────────────────────────────────────────

    public function testAllowsUpToLimitThenRejects(): void
    {
        $t = '2026-06-01 00:00:00';
        $this->assertSame(200, $this->check('1', 'api', $t)->getStatusCode());
        $this->assertSame(200, $this->check('1', 'api', $t)->getStatusCode());
        $r3 = $this->check('1', 'api', $t);
        $this->assertSame(200, $r3->getStatusCode());
        $this->assertSame(0, $this->json($r3)['remaining']);
        // 4th within the window → 429
        $this->assertSame(429, $this->check('1', 'api', $t)->getStatusCode());
    }

    public function testRejectedRequestIsNotRecorded(): void
    {
        $t = '2026-06-01 00:00:00';
        for ($i = 0; $i < 3; $i++) {
            $this->check('1', 'api', $t);
        }
        $this->check('1', 'api', $t); // 429, must not count
        // status still shows exactly LIMIT, not LIMIT+1
        $data = $this->json($this->req('GET', '/rate/status', ['X-User-Id' => '1', 'X-Now' => $t], null, ['endpoint' => 'api']));
        $this->assertSame(self::LIMIT, $data['count']);
    }

    public function testPerUserAndPerEndpointIsolation(): void
    {
        $t = '2026-06-01 00:00:00';
        for ($i = 0; $i < 3; $i++) {
            $this->check('1', 'api', $t);
        }
        // different user, and different endpoint for same user, are independent
        $this->assertSame(200, $this->check('2', 'api', $t)->getStatusCode());
        $this->assertSame(200, $this->check('1', 'other', $t)->getStatusCode());
    }

    // ── sliding window (the distinguishing behaviour vs fixed window) ──────────

    public function testWindowSlidesEventsOutGradually(): void
    {
        // 3 events spread across the window: 00:00, 00:30, 00:59 → limit reached
        $this->check('1', 'api', '2026-06-01 00:00:00');
        $this->check('1', 'api', '2026-06-01 00:00:30');
        $this->check('1', 'api', '2026-06-01 00:00:59');

        // at 00:01:00 the window is [00:00:00, 00:01:00] — all 3 still counted → 429
        $this->assertSame(429, $this->check('1', 'api', '2026-06-01 00:01:00')->getStatusCode());

        // at 00:01:01 the window is [00:00:01, 00:01:01] — the 00:00:00 event has
        // slid out, so only 2 remain and one slot frees up → 200 (NOT a hard reset).
        $this->assertSame(200, $this->check('1', 'api', '2026-06-01 00:01:01')->getStatusCode());
    }

    public function testFullyDrainsAfterWindow(): void
    {
        $this->check('1', 'api', '2026-06-01 00:00:00');
        $this->check('1', 'api', '2026-06-01 00:00:00');
        $this->check('1', 'api', '2026-06-01 00:00:00');
        // well past the window → all old events gone → count 0
        $data = $this->json($this->req('GET', '/rate/status', ['X-User-Id' => '1', 'X-Now' => '2026-06-01 00:02:00'], null, ['endpoint' => 'api']));
        $this->assertSame(0, $data['count']);
    }

    // ── status ──────────────────────────────────────────────────────────────────

    public function testStatusDoesNotRecord(): void
    {
        $t = '2026-06-01 00:00:00';
        $this->req('GET', '/rate/status', ['X-User-Id' => '1', 'X-Now' => $t], null, ['endpoint' => 'api']);
        $data = $this->json($this->req('GET', '/rate/status', ['X-User-Id' => '1', 'X-Now' => $t], null, ['endpoint' => 'api']));
        $this->assertSame(0, $data['count']); // querying status never increments
    }

    // ── admin reset ──────────────────────────────────────────────────────────────

    public function testAdminResetClearsCounters(): void
    {
        $t = '2026-06-01 00:00:00';
        for ($i = 0; $i < 3; $i++) {
            $this->check('1', 'api', $t);
        }
        $this->assertSame(429, $this->check('1', 'api', $t)->getStatusCode());
        $this->assertSame(200, $this->req('DELETE', '/rate/reset/1', ['X-Admin-Key' => self::ADMIN_KEY])->getStatusCode());
        // after reset, allowed again
        $this->assertSame(200, $this->check('1', 'api', $t)->getStatusCode());
    }

    public function testAdminResetSpecificEndpoint(): void
    {
        $t = '2026-06-01 00:00:00';
        $this->check('1', 'api', $t);
        $this->check('1', 'other', $t);
        $this->req('DELETE', '/rate/reset/1', ['X-Admin-Key' => self::ADMIN_KEY], null, ['endpoint' => 'api']);
        $apiCount = $this->json($this->req('GET', '/rate/status', ['X-User-Id' => '1', 'X-Now' => $t], null, ['endpoint' => 'api']))['count'];
        $otherCount = $this->json($this->req('GET', '/rate/status', ['X-User-Id' => '1', 'X-Now' => $t], null, ['endpoint' => 'other']))['count'];
        $this->assertSame(0, $apiCount);
        $this->assertSame(1, $otherCount); // untouched
    }

    // ── ATK-01〜12 ────────────────────────────────────────────────────────────────

    public function testAtk01MissingUserId(): void
    {
        $this->assertSame(400, $this->req('POST', '/rate/check', [], ['endpoint' => 'api'])->getStatusCode());
    }

    public function testAtk02EmptyEndpoint(): void
    {
        $this->assertSame(422, $this->req('POST', '/rate/check', ['X-User-Id' => '1'], ['endpoint' => '  '])->getStatusCode());
    }

    public function testAtk03OverlongEndpoint(): void
    {
        $this->assertSame(422, $this->req('POST', '/rate/check', ['X-User-Id' => '1'], ['endpoint' => str_repeat('a', 129)])->getStatusCode());
    }

    public function testAtk04SqlInjectionEndpointIsInert(): void
    {
        $payload = "'; DROP TABLE rate_events; --";
        $this->assertSame(200, $this->req('POST', '/rate/check', ['X-User-Id' => '1', 'X-Now' => '2026-06-01 00:00:00'], ['endpoint' => $payload])->getStatusCode());
        // table survived: a normal check still works
        $this->assertSame(200, $this->check('1', 'api', '2026-06-01 00:00:00')->getStatusCode());
    }

    public function testAtk05NonAdminReset(): void
    {
        $this->assertSame(403, $this->req('DELETE', '/rate/reset/1', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testAtk06WrongAdminKey(): void
    {
        $this->assertSame(403, $this->req('DELETE', '/rate/reset/1', ['X-Admin-Key' => 'nope'])->getStatusCode());
    }

    public function testAtk06bEmptyConfiguredKeyFailsClosed(): void
    {
        $this->assertSame(403, $this->req('DELETE', '/rate/reset/1', ['X-Admin-Key' => ''], null, [], '')->getStatusCode());
    }

    public function testAtk07to09InvalidPathUserId(): void
    {
        foreach (['-1', '0', 'abc', '99999999999999999999'] as $bad) {
            $this->assertSame(404, $this->req('DELETE', '/rate/reset/' . $bad, ['X-Admin-Key' => self::ADMIN_KEY])->getStatusCode(), "userId '{$bad}' must be 404");
        }
    }

    public function testAtk10StatusWithoutEndpoint(): void
    {
        $this->assertSame(422, $this->req('GET', '/rate/status', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testAtk11CheckWithoutBody(): void
    {
        $this->assertSame(400, $this->req('POST', '/rate/check', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testAtk12BodyMissingEndpoint(): void
    {
        $this->assertSame(422, $this->req('POST', '/rate/check', ['X-User-Id' => '1'], ['other' => 'x'])->getStatusCode());
    }
}
