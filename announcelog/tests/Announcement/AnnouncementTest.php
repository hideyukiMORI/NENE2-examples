<?php

declare(strict_types=1);

namespace AnnounceLog\Tests\Announcement;

use AnnounceLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class AnnouncementTest extends TestCase
{
    private const ADMIN_KEY = 'super-secret-admin-key';

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

    /** @param array<string, mixed> $overrides */
    private function createActive(array $overrides = []): int
    {
        $body = array_merge([
            'title' => 'Maintenance',
            'body' => 'Scheduled downtime',
            'priority' => 1,
            'starts_at' => $this->iso('-1 hour'),
            'ends_at' => $this->iso('+1 hour'),
        ], $overrides);
        $res = $this->req('POST', '/announcements', ['X-Admin-Key' => self::ADMIN_KEY], $body);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    // ── admin auth ──────────────────────────────────────────────────────────

    public function testCreateRequiresAdminKey(): void
    {
        $this->assertSame(401, $this->req('POST', '/announcements', [], ['title' => 'x'])->getStatusCode());
    }

    public function testWrongAdminKeyRejected(): void
    {
        $res = $this->req('POST', '/announcements', ['X-Admin-Key' => 'wrong'], ['title' => 'x']);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testEmptyConfiguredKeyFailsClosed(): void
    {
        // even an empty provided key must not authenticate when server key is unset
        $res = $this->req('POST', '/announcements', ['X-Admin-Key' => ''], ['title' => 'x'], '');
        $this->assertSame(401, $res->getStatusCode());
    }

    // ── create / validation ───────────────────────────────────────────────────

    public function testCreate(): void
    {
        $id = $this->createActive(['title' => 'Hello']);
        $res = $this->req('GET', '/announcements');
        $data = $this->json($res);
        $this->assertSame(1, $data['count']);
        $this->assertSame('Hello', $data['announcements'][0]['title']);
        $this->assertSame($id, $data['announcements'][0]['id']);
    }

    public function testTitleRequired(): void
    {
        $res = $this->req('POST', '/announcements', ['X-Admin-Key' => self::ADMIN_KEY], [
            'starts_at' => $this->iso('-1 hour'),
            'ends_at' => $this->iso('+1 hour'),
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testEndsBeforeStartsRejected(): void
    {
        $res = $this->req('POST', '/announcements', ['X-Admin-Key' => self::ADMIN_KEY], [
            'title' => 'bad window',
            'starts_at' => $this->iso('+2 hour'),
            'ends_at' => $this->iso('+1 hour'),
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testBadOffsetRejected(): void
    {
        $res = $this->req('POST', '/announcements', ['X-Admin-Key' => self::ADMIN_KEY], [
            'title' => 'x',
            'starts_at' => '2026-01-01T00:00:00+25:00', // out-of-range offset
            'ends_at' => $this->iso('+1 hour'),
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testNonIntPriorityRejected(): void
    {
        $res = $this->req('POST', '/announcements', ['X-Admin-Key' => self::ADMIN_KEY], [
            'title' => 'x',
            'priority' => '5', // string, not int
            'starts_at' => $this->iso('-1 hour'),
            'ends_at' => $this->iso('+1 hour'),
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ── time-based activation ───────────────────────────────────────────────────

    public function testFutureAnnouncementNotYetActive(): void
    {
        $this->createActive(['starts_at' => $this->iso('+1 hour'), 'ends_at' => $this->iso('+2 hour')]);
        $this->assertSame(0, $this->json($this->req('GET', '/announcements'))['count']);
    }

    public function testExpiredAnnouncementNotActive(): void
    {
        $this->createActive(['starts_at' => $this->iso('-2 hour'), 'ends_at' => $this->iso('-1 hour')]);
        $this->assertSame(0, $this->json($this->req('GET', '/announcements'))['count']);
    }

    // ── priority ordering ───────────────────────────────────────────────────────

    public function testPriorityOrdering(): void
    {
        $this->createActive(['title' => 'low', 'priority' => 1]);
        $this->createActive(['title' => 'high', 'priority' => 10]);
        $items = $this->json($this->req('GET', '/announcements'))['announcements'];
        $this->assertSame('high', $items[0]['title']);
        $this->assertSame('low', $items[1]['title']);
    }

    // ── update / delete ─────────────────────────────────────────────────────────

    public function testUpdate(): void
    {
        $id = $this->createActive(['title' => 'old']);
        $res = $this->req('PUT', '/announcements/' . $id, ['X-Admin-Key' => self::ADMIN_KEY], [
            'title' => 'new',
            'starts_at' => $this->iso('-1 hour'),
            'ends_at' => $this->iso('+3 hour'),
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('new', $this->json($res)['title']);
    }

    public function testUpdateUnknownIs404(): void
    {
        $res = $this->req('PUT', '/announcements/999', ['X-Admin-Key' => self::ADMIN_KEY], [
            'title' => 'x',
            'starts_at' => $this->iso('-1 hour'),
            'ends_at' => $this->iso('+1 hour'),
        ]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testDelete(): void
    {
        $id = $this->createActive();
        $this->assertSame(200, $this->req('DELETE', '/announcements/' . $id, ['X-Admin-Key' => self::ADMIN_KEY])->getStatusCode());
        $this->assertSame(0, $this->json($this->req('GET', '/announcements'))['count']);
    }

    public function testDeleteUnknownIs404(): void
    {
        $this->assertSame(404, $this->req('DELETE', '/announcements/999', ['X-Admin-Key' => self::ADMIN_KEY])->getStatusCode());
    }

    public function testDeleteRequiresAdmin(): void
    {
        $id = $this->createActive();
        $this->assertSame(401, $this->req('DELETE', '/announcements/' . $id)->getStatusCode());
    }

    // ── dismissal ─────────────────────────────────────────────────────────────────

    public function testDismissExcludesForUser(): void
    {
        $id = $this->createActive();
        $this->assertSame(200, $this->req('POST', '/announcements/' . $id . '/dismiss', ['X-User-Id' => '1'])->getStatusCode());
        // user 1 no longer sees it
        $this->assertSame(0, $this->json($this->req('GET', '/announcements', ['X-User-Id' => '1']))['count']);
        // user 2 still sees it
        $this->assertSame(1, $this->json($this->req('GET', '/announcements', ['X-User-Id' => '2']))['count']);
        // anonymous (no user) still sees it
        $this->assertSame(1, $this->json($this->req('GET', '/announcements'))['count']);
    }

    public function testDismissIsIdempotent(): void
    {
        $id = $this->createActive();
        $this->req('POST', '/announcements/' . $id . '/dismiss', ['X-User-Id' => '1']);
        $this->assertSame(200, $this->req('POST', '/announcements/' . $id . '/dismiss', ['X-User-Id' => '1'])->getStatusCode());
        $this->assertSame(0, $this->json($this->req('GET', '/announcements', ['X-User-Id' => '1']))['count']);
    }

    public function testDismissRequiresUser(): void
    {
        $id = $this->createActive();
        $this->assertSame(401, $this->req('POST', '/announcements/' . $id . '/dismiss')->getStatusCode());
    }

    public function testDismissUnknownIs404(): void
    {
        $this->assertSame(404, $this->req('POST', '/announcements/999/dismiss', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testListWithMalformedUserIdRejected(): void
    {
        $this->createActive();
        $this->assertSame(401, $this->req('GET', '/announcements', ['X-User-Id' => 'abc'])->getStatusCode());
    }
}
