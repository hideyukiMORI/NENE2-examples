<?php

declare(strict_types=1);

namespace VaultLog\Tests\Vault;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use VaultLog\AppFactory;

class VaultTest extends TestCase
{
    private const ADMIN_KEY = 'admin-secret';
    private const HMAC_SECRET = 'hmac-secret';

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

    /** @param array<string, string> $headers */
    private function req(string $method, string $path, array $headers = [], mixed $body = null, string $hmac = self::HMAC_SECRET): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile, self::ADMIN_KEY, $hmac);
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

    // ─── store / upsert ────────────────────────────────────────────────────

    public function testStoreRequiresUserId(): void
    {
        $this->assertSame(401, $this->req('POST', '/vault', [], ['key' => 'api_key', 'value' => 'x'])->getStatusCode());
    }

    public function testStoreReturns201ThenUpsert200(): void
    {
        $first = $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => 'api_key', 'value' => 'v1']);
        $this->assertSame(201, $first->getStatusCode());
        $second = $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => 'api_key', 'value' => 'v2']);
        $this->assertSame(200, $second->getStatusCode()); // VULN-F: upsert, no duplicate

        $get = $this->req('GET', '/vault/api_key', ['X-User-Id' => '100']);
        $this->assertSame('v2', $this->json($get)['value']);
    }

    // ─── VULN-A: key validation / injection ──────────────────────────────────

    public function testVulnAInjectionInKeyRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => "' OR '1'='1", 'value' => 'x'])->getStatusCode());
    }

    public function testVulnGPathTraversalKeyRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => '../etc/passwd', 'value' => 'x'])->getStatusCode());
    }

    public function testVulnKOverlongKeyRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => str_repeat('a', 65), 'value' => 'x'])->getStatusCode());
    }

    // ─── VULN-B/C: IDOR ──────────────────────────────────────────────────────

    public function testVulnBCrossUserReadIs404(): void
    {
        $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => 'private_key', 'value' => 'secret']);
        $res = $this->req('GET', '/vault/private_key', ['X-User-Id' => '200']);
        $this->assertSame(404, $res->getStatusCode()); // identical to "not found" — no enumeration
    }

    public function testVulnBCrossUserDeleteIs404(): void
    {
        $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => 'private_key', 'value' => 'secret']);
        $this->assertSame(404, $this->req('DELETE', '/vault/private_key', ['X-User-Id' => '200'])->getStatusCode());
        // still readable by the owner — was not deleted
        $this->assertSame(200, $this->req('GET', '/vault/private_key', ['X-User-Id' => '100'])->getStatusCode());
    }

    public function testVulnCListReturnsOnlyOwnKeys(): void
    {
        $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => 'k1', 'value' => 'a']);
        $this->req('POST', '/vault', ['X-User-Id' => '200'], ['key' => 'k2', 'value' => 'b']);
        $this->assertSame(['k1'], $this->json($this->req('GET', '/vault', ['X-User-Id' => '100']))['keys']);
    }

    // ─── VULN-H/I: user id ───────────────────────────────────────────────────

    public function testVulnHNegativeAndZeroUserIdRejected(): void
    {
        $this->assertSame(401, $this->req('GET', '/vault', ['X-User-Id' => '-5'])->getStatusCode());
        $this->assertSame(401, $this->req('GET', '/vault', ['X-User-Id' => '0'])->getStatusCode());
    }

    public function testVulnIOverlongUserIdRejected(): void
    {
        $this->assertSame(401, $this->req('GET', '/vault', ['X-User-Id' => str_repeat('9', 25)])->getStatusCode());
    }

    // ─── admin: metadata only, no values ─────────────────────────────────────

    public function testVulnDAdminRequiresKey(): void
    {
        $this->assertSame(403, $this->req('GET', '/admin/vault')->getStatusCode());
        $this->assertSame(403, $this->req('GET', '/admin/vault', ['X-Admin-Key' => 'wrong'])->getStatusCode());
    }

    public function testAdminNeverSeesValues(): void
    {
        $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => 'k1', 'value' => 'topsecret']);
        $data = $this->json($this->req('GET', '/admin/vault', ['X-Admin-Key' => self::ADMIN_KEY]));
        $this->assertSame(1, $data['count']);
        $this->assertArrayNotHasKey('value', $data['entries'][0]);
        $this->assertSame(100, $data['entries'][0]['user_id']);
    }

    // ─── HMAC integrity ──────────────────────────────────────────────────────

    public function testTamperedValueFailsIntegrityCheck(): void
    {
        $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => 'k1', 'value' => 'original']);
        // Out-of-band tamper: change value directly in the DB, leaving the HMAC stale.
        $this->pdo->exec("UPDATE vault_entries SET value = 'tampered' WHERE user_id = 100 AND key_name = 'k1'");
        $res = $this->req('GET', '/vault/k1', ['X-User-Id' => '100']);
        $this->assertSame(500, $res->getStatusCode());
    }

    // ─── VULN-L: empty HMAC secret must not crash ────────────────────────────

    public function testVulnLEmptyHmacSecretDoesNotCrash(): void
    {
        $store = $this->req('POST', '/vault', ['X-User-Id' => '100'], ['key' => 'k1', 'value' => 'v'], '');
        $this->assertSame(201, $store->getStatusCode());
        $get = $this->req('GET', '/vault/k1', ['X-User-Id' => '100'], null, '');
        $this->assertSame(200, $get->getStatusCode());
        $this->assertSame('v', $this->json($get)['value']);
    }
}
