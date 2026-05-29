<?php

declare(strict_types=1);

namespace ReservationLog\Tests\Reservation;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReservationLog\AppFactory;

class ReservationTest extends TestCase
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

    private function iso(string $expr): string
    {
        return (new \DateTimeImmutable($expr, new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function makeResource(): int
    {
        $res = $this->req('POST', '/resources', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => 'Room A']);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    private function bookSlot(int $resource, string $start, string $end, string $user = '1'): ResponseInterface
    {
        return $this->req('POST', '/resources/' . $resource . '/book', ['X-User-Id' => $user], [
            'starts_at' => $this->iso($start),
            'ends_at' => $this->iso($end),
        ]);
    }

    // ── resource admin ────────────────────────────────────────────────────────

    public function testCreateResourceRequiresAdmin(): void
    {
        $this->assertSame(403, $this->req('POST', '/resources', [], ['name' => 'x'])->getStatusCode());
    }

    public function testEmptyAdminKeyFailsClosed(): void // VULN-D
    {
        $res = $this->req('POST', '/resources', ['X-Admin-Key' => ''], ['name' => 'x'], '');
        $this->assertSame(403, $res->getStatusCode());
    }

    // ── booking + overlap ───────────────────────────────────────────────────────

    public function testBookSlot(): void
    {
        $r = $this->makeResource();
        $res = $this->bookSlot($r, '2026-06-01 09:00', '2026-06-01 10:00');
        $this->assertSame(201, $res->getStatusCode());
        $this->assertArrayNotHasKey('user_id', $this->json($res)); // public view
    }

    public function testOverlapRejected(): void // ATK-09
    {
        $r = $this->makeResource();
        $this->bookSlot($r, '2026-06-01 09:00', '2026-06-01 10:00');
        // overlapping (contained) → 409
        $this->assertSame(409, $this->bookSlot($r, '2026-06-01 09:30', '2026-06-01 09:45', '2')->getStatusCode());
        // identical → 409
        $this->assertSame(409, $this->bookSlot($r, '2026-06-01 09:00', '2026-06-01 10:00', '2')->getStatusCode());
    }

    public function testAdjacentSlotAllowed(): void
    {
        $r = $this->makeResource();
        $this->bookSlot($r, '2026-06-01 09:00', '2026-06-01 10:00');
        // adjacent (end == start) does NOT overlap (half-open intervals)
        $this->assertSame(201, $this->bookSlot($r, '2026-06-01 10:00', '2026-06-01 11:00', '2')->getStatusCode());
    }

    public function testDifferentResourcesDoNotConflict(): void
    {
        $a = $this->makeResource();
        $b = $this->makeResource();
        $this->bookSlot($a, '2026-06-01 09:00', '2026-06-01 10:00');
        $this->assertSame(201, $this->bookSlot($b, '2026-06-01 09:00', '2026-06-01 10:00')->getStatusCode());
    }

    public function testEndBeforeStartRejected(): void
    {
        $r = $this->makeResource();
        $this->assertSame(422, $this->bookSlot($r, '2026-06-01 10:00', '2026-06-01 09:00')->getStatusCode());
    }

    public function testBookRequiresUser(): void
    {
        $r = $this->makeResource();
        $res = $this->req('POST', '/resources/' . $r . '/book', [], [
            'starts_at' => $this->iso('2026-06-01 09:00'),
            'ends_at' => $this->iso('2026-06-01 10:00'),
        ]);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testBookUnknownResourceIs404(): void
    {
        $this->assertSame(404, $this->bookSlot(999, '2026-06-01 09:00', '2026-06-01 10:00')->getStatusCode());
    }

    public function testNoteLengthGuard(): void
    {
        $r = $this->makeResource();
        $res = $this->req('POST', '/resources/' . $r . '/book', ['X-User-Id' => '1'], [
            'starts_at' => $this->iso('2026-06-01 09:00'),
            'ends_at' => $this->iso('2026-06-01 10:00'),
            'note' => str_repeat('x', 501),
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ── IDOR views ──────────────────────────────────────────────────────────────

    public function testMyBookingsExcludesUserId(): void
    {
        $r = $this->makeResource();
        $this->bookSlot($r, '2026-06-01 09:00', '2026-06-01 10:00', '1');
        $data = $this->json($this->req('GET', '/bookings', ['X-User-Id' => '1']));
        $this->assertSame(1, $data['total']);
        $this->assertArrayNotHasKey('user_id', $data['data'][0]);
    }

    public function testMyBookingsAreUserScoped(): void
    {
        $r = $this->makeResource();
        $this->bookSlot($r, '2026-06-01 09:00', '2026-06-01 10:00', '1');
        $this->assertSame(0, $this->json($this->req('GET', '/bookings', ['X-User-Id' => '2']))['total']);
    }

    public function testAdminResourceBookingsIncludeUserId(): void
    {
        $r = $this->makeResource();
        $this->bookSlot($r, '2026-06-01 09:00', '2026-06-01 10:00', '7');
        $data = $this->json($this->req('GET', '/resources/' . $r . '/bookings', ['X-Admin-Key' => self::ADMIN_KEY]));
        $this->assertSame(1, $data['total']);
        $this->assertSame(7, $data['data'][0]['user_id']); // admin sees owner
    }

    public function testAdminBookingsRequireAdmin(): void
    {
        $r = $this->makeResource();
        $this->assertSame(403, $this->req('GET', '/resources/' . $r . '/bookings', ['X-User-Id' => '1'])->getStatusCode());
    }

    // ── cancel ownership ──────────────────────────────────────────────────────────

    public function testCancelOwn(): void
    {
        $r = $this->makeResource();
        $id = (int) $this->json($this->bookSlot($r, '2026-06-01 09:00', '2026-06-01 10:00', '1'))['id'];
        $this->assertSame(200, $this->req('DELETE', '/bookings/' . $id, ['X-User-Id' => '1'])->getStatusCode());
        // slot freed → rebooking succeeds
        $this->assertSame(201, $this->bookSlot($r, '2026-06-01 09:00', '2026-06-01 10:00', '2')->getStatusCode());
    }

    public function testCancelOthersIs403(): void
    {
        $r = $this->makeResource();
        $id = (int) $this->json($this->bookSlot($r, '2026-06-01 09:00', '2026-06-01 10:00', '1'))['id'];
        // wrong owner → 403 (not 404 — id is visible to its owner's lister)
        $this->assertSame(403, $this->req('DELETE', '/bookings/' . $id, ['X-User-Id' => '2'])->getStatusCode());
    }

    public function testCancelUnknownIs404(): void
    {
        $this->assertSame(404, $this->req('DELETE', '/bookings/999', ['X-User-Id' => '1'])->getStatusCode());
    }
}
