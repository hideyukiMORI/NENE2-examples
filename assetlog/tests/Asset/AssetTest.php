<?php

declare(strict_types=1);

namespace AssetLog\Tests\Asset;

use AssetLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class AssetTest extends TestCase
{
    private const ADMIN_KEY = 'secret-admin-key';

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

        // Seed one available asset (id 1).
        $this->pdo->exec("INSERT INTO assets (id, name, holder_id, created_at, updated_at) VALUES (1, 'Projector', NULL, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
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

    // ─── creation / admin key ─────────────────────────────────────────────

    public function testCreateRequiresAdminKey(): void
    {
        $this->assertSame(403, $this->req('POST', '/assets', [], ['name' => 'Camera'])->getStatusCode());
    }

    public function testCreateWithAdminKey(): void
    {
        $res = $this->req('POST', '/assets', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => 'Camera']);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Camera', $this->json($res)['name']);
        $this->assertTrue($this->json($res)['available']);
    }

    public function testCreateWrongAdminKeyIsRejected(): void
    {
        $this->assertSame(403, $this->req('POST', '/assets', ['X-Admin-Key' => 'wrong'], ['name' => 'X'])->getStatusCode());
    }

    public function testCreateValidationEmptyName(): void
    {
        $this->assertSame(422, $this->req('POST', '/assets', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => '  '])->getStatusCode());
    }

    // ─── IDOR: holder_id projection ────────────────────────────────────────

    public function testPublicListHidesHolderId(): void
    {
        $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '42']);
        $data = $this->json($this->req('GET', '/assets'));
        $this->assertArrayNotHasKey('holder_id', $data['assets'][0]);
        $this->assertFalse($data['assets'][0]['available']);
    }

    public function testAdminListRevealsHolderId(): void
    {
        $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '42']);
        $data = $this->json($this->req('GET', '/assets', ['X-Admin-Key' => self::ADMIN_KEY]));
        $this->assertArrayHasKey('holder_id', $data['assets'][0]);
        $this->assertSame(42, $data['assets'][0]['holder_id']);
    }

    public function testPublicGetHidesHolderId(): void
    {
        $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '42']);
        $this->assertArrayNotHasKey('holder_id', $this->json($this->req('GET', '/assets/1')));
    }

    // ─── checkout / checkin lifecycle ──────────────────────────────────────

    public function testCheckoutRequiresValidUserId(): void
    {
        $this->assertSame(401, $this->req('POST', '/assets/1/checkout')->getStatusCode());
        $this->assertSame(401, $this->req('POST', '/assets/1/checkout', ['X-User-Id' => 'abc'])->getStatusCode());
        $this->assertSame(401, $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '0'])->getStatusCode());
    }

    public function testCheckoutUnknownAssetIs404(): void
    {
        $this->assertSame(404, $this->req('POST', '/assets/999/checkout', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testCheckoutSucceeds(): void
    {
        $res = $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '42']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($this->json($res)['available']);
    }

    public function testDoubleCheckoutConflicts(): void
    {
        $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '42']);
        $res = $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '99']);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testCheckinByWrongHolderIsForbidden(): void
    {
        $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '42']);
        $res = $this->req('POST', '/assets/1/checkin', ['X-User-Id' => '99']);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testCheckinWhenAvailableConflicts(): void
    {
        $res = $this->req('POST', '/assets/1/checkin', ['X-User-Id' => '42']);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testFullLifecycleCheckoutCheckinRecheckout(): void
    {
        $this->assertSame(200, $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '42'])->getStatusCode());
        $this->assertSame(200, $this->req('POST', '/assets/1/checkin', ['X-User-Id' => '42'])->getStatusCode());
        // available again — a different user can now check it out
        $res = $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '99']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($this->json($res)['available']);
    }

    // ─── audit history ─────────────────────────────────────────────────────

    public function testHistoryRecordsEveryStateChange(): void
    {
        $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '42']);
        $this->req('POST', '/assets/1/checkin', ['X-User-Id' => '42']);
        $this->req('POST', '/assets/1/checkout', ['X-User-Id' => '99']);

        $data = $this->json($this->req('GET', '/assets/1/history'));
        $this->assertSame(3, $data['count']);
        $actions = array_map(static fn (array $h): string => $h['action'], $data['history']);
        $this->assertSame(['checkout', 'checkin', 'checkout'], $actions);
        $this->assertSame([42, 42, 99], array_map(static fn (array $h): int => $h['user_id'], $data['history']));
    }

    public function testHistoryUnknownAssetIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/assets/999/history')->getStatusCode());
    }
}
