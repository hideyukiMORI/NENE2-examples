<?php

declare(strict_types=1);

namespace Follow\Tests\Follow;

use Follow\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FollowTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/followlog-' . bin2hex(random_bytes(8)) . '.sqlite';
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

    private function request(string $method, string $path, mixed $body = null): ResponseInterface
    {
        $req = new ServerRequest($method, $path);

        if ($body !== null) {
            $req = $req
                ->withBody(Stream::create((string) json_encode($body)))
                ->withHeader('Content-Type', 'application/json');
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

    // --- User creation ---

    public function testCreateUser(): void
    {
        $res  = $this->request('POST', '/users', ['name' => 'Alice']);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Alice', $body['name']);
    }

    // --- Follow ---

    public function testFollow(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $res  = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertTrue($body['following']);
        $this->assertSame($alice, $body['follower_id']);
        $this->assertSame($bob, $body['followee_id']);
    }

    public function testFollowIdempotentReturns200(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
        $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($this->json($res)['following']);
    }

    public function testFollowSelfReturns422(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $alice]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testFollowUnknownFollowerReturns404(): void
    {
        $bob = $this->createUser('Bob');
        $res = $this->request('POST', '/users/9999/follow', ['followee_id' => $bob]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testFollowUnknownFolloweeReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => 9999]);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Unfollow ---

    public function testUnfollow(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
        $res = $this->request('DELETE', "/users/{$alice}/follow/{$bob}");

        $this->assertSame(204, $res->getStatusCode());
    }

    public function testUnfollowNotFollowingReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $res = $this->request('DELETE', "/users/{$alice}/follow/{$bob}");
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testUnfollowThenRefollow(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
        $this->request('DELETE', "/users/{$alice}/follow/{$bob}");
        $res = $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

        $this->assertSame(201, $res->getStatusCode());
    }

    // --- Stats ---

    public function testStatsInitial(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('GET', "/users/{$alice}/stats");
        $body  = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, $body['followers_count']);
        $this->assertSame(0, $body['following_count']);
    }

    public function testStatsAfterFollow(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $carol = $this->createUser('Carol');

        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $carol]);
        $this->request('POST', "/users/{$bob}/follow", ['followee_id' => $alice]);

        $aliceStats = $this->json($this->request('GET', "/users/{$alice}/stats"));
        $bobStats   = $this->json($this->request('GET', "/users/{$bob}/stats"));

        $this->assertSame(1, $aliceStats['followers_count']); // Bob follows Alice
        $this->assertSame(2, $aliceStats['following_count']); // Alice follows Bob + Carol
        $this->assertSame(1, $bobStats['followers_count']);   // Alice follows Bob
        $this->assertSame(1, $bobStats['following_count']);   // Bob follows Alice
    }

    public function testStatsUnknownUserReturns404(): void
    {
        $res = $this->request('GET', '/users/9999/stats');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- List followers/following ---

    public function testListFollowers(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $carol = $this->createUser('Carol');

        $this->request('POST', "/users/{$bob}/follow", ['followee_id' => $alice]);
        $this->request('POST', "/users/{$carol}/follow", ['followee_id' => $alice]);

        $res   = $this->request('GET', "/users/{$alice}/followers");
        $body  = $this->json($res);
        $items = $body['items'];

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $items);
        $this->assertSame(2, $body['count']);
        // Carol followed last → appears first (ORDER BY id DESC)
        $this->assertSame('Carol', $items[0]['name']);
    }

    public function testListFollowing(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $carol = $this->createUser('Carol');

        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $carol]);

        $res   = $this->request('GET', "/users/{$alice}/following");
        $body  = $this->json($res);
        $items = $body['items'];

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $items);
        // Carol followed last → first in list
        $this->assertSame('Carol', $items[0]['name']);
    }

    public function testListFollowersUnknownUserReturns404(): void
    {
        $res = $this->request('GET', '/users/9999/followers');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Is following ---

    public function testIsFollowingFalse(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $res  = $this->request('GET', "/users/{$alice}/is-following/{$bob}");
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($body['following']);
    }

    public function testIsFollowingTrue(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
        $res  = $this->request('GET', "/users/{$alice}/is-following/{$bob}");
        $body = $this->json($res);

        $this->assertTrue($body['following']);
    }

    public function testIsFollowingAfterUnfollow(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
        $this->request('DELETE', "/users/{$alice}/follow/{$bob}");

        $res = $this->request('GET', "/users/{$alice}/is-following/{$bob}");
        $this->assertFalse($this->json($res)['following']);
    }

    // --- Mutual follow ---

    public function testMutualFollow(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);
        $this->request('POST', "/users/{$bob}/follow", ['followee_id' => $alice]);

        $aliceFollowsBob = $this->json($this->request('GET', "/users/{$alice}/is-following/{$bob}"))['following'];
        $bobFollowsAlice = $this->json($this->request('GET', "/users/{$bob}/is-following/{$alice}"))['following'];

        $this->assertTrue($aliceFollowsBob);
        $this->assertTrue($bobFollowsAlice);
    }

    // --- Isolation ---

    public function testFollowerCountsArePerUser(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $carol = $this->createUser('Carol');

        $this->request('POST', "/users/{$alice}/follow", ['followee_id' => $bob]);

        $carolStats = $this->json($this->request('GET', "/users/{$carol}/stats"));
        $this->assertSame(0, $carolStats['followers_count']);
        $this->assertSame(0, $carolStats['following_count']);
    }
}
