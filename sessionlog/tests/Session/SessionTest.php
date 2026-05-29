<?php

declare(strict_types=1);

namespace SessionLog\Tests\Session;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SessionLog\AppFactory;

class SessionTest extends TestCase
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

    /** @param array<string, mixed> $body */
    private function makeSession(string $user, array $body = []): string
    {
        $res = $this->req('POST', '/sessions', ['X-User-Id' => $user], $body ?: ['device_name' => 'Laptop']);
        assert($res->getStatusCode() === 201);
        return (string) $this->json($res)['token'];
    }

    // ── create / token ──────────────────────────────────────────────────────

    public function testCreateRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/sessions', [], ['device_name' => 'x'])->getStatusCode());
    }

    public function testTokenIs64Hex(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->makeSession('1'));
    }

    public function testNonStringDeviceNameRejected(): void // VULN-B
    {
        $this->assertSame(422, $this->req('POST', '/sessions', ['X-User-Id' => '1'], ['device_name' => ['arr']])->getStatusCode());
    }

    public function testMassAssignmentIgnored(): void // VULN-L
    {
        $token = $this->makeSession('1', ['device_name' => 'Phone', 'token' => 'custom', 'user_id' => 999]);
        $this->assertNotSame('custom', $token); // server generated, body ignored
        // session belongs to user 1 (header), not 999 → user 1 sees it
        $this->assertSame(1, $this->json($this->req('GET', '/sessions', ['X-User-Id' => '1']))['count']);
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListActiveOwnerScoped(): void
    {
        $this->makeSession('1');
        $this->makeSession('1');
        $this->makeSession('2');
        $this->assertSame(2, $this->json($this->req('GET', '/sessions', ['X-User-Id' => '1']))['count']);
    }

    // ── revoke one (IDOR + timing oracle) ────────────────────────────────────

    public function testRevokeOwnSession(): void
    {
        $token = $this->makeSession('1');
        $this->assertSame(204, $this->req('DELETE', '/sessions/' . $token, ['X-User-Id' => '1'])->getStatusCode());
        // gone from active list
        $this->assertSame(0, $this->json($this->req('GET', '/sessions', ['X-User-Id' => '1']))['count']);
    }

    public function testCrossUserRevokeIs404(): void // VULN-E/H
    {
        $token = $this->makeSession('1');
        $this->assertSame(404, $this->req('DELETE', '/sessions/' . $token, ['X-User-Id' => '99'])->getStatusCode());
        // survived
        $this->assertSame(1, $this->json($this->req('GET', '/sessions', ['X-User-Id' => '1']))['count']);
    }

    public function testDoubleRevokeIs404(): void // VULN-H (already-revoked = same 404)
    {
        $token = $this->makeSession('1');
        $this->req('DELETE', '/sessions/' . $token, ['X-User-Id' => '1']);
        $this->assertSame(404, $this->req('DELETE', '/sessions/' . $token, ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testMalformedTokenIs404(): void // VULN-C/I
    {
        foreach (['short', str_repeat('Z', 64), '../../etc', "'; DROP TABLE sessions--"] as $bad) {
            $this->assertSame(404, $this->req('DELETE', '/sessions/' . rawurlencode($bad), ['X-User-Id' => '1'])->getStatusCode());
        }
    }

    // ── revoke all except current ───────────────────────────────────────────────

    public function testRevokeAllExceptCurrent(): void
    {
        $current = $this->makeSession('1', ['device_name' => 'Current']);
        $this->makeSession('1', ['device_name' => 'Other1']);
        $this->makeSession('1', ['device_name' => 'Other2']);

        $res = $this->req('DELETE', '/sessions', ['X-User-Id' => '1', 'X-Current-Session' => $current]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(2, $this->json($res)['revoked']);
        // only the current session remains active
        $remaining = $this->json($this->req('GET', '/sessions', ['X-User-Id' => '1']));
        $this->assertSame(1, $remaining['count']);
        $this->assertSame($current, $remaining['sessions'][0]['token']);
    }

    public function testRevokeAllRequiresValidCurrentToken(): void
    {
        $this->makeSession('1');
        $this->assertSame(422, $this->req('DELETE', '/sessions', ['X-User-Id' => '1', 'X-Current-Session' => 'bogus'])->getStatusCode());
    }
}
