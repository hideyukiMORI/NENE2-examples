<?php

declare(strict_types=1);

namespace WaitlistLog\Tests\Waitlist;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use WaitlistLog\AppFactory;

class WaitlistTest extends TestCase
{
    private const ADMIN_KEY = 'admin-secret';

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
    private function req(string $method, string $path, array $headers = [], mixed $body = null, string $adminKey = self::ADMIN_KEY): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile, $adminKey);
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

    private function join(string $user, ?string $note = null): ResponseInterface
    {
        $body = $note !== null ? ['note' => $note] : [];
        return $this->req('POST', '/waitlist', ['X-User-Id' => $user], $body);
    }

    private function entryId(string $user): int
    {
        $data = $this->json($this->req('GET', '/waitlist', ['X-Admin-Key' => self::ADMIN_KEY]));
        foreach ($data['entries'] as $e) {
            if ((int) $e['user_id'] === (int) $user) {
                return (int) $e['id'];
            }
        }
        return 0;
    }

    // ── join ────────────────────────────────────────────────────────────────

    public function testJoinRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/waitlist', [], [])->getStatusCode());
    }

    public function testJoinStartsWaiting(): void
    {
        $res = $this->join('1');
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('waiting', $this->json($res)['status']);
    }

    public function testDuplicateJoinIs409(): void
    {
        $this->join('1');
        $this->assertSame(409, $this->join('1')->getStatusCode());
    }

    public function testNoteTruncatedNotRejected(): void
    {
        $res = $this->join('1', str_repeat('x', 600));
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(500, mb_strlen((string) $this->json($res)['note']));
    }

    // ── position ────────────────────────────────────────────────────────────────

    public function testPositionTracking(): void
    {
        $this->join('1');
        $this->join('2');
        $this->join('3');
        $this->assertSame(1, $this->json($this->req('GET', '/waitlist/me', ['X-User-Id' => '1']))['position']);
        $this->assertSame(3, $this->json($this->req('GET', '/waitlist/me', ['X-User-Id' => '3']))['position']);
    }

    public function testPositionSkipsTerminalEntries(): void
    {
        $this->join('1');
        $this->join('2');
        $this->join('3');
        // approve user 1 → user 2 becomes position 1 among waiting
        $this->req('POST', '/waitlist/' . $this->entryId('1') . '/approve', ['X-Admin-Key' => self::ADMIN_KEY]);
        $this->assertSame(1, $this->json($this->req('GET', '/waitlist/me', ['X-User-Id' => '2']))['position']);
    }

    public function testApprovedHasNoPosition(): void
    {
        $this->join('1');
        $this->req('POST', '/waitlist/' . $this->entryId('1') . '/approve', ['X-Admin-Key' => self::ADMIN_KEY]);
        $this->assertNull($this->json($this->req('GET', '/waitlist/me', ['X-User-Id' => '1']))['position']);
    }

    // ── me / route order ─────────────────────────────────────────────────────────

    public function testMeNotOnList404(): void
    {
        $this->assertSame(404, $this->req('GET', '/waitlist/me', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testMeRouteNotCapturedById(): void
    {
        $this->join('5');
        // '/waitlist/me' must reach the me handler (200), not approve/{id} with id='me'
        $this->assertSame(200, $this->req('GET', '/waitlist/me', ['X-User-Id' => '5'])->getStatusCode());
    }

    // ── state machine ─────────────────────────────────────────────────────────────

    public function testApprove(): void
    {
        $this->join('1');
        $res = $this->req('POST', '/waitlist/' . $this->entryId('1') . '/approve', ['X-Admin-Key' => self::ADMIN_KEY]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('approved', $this->json($res)['status']);
    }

    public function testDecline(): void
    {
        $this->join('1');
        $res = $this->req('POST', '/waitlist/' . $this->entryId('1') . '/decline', ['X-Admin-Key' => self::ADMIN_KEY]);
        $this->assertSame('declined', $this->json($res)['status']);
    }

    public function testCannotTransitionFromTerminal(): void
    {
        $this->join('1');
        $id = $this->entryId('1');
        $this->req('POST', '/waitlist/' . $id . '/approve', ['X-Admin-Key' => self::ADMIN_KEY]);
        // approved → decline is blocked (409)
        $this->assertSame(409, $this->req('POST', '/waitlist/' . $id . '/decline', ['X-Admin-Key' => self::ADMIN_KEY])->getStatusCode());
    }

    public function testTransitionUnknownIs404(): void
    {
        $this->assertSame(404, $this->req('POST', '/waitlist/999/approve', ['X-Admin-Key' => self::ADMIN_KEY])->getStatusCode());
    }

    // ── leave ──────────────────────────────────────────────────────────────────────

    public function testLeaveWhileWaiting(): void
    {
        $this->join('1');
        $this->assertSame(200, $this->req('DELETE', '/waitlist/me', ['X-User-Id' => '1'])->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/waitlist/me', ['X-User-Id' => '1'])->getStatusCode());
        // can rejoin after leaving
        $this->assertSame(201, $this->join('1')->getStatusCode());
    }

    public function testCannotLeaveAfterApproved(): void
    {
        $this->join('1');
        $this->req('POST', '/waitlist/' . $this->entryId('1') . '/approve', ['X-Admin-Key' => self::ADMIN_KEY]);
        $this->assertSame(409, $this->req('DELETE', '/waitlist/me', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testLeaveNotOnList404(): void
    {
        $this->assertSame(404, $this->req('DELETE', '/waitlist/me', ['X-User-Id' => '1'])->getStatusCode());
    }

    // ── admin auth / IDOR ─────────────────────────────────────────────────────────────

    public function testAdminListRequiresKey(): void
    {
        $this->join('1');
        $this->assertSame(403, $this->req('GET', '/waitlist', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testEmptyAdminKeyFailsClosed(): void
    {
        $this->join('1');
        $this->assertSame(403, $this->req('GET', '/waitlist', ['X-Admin-Key' => ''], null, '')->getStatusCode());
    }

    public function testApproveRequiresAdmin(): void
    {
        $this->join('1');
        $this->assertSame(403, $this->req('POST', '/waitlist/1/approve', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testMeOmitsUserIdAdminListIncludesIt(): void
    {
        $this->join('7');
        $this->assertArrayNotHasKey('user_id', $this->json($this->req('GET', '/waitlist/me', ['X-User-Id' => '7'])));
        $admin = $this->json($this->req('GET', '/waitlist', ['X-Admin-Key' => self::ADMIN_KEY]));
        $this->assertSame(7, $admin['entries'][0]['user_id']);
    }
}
