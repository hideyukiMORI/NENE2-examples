<?php

declare(strict_types=1);

namespace Message\Tests\Message;

use Message\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * FT135 Vulnerability Assessment
 *
 * Tests that the DM system correctly enforces access control and
 * rejects adversarial inputs.
 */
final class VulnTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/messagelog-vuln-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $this->app = AppFactory::createSqliteApp($this->dbFile);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function request(string $method, string $path, mixed $body = null, string $actorId = ''): ResponseInterface
    {
        $req = new ServerRequest($method, $path);

        if ($body !== null) {
            $req = $req
                ->withBody(Stream::create((string) json_encode($body)))
                ->withHeader('Content-Type', 'application/json');
        }

        if ($actorId !== '') {
            $req = $req->withHeader('X-User-Id', $actorId);
        }

        return $this->app->handle($req);
    }

    private function json(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function createUser(string $name): int
    {
        $req = new ServerRequest('POST', '/users');
        $req = $req->withBody(Stream::create((string) json_encode(['name' => $name])))
                   ->withHeader('Content-Type', 'application/json');
        $res = $this->app->handle($req);

        return (int) $this->json($res)['id'];
    }

    private function startConversation(int $a, int $b): int
    {
        $res = $this->request('POST', '/conversations', ['initiator_id' => $a, 'recipient_id' => $b]);

        return (int) $this->json($res)['id'];
    }

    // VULN-A: IDOR — read messages from a conversation you're not part of
    public function testVulnA_IdorReadMessages(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $carol  = $this->createUser('Carol');
        $convId = $this->startConversation($alice, $bob);

        $this->request('POST', "/conversations/{$convId}/messages", ['sender_id' => $alice, 'content' => 'Secret!']);

        // Carol tries to read Alice & Bob's messages
        $res = $this->request('GET', "/conversations/{$convId}/messages", actorId: (string) $carol);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-A: non-participant must not read messages');
    }

    // VULN-B: IDOR — send a message in a conversation you're not part of
    public function testVulnB_IdorSendMessage(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $carol  = $this->createUser('Carol');
        $convId = $this->startConversation($alice, $bob);

        $res = $this->request('POST', "/conversations/{$convId}/messages", [
            'sender_id' => $carol,
            'content'   => 'Injected message',
        ]);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-B: non-participant must not send messages');
    }

    // VULN-C: IDOR — read another user's conversation list
    public function testVulnC_IdorReadConversationList(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        // Bob tries to read Alice's conversations
        $res = $this->request('GET', "/users/{$alice}/conversations", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-C: must not read other user\'s conversation list');
    }

    // VULN-D: missing X-User-Id header on protected GET endpoint
    public function testVulnD_MissingActorOnListMessages(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $convId = $this->startConversation($alice, $bob);

        // No X-User-Id header
        $res = $this->request('GET', "/conversations/{$convId}/messages");
        // Should fail — 404 (actor not found) or 403 (not a participant), never 200
        $this->assertNotSame(200, $res->getStatusCode(), 'VULN-D: missing actor header must not return messages');
    }

    // VULN-E: missing X-User-Id on conversation list
    public function testVulnE_MissingActorOnConversationList(): void
    {
        $alice = $this->createUser('Alice');

        $res = $this->request('GET', "/users/{$alice}/conversations");
        // actor_id=0 != alice_id → 403
        $this->assertNotSame(200, $res->getStatusCode(), 'VULN-E: missing actor must not return conversation list');
    }

    // VULN-F: negative user ID in path
    public function testVulnF_NegativeUserIdInPath(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('GET', '/users/-1/conversations', actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode(), 'VULN-F: negative user ID must return 404');
    }

    // VULN-G: zero conversation ID in path
    public function testVulnG_ZeroConversationId(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('GET', '/conversations/0/messages', actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode(), 'VULN-G: zero conversation ID must return 404');
    }

    // VULN-H: non-numeric X-User-Id header (header injection attempt)
    public function testVulnH_NonNumericActorHeader(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $convId = $this->startConversation($alice, $bob);

        $res = $this->request('GET', "/conversations/{$convId}/messages", actorId: 'admin');
        $this->assertNotSame(200, $res->getStatusCode(), 'VULN-H: non-numeric X-User-Id must not grant access');
    }

    // VULN-I: SQL injection attempt in content
    public function testVulnI_SqlInjectionInContent(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $convId = $this->startConversation($alice, $bob);

        $payload = "'; DROP TABLE messages; --";
        $res     = $this->request('POST', "/conversations/{$convId}/messages", [
            'sender_id' => $alice,
            'content'   => $payload,
        ]);

        // Should store the content verbatim (prepared statements protect against injection)
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame($payload, $this->json($res)['content']);

        // Messages table must still exist and be readable
        $listRes = $this->request('GET', "/conversations/{$convId}/messages", actorId: (string) $alice);
        $this->assertSame(200, $listRes->getStatusCode());
        $this->assertSame(1, $this->json($listRes)['count']);
    }

    // VULN-J: XSS attempt in content (stored verbatim, no HTML rendering in API)
    public function testVulnJ_XssInContent(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $convId = $this->startConversation($alice, $bob);

        $payload = '<script>alert("xss")</script>';
        $res     = $this->request('POST', "/conversations/{$convId}/messages", [
            'sender_id' => $alice,
            'content'   => $payload,
        ]);

        $this->assertSame(201, $res->getStatusCode());
        // Content stored verbatim — no HTML encoding in JSON API (that's the client's responsibility)
        $this->assertSame($payload, $this->json($res)['content']);
    }

    // VULN-K: self-conversation attempt
    public function testVulnK_SelfConversation(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', '/conversations', [
            'initiator_id' => $alice,
            'recipient_id' => $alice,
        ]);
        $this->assertSame(422, $res->getStatusCode(), 'VULN-K: self-conversation must be rejected');
    }

    // VULN-L: large content (100KB)
    public function testVulnL_LargeContent(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $convId = $this->startConversation($alice, $bob);

        $bigContent = str_repeat('A', 100_000);
        $res        = $this->request('POST', "/conversations/{$convId}/messages", [
            'sender_id' => $alice,
            'content'   => $bigContent,
        ]);

        // The framework accepts it (no content-length limit set here), but it stores verbatim.
        // In production, a request-size middleware would reject this before it reaches the handler.
        // This test documents the current behaviour and is not a failure.
        $this->assertContains($res->getStatusCode(), [201, 413], 'VULN-L: large content returns 201 or 413');
    }
}
