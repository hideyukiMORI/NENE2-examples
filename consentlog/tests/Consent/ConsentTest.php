<?php

declare(strict_types=1);

namespace ConsentLog\Tests\Consent;

use ConsentLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class ConsentTest extends TestCase
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

    private function grant(string $user, string $purpose): ResponseInterface
    {
        return $this->req('POST', '/consents', ['X-User-Id' => $user], ['purpose' => $purpose]);
    }

    // ── grant / withdraw / idempotency ────────────────────────────────────────

    public function testRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/consents', [], ['purpose' => 'marketing'])->getStatusCode());
    }

    public function testGrantThenList(): void
    {
        $this->assertSame(201, $this->grant('1', 'marketing')->getStatusCode());
        $data = $this->json($this->req('GET', '/consents', ['X-User-Id' => '1']));
        $this->assertSame(1, $data['count']);
        $this->assertTrue($data['consents'][0]['granted']);
    }

    public function testGrantIsIdempotent(): void
    {
        $this->grant('1', 'marketing');
        $this->grant('1', 'marketing'); // repeat — UPSERT, no duplicate row
        $this->assertSame(1, $this->json($this->req('GET', '/consents', ['X-User-Id' => '1']))['count']);
    }

    public function testWithdraw(): void
    {
        $this->grant('1', 'analytics');
        $res = $this->req('DELETE', '/consents/analytics', ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($this->json($res)['granted']);
        // current state reflects withdrawal
        $consents = $this->json($this->req('GET', '/consents', ['X-User-Id' => '1']))['consents'];
        $this->assertFalse($consents[0]['granted']);
    }

    // ── append-only history ───────────────────────────────────────────────────

    public function testHistoryIsAppendOnly(): void
    {
        $this->grant('1', 'marketing');
        $this->req('DELETE', '/consents/marketing', ['X-User-Id' => '1']);
        $this->grant('1', 'marketing');

        $history = $this->json($this->req('GET', '/consents/marketing/history', ['X-User-Id' => '1']))['history'];
        $this->assertCount(3, $history);
        $this->assertSame([true, false, true], array_map(static fn (array $h): bool => $h['granted'], $history));
    }

    // ── IDOR ──────────────────────────────────────────────────────────────────

    public function testConsentsAreUserScoped(): void
    {
        $this->grant('1', 'marketing');
        $this->grant('2', 'analytics');
        $this->assertSame(1, $this->json($this->req('GET', '/consents', ['X-User-Id' => '1']))['count']);
        // user 2's history for user 1's purpose is empty (scoped)
        $this->assertSame(0, $this->json($this->req('GET', '/consents/marketing/history', ['X-User-Id' => '2']))['count']);
    }

    // ── user enumeration resistance ─────────────────────────────────────────────

    public function testUnknownUserReturnsEmpty200(): void
    {
        $res = $this->req('GET', '/consents', ['X-User-Id' => '99999']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, $this->json($res)['count']);
    }

    // ── purpose validation (VULN-D/G/I) ────────────────────────────────────────

    public function testInvalidPurposeRejected(): void
    {
        foreach (['has-dash', 'has space', "'; DROP--", '<script>', str_repeat('a', 51)] as $bad) {
            $this->assertSame(422, $this->req('POST', '/consents', ['X-User-Id' => '1'], ['purpose' => $bad])->getStatusCode(), "purpose '{$bad}' must be 422");
        }
    }

    public function testNonStringPurposeRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/consents', ['X-User-Id' => '1'], ['purpose' => ['arr']])->getStatusCode());
    }

    // ── X-User-Id validation (VULN-A/J) ─────────────────────────────────────────

    public function testInvalidUserIdRejected(): void
    {
        foreach (['abc', '0', '-1', str_repeat('9', 20)] as $bad) {
            $this->assertSame(401, $this->req('GET', '/consents', ['X-User-Id' => $bad])->getStatusCode());
        }
    }
}
