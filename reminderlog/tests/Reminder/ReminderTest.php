<?php

declare(strict_types=1);

namespace ReminderLog\Tests\Reminder;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReminderLog\AppFactory;

class ReminderTest extends TestCase
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

    private function future(string $offset = '+00:00'): string
    {
        return (new \DateTimeImmutable('+1 day'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s') . $offset;
    }

    private function past(): string
    {
        return (new \DateTimeImmutable('-1 day'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s') . '+00:00';
    }

    // ── create ────────────────────────────────────────────────────────────

    public function testCreateRequiresUser(): void
    {
        $this->assertSame(401, $this->req('POST', '/reminders', [], ['title' => 'x', 'remind_at' => $this->future()])->getStatusCode());
    }

    public function testCreateFutureReminder(): void
    {
        $res = $this->req('POST', '/reminders', ['X-User-Id' => '1'], ['title' => 'Call', 'remind_at' => $this->future()]);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('pending', $this->json($res)['status']);
    }

    public function testPastReminderRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/reminders', ['X-User-Id' => '1'], ['title' => 'x', 'remind_at' => $this->past()])->getStatusCode());
    }

    public function testInvalidDatetimeRejected(): void
    {
        foreach (['2026-06-01T10:00:00Z', '2026-06-01 10:00:00', '2026-02-30T00:00:00+00:00', 'not-a-date', '2026-06-01T10:00:00+25:00'] as $bad) {
            $res = $this->req('POST', '/reminders', ['X-User-Id' => '1'], ['title' => 'x', 'remind_at' => $bad]);
            $this->assertSame(422, $res->getStatusCode(), "remind_at '{$bad}' must be 422");
        }
    }

    // ── cross-timezone future check (the key correctness point) ───────────────

    public function testCrossTimezonePastIsRejected(): void
    {
        // A far-past instant expressed with a large positive offset must still be past.
        $pastInstantWithOffset = (new \DateTimeImmutable('-2 hours'))->setTimezone(new \DateTimeZone('+09:00'))->format('Y-m-d\TH:i:sP');
        $res = $this->req('POST', '/reminders', ['X-User-Id' => '1'], ['title' => 'x', 'remind_at' => $pastInstantWithOffset]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCrossTimezoneFutureIsAccepted(): void
    {
        // A future instant expressed with a negative offset must be accepted.
        $futureInstantWithOffset = (new \DateTimeImmutable('+2 days'))->setTimezone(new \DateTimeZone('-05:00'))->format('Y-m-d\TH:i:sP');
        $res = $this->req('POST', '/reminders', ['X-User-Id' => '1'], ['title' => 'x', 'remind_at' => $futureInstantWithOffset]);
        $this->assertSame(201, $res->getStatusCode());
    }

    // ── list / ownership ──────────────────────────────────────────────────────

    public function testListIsOwnerScoped(): void
    {
        $this->req('POST', '/reminders', ['X-User-Id' => '1'], ['title' => 'a', 'remind_at' => $this->future()]);
        $this->req('POST', '/reminders', ['X-User-Id' => '2'], ['title' => 'b', 'remind_at' => $this->future()]);
        $this->assertSame(1, $this->json($this->req('GET', '/reminders', ['X-User-Id' => '1']))['count']);
    }

    public function testStatusFilter(): void
    {
        $id = (int) $this->json($this->req('POST', '/reminders', ['X-User-Id' => '1'], ['title' => 'a', 'remind_at' => $this->future()]))['id'];
        $this->req('PATCH', "/reminders/{$id}/cancel", ['X-User-Id' => '1']);
        $this->assertSame(1, $this->json($this->req('GET', '/reminders', ['X-User-Id' => '1'], null, ['status' => 'cancelled']))['count']);
        $this->assertSame(0, $this->json($this->req('GET', '/reminders', ['X-User-Id' => '1'], null, ['status' => 'pending']))['count']);
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancel(): void
    {
        $id = (int) $this->json($this->req('POST', '/reminders', ['X-User-Id' => '1'], ['title' => 'a', 'remind_at' => $this->future()]))['id'];
        $res = $this->req('PATCH', "/reminders/{$id}/cancel", ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('cancelled', $this->json($res)['status']);
    }

    public function testCancelTwiceConflicts(): void
    {
        $id = (int) $this->json($this->req('POST', '/reminders', ['X-User-Id' => '1'], ['title' => 'a', 'remind_at' => $this->future()]))['id'];
        $this->req('PATCH', "/reminders/{$id}/cancel", ['X-User-Id' => '1']);
        $this->assertSame(409, $this->req('PATCH', "/reminders/{$id}/cancel", ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testCancelOtherUsersReminderIs404(): void
    {
        $id = (int) $this->json($this->req('POST', '/reminders', ['X-User-Id' => '1'], ['title' => 'a', 'remind_at' => $this->future()]))['id'];
        $this->assertSame(404, $this->req('PATCH', "/reminders/{$id}/cancel", ['X-User-Id' => '99'])->getStatusCode());
    }
}
