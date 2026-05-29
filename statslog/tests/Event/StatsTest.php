<?php

declare(strict_types=1);

namespace StatsLog\Tests\Event;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use StatsLog\AppFactory;

class StatsTest extends TestCase
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
    private function req(string $method, string $path, array $headers = [], mixed $body = null, ?string $query = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        if ($query !== null) {
            parse_str($query, $q);
            $request = $request->withQueryParams($q);
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

    /** @param array<string, mixed> $body */
    private function record(array $body): ResponseInterface
    {
        return $this->req('POST', '/events', [], $body);
    }

    // ── record / shape ──────────────────────────────────────────────────────

    public function testRecordEvent(): void
    {
        $res = $this->record([
            'event_type' => 'page_view',
            'user_id' => 'usr_abc',
            'properties' => ['path' => '/pricing', 'referrer' => 'google'],
            'occurred_at' => '2026-05-27T09:00:00Z',
        ]);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('page_view', $data['event_type']);
        $this->assertSame('/pricing', $data['properties']['path']); // decoded back to object
    }

    public function testEventTypeRequired(): void
    {
        $this->assertSame(422, $this->record(['user_id' => 'u'])->getStatusCode());
    }

    public function testUserIdRequired(): void
    {
        $this->assertSame(422, $this->record(['event_type' => 'x'])->getStatusCode());
    }

    public function testPropertiesMustBeObject(): void
    {
        // a JSON array (list) is not a property object → 422
        $this->assertSame(422, $this->record(['event_type' => 'x', 'user_id' => 'u', 'properties' => [1, 2, 3]])->getStatusCode());
    }

    public function testBadOccurredAtRejected(): void
    {
        $this->assertSame(422, $this->record(['event_type' => 'x', 'user_id' => 'u', 'occurred_at' => '2026-05-27 09:00:00'])->getStatusCode());
    }

    // ── json_extract property filter ────────────────────────────────────────────

    public function testFilterByProperty(): void
    {
        $this->record(['event_type' => 'view', 'user_id' => 'u1', 'properties' => ['path' => '/a'], 'occurred_at' => '2026-05-27T09:00:00Z']);
        $this->record(['event_type' => 'view', 'user_id' => 'u2', 'properties' => ['path' => '/b'], 'occurred_at' => '2026-05-27T09:01:00Z']);
        $data = $this->json($this->req('GET', '/events/by-property', [], null, 'key=path&value=' . rawurlencode('/a')));
        $this->assertSame(1, $data['count']);
        $this->assertSame('/a', $data['events'][0]['properties']['path']);
    }

    public function testFilterByNestedProperty(): void
    {
        $this->record(['event_type' => 'view', 'user_id' => 'u1', 'properties' => ['browser' => ['name' => 'firefox']], 'occurred_at' => '2026-05-27T09:00:00Z']);
        $data = $this->json($this->req('GET', '/events/by-property', [], null, 'key=browser.name&value=firefox'));
        $this->assertSame(1, $data['count']);
    }

    public function testPropertyInjectionKeyRejected(): void
    {
        // a key with SQL-ish chars is rejected by the key-shape guard → 422
        $this->assertSame(422, $this->req('GET', '/events/by-property', [], null, "key=" . rawurlencode("'); DROP--") . '&value=x')->getStatusCode());
    }

    // ── aggregation ─────────────────────────────────────────────────────────────

    public function testPerDay(): void
    {
        $this->record(['event_type' => 'a', 'user_id' => 'u1', 'occurred_at' => '2026-05-27T09:00:00Z']);
        $this->record(['event_type' => 'b', 'user_id' => 'u2', 'occurred_at' => '2026-05-27T18:00:00Z']);
        $this->record(['event_type' => 'a', 'user_id' => 'u1', 'occurred_at' => '2026-05-28T09:00:00Z']);
        $data = $this->json($this->req('GET', '/stats/per-day'));
        $this->assertCount(2, $data['per_day']);
        $this->assertSame('2026-05-27', $data['per_day'][0]['day']);
        $this->assertSame(2, $data['per_day'][0]['count']);
    }

    public function testPerType(): void
    {
        $this->record(['event_type' => 'view', 'user_id' => 'u1', 'occurred_at' => '2026-05-27T09:00:00Z']);
        $this->record(['event_type' => 'view', 'user_id' => 'u2', 'occurred_at' => '2026-05-27T09:01:00Z']);
        $this->record(['event_type' => 'click', 'user_id' => 'u1', 'occurred_at' => '2026-05-27T09:02:00Z']);
        $data = $this->json($this->req('GET', '/stats/per-type'));
        $this->assertSame('view', $data['per_type'][0]['event_type']); // most frequent first
        $this->assertSame(2, $data['per_type'][0]['count']);
    }

    public function testUniqueUsers(): void
    {
        // u1 appears twice on the same day → counted once
        $this->record(['event_type' => 'a', 'user_id' => 'u1', 'occurred_at' => '2026-05-27T09:00:00Z']);
        $this->record(['event_type' => 'a', 'user_id' => 'u1', 'occurred_at' => '2026-05-27T10:00:00Z']);
        $this->record(['event_type' => 'a', 'user_id' => 'u2', 'occurred_at' => '2026-05-27T11:00:00Z']);
        $data = $this->json($this->req('GET', '/stats/unique-users'));
        $this->assertSame(2, $data['unique_users'][0]['unique_users']);
    }

    public function testDateRangeFilter(): void
    {
        $this->record(['event_type' => 'a', 'user_id' => 'u1', 'occurred_at' => '2026-05-27T09:00:00Z']);
        $this->record(['event_type' => 'a', 'user_id' => 'u1', 'occurred_at' => '2026-06-10T09:00:00Z']);
        $data = $this->json($this->req('GET', '/stats/per-day', [], null, 'from=2026-06-01T00:00:00Z&to=2026-07-01T00:00:00Z'));
        $this->assertCount(1, $data['per_day']);
        $this->assertSame('2026-06-10', $data['per_day'][0]['day']);
    }

    public function testBadRangeRejected(): void
    {
        $this->assertSame(422, $this->req('GET', '/stats/per-day', [], null, 'from=2026-06-01')->getStatusCode());
    }

    // ── routing / misc ────────────────────────────────────────────────────────────

    public function testByPropertyRouteNotCapturedById(): void
    {
        // '/events/by-property' must reach the filter handler (422 missing value), not show({id})
        $this->assertSame(422, $this->req('GET', '/events/by-property', [], null, 'key=path')->getStatusCode());
    }

    public function testGetByIdAnd404(): void
    {
        $id = (int) $this->json($this->record(['event_type' => 'a', 'user_id' => 'u1', 'occurred_at' => '2026-05-27T09:00:00Z']))['id'];
        $this->assertSame(200, $this->req('GET', '/events/' . $id)->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/events/999')->getStatusCode());
    }

    public function testListPaginated(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->record(['event_type' => 'a', 'user_id' => 'u', 'occurred_at' => '2026-05-27T09:0' . $i . ':00Z']);
        }
        $this->assertSame(2, $this->json($this->req('GET', '/events', [], null, 'limit=2'))['count']);
    }
}
