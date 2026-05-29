<?php

declare(strict_types=1);

namespace VerifyLog\Tests\Verification;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use VerifyLog\AppFactory;

class VerifyTest extends TestCase
{
    private const CODE = '042042'; // deterministic test code

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
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function req(string $method, string $path, mixed $body = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile, static fn (): string => self::CODE);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
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

    private function request(string $contact = 'a@b.test'): int
    {
        $res = $this->req('POST', '/verifications', ['contact' => $contact]);
        assert($res->getStatusCode() === 202);
        return (int) $this->json($res)['id'];
    }

    // ── request always 202 ────────────────────────────────────────────────────

    public function testRequestAlways202(): void
    {
        $res = $this->req('POST', '/verifications', ['contact' => 'x@y.test']);
        $this->assertSame(202, $res->getStatusCode());
        // code never leaks into the response
        $this->assertStringNotContainsString(self::CODE, (string) $res->getBody());
    }

    public function testRequestRequiresContact(): void
    {
        $this->assertSame(422, $this->req('POST', '/verifications', [])->getStatusCode());
    }

    // ── correct code ──────────────────────────────────────────────────────────

    public function testCorrectCodeVerifies(): void
    {
        $id = $this->request();
        $res = $this->req('POST', "/verifications/{$id}/check", ['code' => self::CODE]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($this->json($res)['verified']);
    }

    // ── brute force / lockout (ATK-05) ─────────────────────────────────────────

    public function testThreeWrongAttemptsLock(): void
    {
        $id = $this->request();
        $this->assertSame(422, $this->req('POST', "/verifications/{$id}/check", ['code' => '000000'])->getStatusCode());
        $this->assertSame(422, $this->req('POST', "/verifications/{$id}/check", ['code' => '111111'])->getStatusCode());
        // 3rd wrong → 429 locked
        $this->assertSame(429, $this->req('POST', "/verifications/{$id}/check", ['code' => '222222'])->getStatusCode());
        // even the correct code is now locked out
        $this->assertSame(429, $this->req('POST', "/verifications/{$id}/check", ['code' => self::CODE])->getStatusCode());
    }

    public function testAttemptsLeftReported(): void
    {
        $id = $this->request();
        $res = $this->req('POST', "/verifications/{$id}/check", ['code' => '999999']);
        $this->assertSame(2, $this->json($res)['attempts_left']);
    }

    // ── replay (ATK-11) ─────────────────────────────────────────────────────────

    public function testReplayAfterSuccessIs410(): void
    {
        $id = $this->request();
        $this->req('POST', "/verifications/{$id}/check", ['code' => self::CODE]);
        $this->assertSame(410, $this->req('POST', "/verifications/{$id}/check", ['code' => self::CODE])->getStatusCode());
    }

    // ── expiry ────────────────────────────────────────────────────────────────

    public function testExpiredIs410(): void
    {
        $id = $this->request();
        // force-expire the row
        $this->pdo->exec("UPDATE verifications SET expires_at = '2000-01-01T00:00:00Z' WHERE id = {$id}");
        $this->assertSame(410, $this->req('POST', "/verifications/{$id}/check", ['code' => self::CODE])->getStatusCode());
    }

    // ── type confusion / id guards (ATK-01/07/08) ────────────────────────────────

    public function testNonStringCodeRejected(): void
    {
        $id = $this->request();
        $this->assertSame(422, $this->req('POST', "/verifications/{$id}/check", ['code' => 42042])->getStatusCode());
    }

    public function testNonNumericIdIs404(): void
    {
        $this->assertSame(404, $this->req('POST', '/verifications/abc/check', ['code' => self::CODE])->getStatusCode());
    }

    public function testUnknownIdIs404(): void
    {
        $this->assertSame(404, $this->req('POST', '/verifications/9999/check', ['code' => self::CODE])->getStatusCode());
    }

    // ── status (no code revealed) ────────────────────────────────────────────────

    public function testStatusRevealsNoCode(): void
    {
        $id = $this->request();
        $res = $this->req('GET', "/verifications/{$id}");
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertFalse($data['verified']);
        $this->assertSame(3, $data['attempts_left']);
        $this->assertStringNotContainsString(self::CODE, (string) $res->getBody());
    }
}
