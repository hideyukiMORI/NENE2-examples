<?php

declare(strict_types=1);

namespace Rank\Tests\Rank;

use Rank\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RankTest extends TestCase
{
    private string $dbPath;
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/ranklog_test_' . uniqid() . '.sqlite';
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

    private function createLeaderboard(string $name): int
    {
        return (int) $this->json($this->request('POST', '/leaderboards', ['name' => $name]))['id'];
    }

    public function testCreateLeaderboard(): void
    {
        $res = $this->request('POST', '/leaderboards', ['name' => 'Global']);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertArrayHasKey('id', $this->json($res));
    }

    public function testCreateLeaderboardMissingName(): void
    {
        $res = $this->request('POST', '/leaderboards', ['name' => '']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testSubmitScore(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $res = $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 1000]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($this->json($res)['new_best']);
    }

    public function testSubmitScoreUpdatesIfHigher(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 500]);
        $res = $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 900]);

        $this->assertTrue($this->json($res)['new_best']);
    }

    public function testSubmitScoreDoesNotUpdateIfLower(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 900]);
        $res = $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 500]);

        $this->assertFalse($this->json($res)['new_best']);
    }

    public function testSubmitScoreMissingScore(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $res = $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testSubmitScoreUnknownUser(): void
    {
        $lbId = $this->createLeaderboard('Global');
        $res  = $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => 9999, 'score' => 100]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testSubmitScoreUnknownLeaderboard(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', '/leaderboards/9999/scores', ['user_id' => $alice, 'score' => 100]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGetRankings(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $carol = $this->createUser('Carol');
        $lbId  = $this->createLeaderboard('Global');

        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 300]);
        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $bob, 'score' => 500]);
        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $carol, 'score' => 400]);

        $res      = $this->request('GET', "/leaderboards/{$lbId}/rankings");
        $rankings = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(3, $rankings['count']);
        $this->assertSame(1, $rankings['items'][0]['rank']);
        $this->assertSame($bob, $rankings['items'][0]['user_id']);
        $this->assertSame(500, $rankings['items'][0]['score']);
    }

    public function testGetRankingsWithLimit(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $carol = $this->createUser('Carol');
        $lbId  = $this->createLeaderboard('Global');

        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 300]);
        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $bob, 'score' => 500]);
        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $carol, 'score' => 400]);

        $res = $this->request('GET', "/leaderboards/{$lbId}/rankings?limit=2");
        $this->assertSame(2, $this->json($res)['count']);
    }

    public function testGetMyRank(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $lbId  = $this->createLeaderboard('Global');

        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 300]);
        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $bob, 'score' => 500]);

        $res  = $this->request('GET', "/leaderboards/{$lbId}/rankings/me", actorId: (string) $alice);
        $data = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(2, $data['rank']); // Bob has higher score
        $this->assertSame(300, $data['score']);
    }

    public function testGetMyRankNoScore(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $res = $this->request('GET', "/leaderboards/{$lbId}/rankings/me", actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGetMyRankMissingActor(): void
    {
        $lbId = $this->createLeaderboard('Global');
        $res  = $this->request('GET', "/leaderboards/{$lbId}/rankings/me");
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testDeleteScore(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 500]);
        $res = $this->request('DELETE', "/leaderboards/{$lbId}/scores/{$alice}", actorId: (string) $alice);
        $this->assertSame(204, $res->getStatusCode());

        $myRank = $this->request('GET', "/leaderboards/{$lbId}/rankings/me", actorId: (string) $alice);
        $this->assertSame(404, $myRank->getStatusCode());
    }

    public function testDeleteScoreNotFound(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $res = $this->request('DELETE', "/leaderboards/{$lbId}/scores/{$alice}", actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testNegativeScoreIsAllowed(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $res = $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => -100]);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testRankingOrderIsDescending(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $lbId  = $this->createLeaderboard('Global');

        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 100]);
        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $bob, 'score' => 200]);

        $rankings = $this->json($this->request('GET', "/leaderboards/{$lbId}/rankings"));
        $this->assertSame($bob, $rankings['items'][0]['user_id']);
        $this->assertSame($alice, $rankings['items'][1]['user_id']);
    }
}
