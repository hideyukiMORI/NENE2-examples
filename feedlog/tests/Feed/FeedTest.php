<?php

declare(strict_types=1);

namespace FeedLog\Tests\Feed;

use FeedLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FeedTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/feedlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));

        // Seed users
        $pdo->exec("INSERT INTO users (name, created_at) VALUES ('Alice', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO users (name, created_at) VALUES ('Bob', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO users (name, created_at) VALUES ('Carol', '2026-01-01 00:00:00')");
        unset($pdo);

        $this->app = AppFactory::createSqlite($this->dbFile);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $headers */
    private function req(string $method, string $uri, mixed $body = null, array $headers = []): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')
                       ->withBody((new Psr17Factory())->createStream(
                           empty($body) ? '{}' : (json_encode($body) ?: '{}')
                       ));
        }
        return $this->app->handle($req);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        return (array) json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function postActivity(int $userId, string $type = 'post', string $summary = 'Hello', bool $isPublic = true): ResponseInterface
    {
        return $this->req('POST', "/users/{$userId}/activities", [
            'type' => $type,
            'summary' => $summary,
            'is_public' => $isPublic,
        ], ['X-User-Id' => (string) $userId]);
    }

    // --- authentication ---

    public function testGetFeedWithoutAuthReturns401(): void
    {
        $res = $this->req('GET', '/feed');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testPostActivityWithoutAuthReturns401(): void
    {
        $res = $this->req('POST', '/users/1/activities', ['type' => 'post', 'summary' => 'Hi']);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testFollowWithoutAuthReturns401(): void
    {
        $res = $this->req('POST', '/users/2/follow');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testUnfollowWithoutAuthReturns401(): void
    {
        $res = $this->req('DELETE', '/users/2/follow');
        $this->assertSame(401, $res->getStatusCode());
    }

    // --- post activity ---

    public function testPostActivityReturns201(): void
    {
        $res = $this->postActivity(1, 'post', 'Hello world');
        $body = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(1, $body['actor_id']);
        $this->assertSame('Alice', $body['actor_name']);
        $this->assertSame('post', $body['type']);
        $this->assertSame('Hello world', $body['summary']);
        $this->assertTrue($body['is_public']);
    }

    public function testPostActivityOtherUserReturns403(): void
    {
        $res = $this->req('POST', '/users/2/activities', [
            'type' => 'post', 'summary' => 'Hi',
        ], ['X-User-Id' => '1']);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testPostActivityInvalidTypeReturns422(): void
    {
        $res = $this->req('POST', '/users/1/activities', [
            'type' => 'invalid', 'summary' => 'Hi',
        ], ['X-User-Id' => '1']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testPostActivityEmptySummaryReturns422(): void
    {
        $res = $this->req('POST', '/users/1/activities', [
            'type' => 'post', 'summary' => '   ',
        ], ['X-User-Id' => '1']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testPostPrivateActivity(): void
    {
        $res = $this->postActivity(1, 'post', 'Private note', false);
        $body = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertFalse($body['is_public']);
    }

    public function testPostActivityWithObjectId(): void
    {
        $res = $this->req('POST', '/users/1/activities', [
            'type' => 'like',
            'summary' => 'Liked post',
            'object_id' => 42,
            'object_type' => 'post',
        ], ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(42, $body['object_id']);
        $this->assertSame('post', $body['object_type']);
    }

    // --- follow ---

    public function testFollowReturns201(): void
    {
        $res = $this->req('POST', '/users/2/follow', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(1, $body['follower_id']);
        $this->assertSame(2, $body['followee_id']);
        $this->assertTrue($body['following']);
    }

    public function testFollowIdempotentReturns200(): void
    {
        $this->req('POST', '/users/2/follow', null, ['X-User-Id' => '1']);
        $res = $this->req('POST', '/users/2/follow', null, ['X-User-Id' => '1']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($this->json($res)['following']);
    }

    public function testFollowSelfReturns422(): void
    {
        $res = $this->req('POST', '/users/1/follow', null, ['X-User-Id' => '1']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testFollowNonExistentUserReturns404(): void
    {
        $res = $this->req('POST', '/users/999/follow', null, ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- unfollow ---

    public function testUnfollowReturns204(): void
    {
        $this->req('POST', '/users/2/follow', null, ['X-User-Id' => '1']);
        $res = $this->req('DELETE', '/users/2/follow', null, ['X-User-Id' => '1']);
        $this->assertSame(204, $res->getStatusCode());
    }

    public function testUnfollowNotFollowingReturns404(): void
    {
        $res = $this->req('DELETE', '/users/2/follow', null, ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- get feed ---

    public function testGetFeedEmpty(): void
    {
        $res = $this->req('GET', '/feed', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([], $body['items']);
        $this->assertNull($body['next_cursor']);
    }

    public function testGetFeedShowsOwnActivities(): void
    {
        $this->postActivity(1, 'post', 'My post');
        $res = $this->req('GET', '/feed', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(1, $body['items']);
        $this->assertSame('My post', $body['items'][0]['summary']);
    }

    public function testGetFeedShowsFollowedUserActivities(): void
    {
        $this->req('POST', '/users/2/follow', null, ['X-User-Id' => '1']);
        $this->postActivity(2, 'post', 'Bob post');

        $res = $this->req('GET', '/feed', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertCount(1, $body['items']);
        $this->assertSame('Bob post', $body['items'][0]['summary']);
    }

    public function testGetFeedExcludesUnfollowedUsers(): void
    {
        $this->postActivity(2, 'post', "Bob's post");
        $res = $this->req('GET', '/feed', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame([], $body['items']);
    }

    public function testGetFeedExcludesPrivateActivities(): void
    {
        $this->req('POST', '/users/2/follow', null, ['X-User-Id' => '1']);
        $this->postActivity(2, 'post', 'Private', false);

        $res = $this->req('GET', '/feed', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame([], $body['items']);
    }

    public function testGetFeedCursorPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postActivity(1, 'post', "Post {$i}");
        }
        $res1 = $this->req('GET', '/feed?limit=3', null, ['X-User-Id' => '1']);
        $body1 = $this->json($res1);
        $this->assertCount(3, $body1['items']);
        $this->assertNotNull($body1['next_cursor']);

        $cursor = $body1['next_cursor'];
        $res2 = $this->req('GET', "/feed?limit=3&before_id={$cursor}", null, ['X-User-Id' => '1']);
        $body2 = $this->json($res2);
        $this->assertCount(2, $body2['items']);
        $this->assertNull($body2['next_cursor']);
    }

    // --- get user activities ---

    public function testGetUserActivitiesReturns200(): void
    {
        $this->postActivity(1, 'post', 'Hello');
        $res = $this->req('GET', '/users/1/activities', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(1, $body['items']);
    }

    public function testGetUserActivitiesNonOwnerSeesOnlyPublic(): void
    {
        $this->postActivity(1, 'post', 'Public');
        $this->postActivity(1, 'post', 'Private', false);

        $res = $this->req('GET', '/users/1/activities', null, ['X-User-Id' => '2']);
        $body = $this->json($res);
        $this->assertCount(1, $body['items']);
        $this->assertSame('Public', $body['items'][0]['summary']);
    }

    public function testGetUserActivitiesOwnerSeesAll(): void
    {
        $this->postActivity(1, 'post', 'Public');
        $this->postActivity(1, 'post', 'Private', false);

        $res = $this->req('GET', '/users/1/activities', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertCount(2, $body['items']);
    }

    public function testGetUserActivitiesNonExistentUserReturns404(): void
    {
        $res = $this->req('GET', '/users/999/activities', null, ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- infrastructure ---

    public function testSecurityHeadersPresent(): void
    {
        $res = $this->req('GET', '/feed', null, ['X-User-Id' => '1']);
        $this->assertNotEmpty($res->getHeaderLine('Content-Security-Policy'));
        $this->assertNotEmpty($res->getHeaderLine('X-Request-Id'));
    }

    public function testUnknownRouteReturns404(): void
    {
        $res = $this->req('GET', '/nonexistent');
        $this->assertSame(404, $res->getStatusCode());
    }
}
