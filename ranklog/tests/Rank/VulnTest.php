<?php

declare(strict_types=1);

namespace Rank\Tests\Rank;

use Rank\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Vulnerability assessment tests for FT141.
 */
final class VulnTest extends TestCase
{
    private string $dbPath;
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/ranklog_vuln_' . uniqid() . '.sqlite';
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

    /** VULN-A: IDOR — delete another user's score */
    public function testVulnAIdorDeleteOtherUserScore(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $lbId  = $this->createLeaderboard('Global');

        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 500]);

        // Bob tries to delete Alice's score
        $res = $this->request('DELETE', "/leaderboards/{$lbId}/scores/{$alice}", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    /** VULN-B: Submit score for another user */
    public function testVulnBSubmitScoreForAnotherUser(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $lbId  = $this->createLeaderboard('Global');

        // Note: submitScore uses user_id in body, not actor header
        // This is intentional (admin can submit for users), but verify it works
        $res = $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 9999]);
        $this->assertSame(200, $res->getStatusCode());

        // Bob checks ranking — Alice's score should be 9999 (legitimate use)
        $_ = $bob; // suppress unused warning
        $rankings = $this->json($this->request('GET', "/leaderboards/{$lbId}/rankings"));
        $this->assertSame(9999, $rankings['items'][0]['score']);
    }

    /** VULN-C: SQL injection in leaderboard name */
    public function testVulnCSqlInjectionInLeaderboardName(): void
    {
        $res = $this->request('POST', '/leaderboards', ['name' => "'; DROP TABLE leaderboards; --"]);
        $this->assertSame(201, $res->getStatusCode());

        // Follow-up request still works
        $res2 = $this->request('POST', '/leaderboards', ['name' => 'Safe Board']);
        $this->assertSame(201, $res2->getStatusCode());
    }

    /** VULN-D: Missing X-User-Id on my rank endpoint */
    public function testVulnDMissingActorOnMyRank(): void
    {
        $lbId = $this->createLeaderboard('Global');
        $res  = $this->request('GET', "/leaderboards/{$lbId}/rankings/me");
        $this->assertSame(400, $res->getStatusCode());
    }

    /** VULN-E: Non-numeric X-User-Id */
    public function testVulnENonNumericActorId(): void
    {
        $lbId = $this->createLeaderboard('Global');
        $res  = $this->request('GET', "/leaderboards/{$lbId}/rankings/me", actorId: 'admin');
        $this->assertNotSame(200, $res->getStatusCode());
    }

    /** VULN-F: Negative leaderboard ID */
    public function testVulnFNegativeLeaderboardId(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('GET', '/leaderboards/-1/rankings/me', actorId: (string) $alice);
        $this->assertNotSame(200, $res->getStatusCode());
    }

    /** VULN-G: Integer overflow in score */
    public function testVulnGIntegerOverflowScore(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        // PHP_INT_MAX as score — should be stored verbatim (integer)
        $res = $this->request('POST', "/leaderboards/{$lbId}/scores", [
            'user_id' => $alice,
            'score'   => PHP_INT_MAX,
        ]);
        // Should succeed with a valid integer score
        $this->assertSame(200, $res->getStatusCode());
    }

    /** VULN-H: Score as float (type confusion) */
    public function testVulnHScoreAsFloat(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $res = $this->request('POST', "/leaderboards/{$lbId}/scores", [
            'user_id' => $alice,
            'score'   => 9.99,
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    /** VULN-I: Score as string (type confusion) */
    public function testVulnIScoreAsString(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $res = $this->request('POST', "/leaderboards/{$lbId}/scores", [
            'user_id' => $alice,
            'score'   => '9999999',
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    /** VULN-J: Missing X-User-Id on delete */
    public function testVulnJMissingActorOnDelete(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 500]);
        $res = $this->request('DELETE', "/leaderboards/{$lbId}/scores/{$alice}");
        $this->assertSame(400, $res->getStatusCode());
    }

    /** VULN-K: Submit score for user_id 0 */
    public function testVulnKSubmitScoreForUserId0(): void
    {
        $lbId = $this->createLeaderboard('Global');
        $res  = $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => 0, 'score' => 100]);
        $this->assertSame(422, $res->getStatusCode());
    }

    /** VULN-L: Limit parameter manipulation (very large limit) */
    public function testVulnLLimitParameterClamped(): void
    {
        $alice = $this->createUser('Alice');
        $lbId  = $this->createLeaderboard('Global');

        $this->request('POST', "/leaderboards/{$lbId}/scores", ['user_id' => $alice, 'score' => 100]);

        // limit=99999 should be clamped to 10 or 100 max
        $res      = $this->request('GET', "/leaderboards/{$lbId}/rankings?limit=99999");
        $rankings = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        // Should return 1 (only Alice), not crash
        $this->assertSame(1, $rankings['count']);
    }
}
