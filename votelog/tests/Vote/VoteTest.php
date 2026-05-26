<?php

declare(strict_types=1);

namespace Vote\Tests\Vote;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Vote\AppFactory;

final class VoteTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/votelog-' . bin2hex(random_bytes(8)) . '.sqlite';
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

    /** @param array<string, string> $query */
    private function request(string $method, string $path, mixed $body = null, array $query = []): ResponseInterface
    {
        $req = new ServerRequest($method, $path);

        if ($body !== null) {
            $req = $req
                ->withBody(Stream::create((string) json_encode($body)))
                ->withHeader('Content-Type', 'application/json');
        }

        if ($query !== []) {
            $req = $req->withQueryParams($query);
        }

        return $this->app->handle($req);
    }

    private function json(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function createUser(string $name = 'Alice'): int
    {
        $res = $this->request('POST', '/users', ['name' => $name]);
        $this->assertSame(201, $res->getStatusCode());

        return (int) $this->json($res)['id'];
    }

    private function createItem(string $title = 'Post A'): int
    {
        $res = $this->request('POST', '/items', ['title' => $title]);
        $this->assertSame(201, $res->getStatusCode());

        return (int) $this->json($res)['id'];
    }

    // --- Setup ---

    public function testCreateUser(): void
    {
        $res  = $this->request('POST', '/users', ['name' => 'Alice']);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Alice', $body['name']);
        $this->assertIsInt($body['id']);
    }

    public function testCreateItem(): void
    {
        $res  = $this->request('POST', '/items', ['title' => 'Great Post']);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Great Post', $body['title']);
        $this->assertIsInt($body['id']);
    }

    // --- Score on empty item ---

    public function testScoreStartsAtZero(): void
    {
        $itemId = $this->createItem();
        $res    = $this->request('GET', "/items/{$itemId}/score");
        $body   = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, $body['upvotes']);
        $this->assertSame(0, $body['downvotes']);
        $this->assertSame(0, $body['score']);
    }

    // --- Upvote ---

    public function testUpvote(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $res  = $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'up']);
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('up', $body['vote']);
        $this->assertSame(1, $body['score']['upvotes']);
        $this->assertSame(0, $body['score']['downvotes']);
        $this->assertSame(1, $body['score']['score']);
    }

    public function testDownvote(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $res  = $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'down']);
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('down', $body['vote']);
        $this->assertSame(0, $body['score']['upvotes']);
        $this->assertSame(1, $body['score']['downvotes']);
        $this->assertSame(-1, $body['score']['score']);
    }

    // --- Toggle (same direction → remove) ---

    public function testUpvoteTwiceTogglesOff(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'up']);
        $res  = $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'up']);
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertNull($body['vote']);
        $this->assertSame(0, $body['score']['score']);
    }

    public function testDownvoteTwiceTogglesOff(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'down']);
        $res  = $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'down']);
        $body = $this->json($res);

        $this->assertNull($body['vote']);
        $this->assertSame(0, $body['score']['score']);
    }

    // --- Direction change ---

    public function testChangeUpvoteToDownvote(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'up']);
        $res  = $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'down']);
        $body = $this->json($res);

        $this->assertSame('down', $body['vote']);
        $this->assertSame(0, $body['score']['upvotes']);
        $this->assertSame(1, $body['score']['downvotes']);
        $this->assertSame(-1, $body['score']['score']);
    }

    public function testChangeDownvoteToUpvote(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'down']);
        $res  = $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'up']);
        $body = $this->json($res);

        $this->assertSame('up', $body['vote']);
        $this->assertSame(1, $body['score']['upvotes']);
        $this->assertSame(0, $body['score']['downvotes']);
    }

    // --- Multiple users ---

    public function testMultipleUsersVote(): void
    {
        $user1  = $this->createUser('Alice');
        $user2  = $this->createUser('Bob');
        $user3  = $this->createUser('Carol');
        $itemId = $this->createItem();

        $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $user1, 'direction' => 'up']);
        $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $user2, 'direction' => 'up']);
        $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $user3, 'direction' => 'down']);

        $res  = $this->request('GET', "/items/{$itemId}/score");
        $body = $this->json($res);

        $this->assertSame(2, $body['upvotes']);
        $this->assertSame(1, $body['downvotes']);
        $this->assertSame(1, $body['score']);
    }

    // --- User vote state ---

    public function testGetUserVoteReturnsNull(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $res  = $this->request('GET', "/items/{$itemId}/vote/{$userId}");
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertNull($body['vote']);
    }

    public function testGetUserVoteAfterUpvote(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'up']);
        $res  = $this->request('GET', "/items/{$itemId}/vote/{$userId}");
        $body = $this->json($res);

        $this->assertSame('up', $body['vote']);
    }

    public function testGetUserVoteAfterToggle(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'up']);
        $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'up']);
        $res  = $this->request('GET', "/items/{$itemId}/vote/{$userId}");
        $body = $this->json($res);

        $this->assertNull($body['vote']);
    }

    // --- Validation ---

    public function testInvalidDirectionReturns422(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $res = $this->request('POST', "/items/{$itemId}/vote", ['user_id' => $userId, 'direction' => 'sideways']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testUnknownItemReturns404(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', '/items/9999/vote', ['user_id' => $userId, 'direction' => 'up']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testUnknownUserReturns404(): void
    {
        $itemId = $this->createItem();
        $res    = $this->request('POST', "/items/{$itemId}/vote", ['user_id' => 9999, 'direction' => 'up']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testScoreUnknownItemReturns404(): void
    {
        $res = $this->request('GET', '/items/9999/score');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGetVoteUnknownItemReturns404(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('GET', "/items/9999/vote/{$userId}");
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGetVoteUnknownUserReturns404(): void
    {
        $itemId = $this->createItem();
        $res    = $this->request('GET', "/items/{$itemId}/vote/9999");
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Per-item isolation ---

    public function testVotesAreIsolatedPerItem(): void
    {
        $userId  = $this->createUser();
        $itemId1 = $this->createItem('Item 1');
        $itemId2 = $this->createItem('Item 2');

        $this->request('POST', "/items/{$itemId1}/vote", ['user_id' => $userId, 'direction' => 'up']);

        $score2 = $this->json($this->request('GET', "/items/{$itemId2}/score"));
        $this->assertSame(0, $score2['score']);
    }
}
