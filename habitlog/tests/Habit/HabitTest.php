<?php

declare(strict_types=1);

namespace HabitLog\Tests\Habit;

use HabitLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class HabitTest extends TestCase
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

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    private function req(string $method, string $path, array $headers = [], mixed $body = null, array $query = []): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
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

    private function habit(string $user, string $name = 'Run', string $freq = 'daily'): int
    {
        $res = $this->req('POST', '/habits', ['X-User-Id' => $user], ['name' => $name, 'frequency' => $freq]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    // ── auth / ownership (hardens ATK-01 / ATK-02) ──────────────────────────

    public function testRequiresUser(): void
    {
        $this->assertSame(401, $this->req('GET', '/habits')->getStatusCode());
        $this->assertSame(401, $this->req('POST', '/habits', [], ['name' => 'x', 'frequency' => 'daily'])->getStatusCode());
    }

    public function testHabitsAreOwnerScoped(): void
    {
        $id = $this->habit('100', 'Secret');
        $this->assertSame(404, $this->req('GET', '/habits/' . $id, ['X-User-Id' => '200'])->getStatusCode());
        $this->assertSame(404, $this->req('DELETE', '/habits/' . $id, ['X-User-Id' => '200'])->getStatusCode());
        $this->assertSame([], $this->json($this->req('GET', '/habits', ['X-User-Id' => '200']))['habits']);
    }

    // ── create validation ────────────────────────────────────────────────────

    public function testInvalidFrequencyRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/habits', ['X-User-Id' => '1'], ['name' => 'x', 'frequency' => 'hourly'])->getStatusCode());
    }

    public function testWhitespaceNameRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/habits', ['X-User-Id' => '1'], ['name' => '   ', 'frequency' => 'daily'])->getStatusCode());
    }

    /** Hardens ATK-08: unbounded name length. */
    public function testOverlongNameRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/habits', ['X-User-Id' => '1'], ['name' => str_repeat('A', 201), 'frequency' => 'daily'])->getStatusCode());
    }

    // ── completions ────────────────────────────────────────────────────────────

    public function testCompleteAndDuplicateConflicts(): void
    {
        $id = $this->habit('1');
        $this->assertSame(201, $this->req('POST', "/habits/{$id}/completions", ['X-User-Id' => '1'], ['completed_on' => '2026-05-20'])->getStatusCode());
        // ATK-06: duplicate on same date → 409
        $this->assertSame(409, $this->req('POST', "/habits/{$id}/completions", ['X-User-Id' => '1'], ['completed_on' => '2026-05-20'])->getStatusCode());
    }

    /** Hardens ATK-04: semantically invalid date rejected. */
    public function testInvalidCalendarDateRejected(): void
    {
        $id = $this->habit('1');
        $this->assertSame(422, $this->req('POST', "/habits/{$id}/completions", ['X-User-Id' => '1'], ['completed_on' => '2026-02-30'])->getStatusCode());
        $this->assertSame(422, $this->req('POST', "/habits/{$id}/completions", ['X-User-Id' => '1'], ['completed_on' => 'not-a-date'])->getStatusCode());
    }

    /** ATK-11: completing a non-existent habit → 404. */
    public function testCompleteUnknownHabitIs404(): void
    {
        $this->assertSame(404, $this->req('POST', '/habits/999/completions', ['X-User-Id' => '1'], ['completed_on' => '2026-05-20'])->getStatusCode());
    }

    // ── streak ───────────────────────────────────────────────────────────────

    public function testStreakCountsConsecutiveDays(): void
    {
        $id = $this->habit('1');
        foreach (['2026-05-18', '2026-05-19', '2026-05-20'] as $d) {
            $this->req('POST', "/habits/{$id}/completions", ['X-User-Id' => '1'], ['completed_on' => $d]);
        }
        $data = $this->json($this->req('GET', "/habits/{$id}/streak", ['X-User-Id' => '1'], null, ['today' => '2026-05-20']));
        $this->assertSame(3, $data['streak']);
    }

    public function testStreakBreaksOnGap(): void
    {
        $id = $this->habit('1');
        // gap: missing 2026-05-19
        $this->req('POST', "/habits/{$id}/completions", ['X-User-Id' => '1'], ['completed_on' => '2026-05-18']);
        $this->req('POST', "/habits/{$id}/completions", ['X-User-Id' => '1'], ['completed_on' => '2026-05-20']);
        $data = $this->json($this->req('GET', "/habits/{$id}/streak", ['X-User-Id' => '1'], null, ['today' => '2026-05-20']));
        $this->assertSame(1, $data['streak']);
    }

    /** Hardens ATK-10: malformed ?today= rejected (no 500). */
    public function testInvalidTodayRejected(): void
    {
        $id = $this->habit('1');
        $this->assertSame(422, $this->req('GET', "/habits/{$id}/streak", ['X-User-Id' => '1'], null, ['today' => 'not-a-date'])->getStatusCode());
    }

    // ── frequency filter ───────────────────────────────────────────────────────

    public function testFrequencyFilter(): void
    {
        $this->habit('1', 'A', 'daily');
        $this->habit('1', 'B', 'weekly');
        $data = $this->json($this->req('GET', '/habits', ['X-User-Id' => '1'], null, ['frequency' => 'weekly']));
        $this->assertSame(1, $data['count']);
        $this->assertSame('B', $data['habits'][0]['name']);
    }

    public function testNonNumericPathIdIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/habits/abc', ['X-User-Id' => '1'])->getStatusCode());
    }
}
