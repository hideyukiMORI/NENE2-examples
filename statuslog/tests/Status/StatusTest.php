<?php

declare(strict_types=1);

namespace StatusLog\Tests\Status;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use StatusLog\AppFactory;

class StatusTest extends TestCase
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

    /** @param array<string, mixed> $body */
    private function admin(string $method, string $path, array $body = []): ResponseInterface
    {
        return $this->req($method, $path, ['X-Admin-Key' => self::ADMIN_KEY], $body);
    }

    private function incident(string $title = 'DB lag', string $impact = 'major'): int
    {
        $res = $this->admin('POST', '/incidents', ['title' => $title, 'impact' => $impact]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    // ── admin auth ──────────────────────────────────────────────────────────

    public function testComponentsPublicRead(): void
    {
        $this->assertSame(200, $this->req('GET', '/components')->getStatusCode());
    }

    public function testCreateComponentRequiresAdmin(): void
    {
        $this->assertSame(403, $this->req('POST', '/components', [], ['name' => 'API'])->getStatusCode());
        $this->assertSame(403, $this->req('POST', '/components', ['X-Admin-Key' => 'wrong'], ['name' => 'API'])->getStatusCode());
    }

    public function testCreateAndUpdateComponent(): void
    {
        $id = (int) $this->json($this->admin('POST', '/components', ['name' => 'API']))['id'];
        $this->assertSame('operational', $this->json($this->req('GET', '/components'))['components'][0]['status']);
        $res = $this->admin('PATCH', "/components/{$id}", ['status' => 'major_outage']);
        $this->assertSame('major_outage', $this->json($res)['status']);
    }

    public function testInvalidComponentStatusRejected(): void
    {
        $this->assertSame(422, $this->admin('POST', '/components', ['name' => 'X', 'status' => 'on_fire'])->getStatusCode());
    }

    // ── incident lifecycle ────────────────────────────────────────────────────

    public function testInvalidImpactRejected(): void
    {
        $this->assertSame(422, $this->admin('POST', '/incidents', ['title' => 'x', 'impact' => 'apocalyptic'])->getStatusCode());
    }

    public function testFullLifecycle(): void
    {
        $id = $this->incident();
        $this->assertSame('investigating', $this->json($this->req('GET', "/incidents/{$id}"))['status']);

        // add update (status + message)
        $this->assertSame(201, $this->admin('POST', "/incidents/{$id}/updates", ['status' => 'identified', 'message' => 'Root cause found'])->getStatusCode());
        $this->assertSame('identified', $this->json($this->req('GET', "/incidents/{$id}"))['status']);

        // patch to monitoring
        $this->assertSame('monitoring', $this->json($this->admin('PATCH', "/incidents/{$id}", ['status' => 'monitoring']))['status']);

        // resolve → resolved_at set
        $resolved = $this->json($this->admin('PATCH', "/incidents/{$id}", ['status' => 'resolved']));
        $this->assertSame('resolved', $resolved['status']);
        $this->assertNotNull($resolved['resolved_at']);
    }

    public function testResolvedIncidentIsImmutable(): void
    {
        $id = $this->incident();
        $this->admin('PATCH', "/incidents/{$id}", ['status' => 'resolved']);
        // any further write → 409
        $this->assertSame(409, $this->admin('PATCH', "/incidents/{$id}", ['status' => 'monitoring'])->getStatusCode());
        $this->assertSame(409, $this->admin('POST', "/incidents/{$id}/updates", ['status' => 'investigating', 'message' => 'reopen?'])->getStatusCode());
    }

    public function testOpenFilterExcludesResolved(): void
    {
        $id = $this->incident();
        $this->incident('Another', 'minor');
        $this->admin('PATCH', "/incidents/{$id}", ['status' => 'resolved']);
        $this->assertSame(1, $this->json($this->req('GET', '/incidents', [], null, ['open' => '1']))['count']);
        $this->assertSame(2, $this->json($this->req('GET', '/incidents'))['count']);
    }

    public function testIncidentTimeline(): void
    {
        $id = $this->incident();
        $this->admin('POST', "/incidents/{$id}/updates", ['status' => 'identified', 'message' => 'A']);
        $this->admin('POST', "/incidents/{$id}/updates", ['status' => 'monitoring', 'message' => 'B']);
        $data = $this->json($this->req('GET', "/incidents/{$id}"));
        $this->assertCount(2, $data['updates']);
        $this->assertSame(['A', 'B'], array_map(static fn (array $u): string => $u['message'], $data['updates']));
    }

    public function testInvalidIncidentStatusRejected(): void
    {
        $id = $this->incident();
        $this->assertSame(422, $this->admin('PATCH', "/incidents/{$id}", ['status' => 'exploded'])->getStatusCode());
    }

    public function testUpdateRequiresAdmin(): void
    {
        $id = $this->incident();
        $this->assertSame(403, $this->req('PATCH', "/incidents/{$id}", [], ['status' => 'monitoring'])->getStatusCode());
    }
}
