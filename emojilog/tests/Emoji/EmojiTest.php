<?php

declare(strict_types=1);

namespace Emoji\Tests\Emoji;

use Emoji\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class EmojiTest extends TestCase
{
    private string $dbPath;
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/emojilog_test_' . uniqid() . '.sqlite';
        $schema       = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec($schema);

        $this->app = AppFactory::createSqliteApp($this->dbPath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
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
        return (int) $this->json($this->request('POST', '/users', ['name' => $name]))['id'];
    }

    private function createPost(int $userId, string $content): int
    {
        return (int) $this->json($this->request('POST', '/posts', ['content' => $content], actorId: (string) $userId))['id'];
    }

    public function testCreatePost(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', '/posts', ['content' => 'Hello World'], actorId: (string) $alice);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertArrayHasKey('id', $this->json($res));
    }

    public function testCreatePostMissingActor(): void
    {
        $res = $this->request('POST', '/posts', ['content' => 'Hello']);
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testAddReaction(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $res = $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testAddDuplicateReactionReturns409(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $res = $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);

        $this->assertSame(409, $res->getStatusCode());
    }

    public function testDifferentEmojisAllowed(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $r1 = $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $r2 = $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '❤️'], actorId: (string) $alice);

        $this->assertSame(201, $r1->getStatusCode());
        $this->assertSame(201, $r2->getStatusCode());
    }

    public function testMultipleUsersCanReactWithSameEmoji(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $postId = $this->createPost($alice, 'Hello');

        $r1 = $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $r2 = $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $bob);

        $this->assertSame(201, $r1->getStatusCode());
        $this->assertSame(201, $r2->getStatusCode());
    }

    public function testGetReactionCounts(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $postId = $this->createPost($alice, 'Hello');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $bob);
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '❤️'], actorId: (string) $alice);

        $res  = $this->request('GET', "/posts/{$postId}/reactions");
        $data = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(2, $data['counts']['👍']);
        $this->assertSame(1, $data['counts']['❤️']);
        $this->assertSame(3, $data['total']);
    }

    public function testGetReactionsWithActorShowsUserReactions(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '❤️'], actorId: (string) $alice);

        $data = $this->json($this->request('GET', "/posts/{$postId}/reactions", actorId: (string) $alice));
        $this->assertContains('👍', $data['user_reactions']);
        $this->assertContains('❤️', $data['user_reactions']);
    }

    public function testRemoveReaction(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $res = $this->request('DELETE', "/posts/{$postId}/reactions/👍", actorId: (string) $alice);
        $this->assertSame(204, $res->getStatusCode());

        $data = $this->json($this->request('GET', "/posts/{$postId}/reactions"));
        $this->assertSame(0, $data['total']);
    }

    public function testRemoveNonExistentReaction(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $res = $this->request('DELETE', "/posts/{$postId}/reactions/👍", actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testRemoveReactionMissingActor(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $res = $this->request('DELETE', "/posts/{$postId}/reactions/👍");
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testGetReactionsForUnknownPost(): void
    {
        $res = $this->request('GET', '/posts/9999/reactions');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testAddReactionToUnknownPost(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', '/posts/9999/reactions', ['emoji' => '👍'], actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testEmptyReactions(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $data = $this->json($this->request('GET', "/posts/{$postId}/reactions"));
        $this->assertSame(0, $data['total']);
        $this->assertSame([], $data['counts']);
    }

    public function testReactionCountsOrderedByCountDesc(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $carol  = $this->createUser('Carol');
        $postId = $this->createPost($alice, 'Hello');

        // 3x 👍, 1x ❤️
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $bob);
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $carol);
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '❤️'], actorId: (string) $alice);

        $data   = $this->json($this->request('GET', "/posts/{$postId}/reactions"));
        $keys   = array_keys($data['counts']);
        $this->assertSame('👍', $keys[0]);
        $this->assertSame('❤️', $keys[1]);
    }

    public function testEmojiTooLong(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $res = $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => str_repeat('a', 9)], actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testAddReactionMissingEmoji(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $res = $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => ''], actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testUserReactionsEmptyWithoutActor(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);

        $data = $this->json($this->request('GET', "/posts/{$postId}/reactions"));
        $this->assertSame([], $data['user_reactions']);
    }
}
