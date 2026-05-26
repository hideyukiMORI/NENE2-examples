<?php

declare(strict_types=1);

namespace Message\Tests\Message;

use Message\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MessageTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/messagelog-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $res = $this->request('POST', '/users', ['name' => $name]);
        $this->assertSame(201, $res->getStatusCode());

        return (int) $this->json($res)['id'];
    }

    private function startConversation(int $initiatorId, int $recipientId): int
    {
        $res = $this->request('POST', '/conversations', [
            'initiator_id' => $initiatorId,
            'recipient_id' => $recipientId,
        ]);
        $this->assertSame(201, $res->getStatusCode());

        return (int) $this->json($res)['id'];
    }

    // --- User creation ---

    public function testCreateUser(): void
    {
        $res  = $this->request('POST', '/users', ['name' => 'Alice']);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Alice', $body['name']);
    }

    // --- Start conversation ---

    public function testStartConversation(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $res  = $this->request('POST', '/conversations', [
            'initiator_id' => $alice,
            'recipient_id' => $bob,
        ]);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame($alice, $body['initiator_id']);
        $this->assertSame($bob, $body['recipient_id']);
    }

    public function testStartConversationIdempotentReturns200(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $first  = $this->request('POST', '/conversations', ['initiator_id' => $alice, 'recipient_id' => $bob]);
        $second = $this->request('POST', '/conversations', ['initiator_id' => $alice, 'recipient_id' => $bob]);

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame($this->json($first)['id'], $this->json($second)['id']);
    }

    public function testStartConversationReverseDirectionReturnsSameConversation(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $ab = $this->request('POST', '/conversations', ['initiator_id' => $alice, 'recipient_id' => $bob]);
        $ba = $this->request('POST', '/conversations', ['initiator_id' => $bob, 'recipient_id' => $alice]);

        $this->assertSame(201, $ab->getStatusCode());
        $this->assertSame(200, $ba->getStatusCode());
        $this->assertSame($this->json($ab)['id'], $this->json($ba)['id']);
    }

    public function testStartConversationWithSelfReturns422(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', '/conversations', [
            'initiator_id' => $alice,
            'recipient_id' => $alice,
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testStartConversationUnknownInitiatorReturns404(): void
    {
        $bob = $this->createUser('Bob');
        $res = $this->request('POST', '/conversations', ['initiator_id' => 9999, 'recipient_id' => $bob]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testStartConversationUnknownRecipientReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', '/conversations', ['initiator_id' => $alice, 'recipient_id' => 9999]);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Send message ---

    public function testSendMessage(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $convId = $this->startConversation($alice, $bob);

        $res  = $this->request('POST', "/conversations/{$convId}/messages", [
            'sender_id' => $alice,
            'content'   => 'Hello Bob!',
        ]);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame($alice, $body['sender_id']);
        $this->assertSame('Hello Bob!', $body['content']);
        $this->assertSame($convId, $body['conversation_id']);
    }

    public function testSendMessageBothParticipantsCanSend(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $convId = $this->startConversation($alice, $bob);

        $res1 = $this->request('POST', "/conversations/{$convId}/messages", ['sender_id' => $alice, 'content' => 'Hi!']);
        $res2 = $this->request('POST', "/conversations/{$convId}/messages", ['sender_id' => $bob, 'content' => 'Hey!']);

        $this->assertSame(201, $res1->getStatusCode());
        $this->assertSame(201, $res2->getStatusCode());
    }

    public function testSendMessageNonParticipantReturns403(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $carol  = $this->createUser('Carol');
        $convId = $this->startConversation($alice, $bob);

        $res = $this->request('POST', "/conversations/{$convId}/messages", [
            'sender_id' => $carol,
            'content'   => 'Intruder!',
        ]);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testSendMessageUnknownConversationReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', '/conversations/9999/messages', [
            'sender_id' => $alice,
            'content'   => 'Hello?',
        ]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testSendMessageEmptyContentReturns422(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $convId = $this->startConversation($alice, $bob);

        $res = $this->request('POST', "/conversations/{$convId}/messages", [
            'sender_id' => $alice,
            'content'   => '   ',
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- List messages ---

    public function testListMessages(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $convId = $this->startConversation($alice, $bob);

        $this->request('POST', "/conversations/{$convId}/messages", ['sender_id' => $alice, 'content' => 'Hello!']);
        $this->request('POST', "/conversations/{$convId}/messages", ['sender_id' => $bob, 'content' => 'Hi there!']);

        $res  = $this->request('GET', "/conversations/{$convId}/messages", actorId: (string) $alice);
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $body['items']);
        $this->assertSame(2, $body['count']);
        // Messages are ordered ASC
        $this->assertSame('Hello!', $body['items'][0]['content']);
        $this->assertSame('Hi there!', $body['items'][1]['content']);
    }

    public function testListMessagesNonParticipantReturns403(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $carol  = $this->createUser('Carol');
        $convId = $this->startConversation($alice, $bob);

        $res = $this->request('GET', "/conversations/{$convId}/messages", actorId: (string) $carol);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testListMessagesUnknownConversationReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('GET', '/conversations/9999/messages', actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- List user conversations ---

    public function testListUserConversations(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $carol = $this->createUser('Carol');

        $this->startConversation($alice, $bob);
        $this->startConversation($alice, $carol);

        $res  = $this->request('GET', "/users/{$alice}/conversations", actorId: (string) $alice);
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $body['items']);
        $this->assertSame(2, $body['count']);
    }

    public function testListUserConversationsOtherUserReturns403(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $res = $this->request('GET', "/users/{$alice}/conversations", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testListUserConversationsUnknownUserReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('GET', '/users/9999/conversations', actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Isolation ---

    public function testMessagesAreIsolatedPerConversation(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $carol = $this->createUser('Carol');

        $conv1 = $this->startConversation($alice, $bob);
        $conv2 = $this->startConversation($alice, $carol);

        $this->request('POST', "/conversations/{$conv1}/messages", ['sender_id' => $alice, 'content' => 'To Bob']);
        $this->request('POST', "/conversations/{$conv1}/messages", ['sender_id' => $alice, 'content' => 'Also to Bob']);

        $res  = $this->request('GET', "/conversations/{$conv2}/messages", actorId: (string) $alice);
        $body = $this->json($res);

        $this->assertSame(0, $body['count']);
    }
}
