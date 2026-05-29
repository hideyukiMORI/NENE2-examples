<?php

declare(strict_types=1);

namespace TicketLog\Tests\Ticket;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TicketLog\AppFactory;

class TicketTest extends TestCase
{
    private const ADMIN_KEY = 'admin-key';

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
        // Event 1: capacity 2.
        $this->pdo->exec("INSERT INTO events (id, name, capacity, created_at) VALUES (1, 'Concert', 2, '2026-01-01T00:00:00Z')");
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

    // ─── event creation ────────────────────────────────────────────────────

    public function testCreateEventRequiresAdmin(): void
    {
        $this->assertSame(403, $this->req('POST', '/events', [], ['name' => 'X', 'capacity' => 5])->getStatusCode());
    }

    public function testCreateEvent(): void
    {
        $res = $this->req('POST', '/events', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => 'Expo', 'capacity' => 5]);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(5, $this->json($res)['remaining']);
    }

    public function testCreateEventValidation(): void
    {
        $this->assertSame(422, $this->req('POST', '/events', ['X-Admin-Key' => self::ADMIN_KEY], ['name' => '', 'capacity' => 0])->getStatusCode());
    }

    // ─── purchase / capacity ─────────────────────────────────────────────────

    public function testPurchaseRequiresUserId(): void
    {
        $this->assertSame(401, $this->req('POST', '/events/1/tickets')->getStatusCode());
    }

    public function testPurchaseUnknownEvent(): void
    {
        $this->assertSame(404, $this->req('POST', '/events/999/tickets', ['X-User-Id' => '1'])->getStatusCode());
    }

    public function testPurchaseSucceedsAndDecrementsRemaining(): void
    {
        $res = $this->req('POST', '/events/1/tickets', ['X-User-Id' => '10']);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(1, $this->json($this->req('GET', '/events/1'))['sold']);
        $this->assertSame(1, $this->json($this->req('GET', '/events/1'))['remaining']);
    }

    public function testCapacityIsEnforced(): void
    {
        $this->assertSame(201, $this->req('POST', '/events/1/tickets', ['X-User-Id' => '10'])->getStatusCode());
        $this->assertSame(201, $this->req('POST', '/events/1/tickets', ['X-User-Id' => '11'])->getStatusCode());
        // capacity 2 reached → third buyer is sold out
        $res = $this->req('POST', '/events/1/tickets', ['X-User-Id' => '12']);
        $this->assertSame(409, $res->getStatusCode());
        $this->assertTrue($this->json($this->req('GET', '/events/1'))['sold_out']);
    }

    public function testDuplicatePurchaseConflicts(): void
    {
        $this->assertSame(201, $this->req('POST', '/events/1/tickets', ['X-User-Id' => '10'])->getStatusCode());
        $res = $this->req('POST', '/events/1/tickets', ['X-User-Id' => '10']);
        $this->assertSame(409, $res->getStatusCode());
        // duplicate did not consume capacity
        $this->assertSame(1, $this->json($this->req('GET', '/events/1'))['sold']);
    }

    // ─── cancel / IDOR ───────────────────────────────────────────────────────

    public function testCancelOwnTicketFreesCapacity(): void
    {
        $purchase = $this->json($this->req('POST', '/events/1/tickets', ['X-User-Id' => '10']));
        $ticketId = (string) $purchase['ticket_id'];

        $this->assertSame(204, $this->req('DELETE', '/tickets/' . $ticketId, ['X-User-Id' => '10'])->getStatusCode());
        $this->assertSame(0, $this->json($this->req('GET', '/events/1'))['sold']);
    }

    public function testCancelOtherUsersTicketIsForbidden(): void
    {
        $purchase = $this->json($this->req('POST', '/events/1/tickets', ['X-User-Id' => '10']));
        $ticketId = (string) $purchase['ticket_id'];

        $res = $this->req('DELETE', '/tickets/' . $ticketId, ['X-User-Id' => '99']);
        $this->assertSame(403, $res->getStatusCode());
        // ticket survived
        $this->assertSame(1, $this->json($this->req('GET', '/events/1'))['sold']);
    }

    public function testCancelUnknownTicket(): void
    {
        $this->assertSame(404, $this->req('DELETE', '/tickets/999', ['X-User-Id' => '10'])->getStatusCode());
    }

    public function testCapacityFreedAllowsNewBuyer(): void
    {
        // Fill capacity 2, cancel one, a new buyer can take the freed slot.
        $p10 = $this->json($this->req('POST', '/events/1/tickets', ['X-User-Id' => '10']));
        $this->req('POST', '/events/1/tickets', ['X-User-Id' => '11']);
        $this->assertSame(409, $this->req('POST', '/events/1/tickets', ['X-User-Id' => '12'])->getStatusCode());

        $this->req('DELETE', '/tickets/' . (string) $p10['ticket_id'], ['X-User-Id' => '10']);
        $this->assertSame(201, $this->req('POST', '/events/1/tickets', ['X-User-Id' => '12'])->getStatusCode());
    }
}
