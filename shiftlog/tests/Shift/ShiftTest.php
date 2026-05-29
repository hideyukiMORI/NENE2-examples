<?php

declare(strict_types=1);

namespace ShiftLog\Tests\Shift;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ShiftLog\AppFactory;

class ShiftTest extends TestCase
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

    private function employee(string $name = 'Alice'): int
    {
        $res = $this->req('POST', '/employees', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => $name, 'role' => 'Barista', 'hourly_rate' => 1850]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    private function shift(int $emp, string $from, string $to): ResponseInterface
    {
        return $this->req('POST', '/shifts', ['X-Admin-Key' => self::ADMIN_KEY], ['employee_id' => $emp, 'starts_at' => $from, 'ends_at' => $to]);
    }

    // ── admin (hardens V-01/02) ─────────────────────────────────────────────

    public function testCreateEmployeeRequiresAdmin(): void
    {
        $this->assertSame(403, $this->req('POST', '/employees', [], ['name' => 'x', 'role' => 'y', 'hourly_rate' => 1])->getStatusCode());
    }

    public function testCreateShiftRequiresAdmin(): void
    {
        $e = $this->employee();
        $this->assertSame(403, $this->req('POST', '/shifts', [], ['employee_id' => $e, 'starts_at' => '2026-05-27T09:00:00Z', 'ends_at' => '2026-05-27T17:00:00Z'])->getStatusCode());
    }

    public function testDeleteShiftRequiresAdmin(): void
    {
        $e = $this->employee();
        $id = (int) $this->json($this->shift($e, '2026-05-27T09:00:00Z', '2026-05-27T17:00:00Z'))['id'];
        $this->assertSame(403, $this->req('DELETE', '/shifts/' . $id)->getStatusCode());
    }

    // ── employee validation (hardens V-06/V-09) ──────────────────────────────

    public function testHourlyRateMustBePositiveInt(): void
    {
        $this->assertSame(422, $this->req('POST', '/employees', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => 'x', 'role' => 'y', 'hourly_rate' => 0])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/employees', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => 'x', 'role' => 'y', 'hourly_rate' => -5])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/employees', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => 'x', 'role' => 'y', 'hourly_rate' => 'free'])->getStatusCode());
    }

    public function testOverlongNameRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/employees', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => str_repeat('A', 101), 'role' => 'y', 'hourly_rate' => 1])->getStatusCode());
    }

    // ── shift scheduling + overlap (V-04) ────────────────────────────────────

    public function testScheduleShift(): void
    {
        $e = $this->employee();
        $res = $this->shift($e, '2026-05-27T09:00:00Z', '2026-05-27T17:00:00Z');
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testOverlappingShiftRejected(): void
    {
        $e = $this->employee();
        $this->assertSame(201, $this->shift($e, '2026-05-27T09:00:00Z', '2026-05-27T17:00:00Z')->getStatusCode());
        // overlaps 09-17
        $this->assertSame(409, $this->shift($e, '2026-05-27T12:00:00Z', '2026-05-27T20:00:00Z')->getStatusCode());
    }

    public function testAdjacentShiftAllowed(): void
    {
        $e = $this->employee();
        $this->shift($e, '2026-05-27T09:00:00Z', '2026-05-27T17:00:00Z');
        // starts exactly when the previous ends → no overlap
        $this->assertSame(201, $this->shift($e, '2026-05-27T17:00:00Z', '2026-05-27T21:00:00Z')->getStatusCode());
    }

    public function testEndsBeforeStartsRejected(): void
    {
        $e = $this->employee();
        $this->assertSame(422, $this->shift($e, '2026-05-27T17:00:00Z', '2026-05-27T09:00:00Z')->getStatusCode());
    }

    // ── datetime validation (hardens V-07) ───────────────────────────────────

    public function testInvalidDatetimeRejected(): void
    {
        $e = $this->employee();
        $this->assertSame(422, $this->shift($e, '2026-02-30T09:00:00Z', '2026-02-30T17:00:00Z')->getStatusCode());
        $this->assertSame(422, $this->shift($e, 'not-a-date', '2026-05-27T17:00:00Z')->getStatusCode());
    }

    public function testScheduleUnknownEmployee(): void
    {
        $this->assertSame(404, $this->shift(999, '2026-05-27T09:00:00Z', '2026-05-27T17:00:00Z')->getStatusCode());
    }

    // ── window + summary (hardens V-08) ──────────────────────────────────────

    public function testScheduleWindowAndSummary(): void
    {
        $e = $this->employee();
        $this->shift($e, '2026-05-27T09:00:00Z', '2026-05-27T17:00:00Z'); // 8h
        $this->shift($e, '2026-05-28T09:00:00Z', '2026-05-28T13:00:00Z'); // 4h

        $schedule = $this->json($this->req('GET', '/schedule', [], null, ['from' => '2026-05-27T00:00:00Z', 'to' => '2026-05-29T00:00:00Z']));
        $this->assertSame(2, $schedule['count']);

        $summary = $this->json($this->req('GET', '/summary/hours', [], null, ['from' => '2026-05-27T00:00:00Z', 'to' => '2026-05-29T00:00:00Z']));
        $this->assertSame(12.0, $summary['summary'][0]['hours']);
    }

    public function testOverlongDateRangeRejected(): void
    {
        $this->assertSame(422, $this->req('GET', '/schedule', [], null, ['from' => '2020-01-01T00:00:00Z', 'to' => '2026-01-01T00:00:00Z'])->getStatusCode());
    }

    public function testNonNumericPathIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/shifts/abc')->getStatusCode());
    }
}
