<?php

declare(strict_types=1);

namespace FeedLog\Tests\Feed;

use FeedLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * MySQL 統合テスト — FT153 feedlog
 *
 * MYSQL_HOST 環境変数が設定されていない場合はスキップ。
 * 実行: docker run --rm --network nene2-ft_default \
 *   -v /home/xi/docker/NENE2-FT/feedlog:/app -w /app \
 *   -e MYSQL_HOST=mysql -e MYSQL_DB=feedlog_test \
 *   -e MYSQL_USER=nene2 -e MYSQL_PASSWORD=secret \
 *   nene2-app php vendor/bin/phpunit tests/Feed/MysqlFeedTest.php
 */
final class MysqlFeedTest extends TestCase
{
    private RequestHandlerInterface $app;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $host = getenv('MYSQL_HOST') ?: '';
        if ($host === '') {
            $this->markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests.');
        }

        $db = getenv('MYSQL_DB') ?: 'feedlog_test';
        $user = getenv('MYSQL_USER') ?: 'nene2';
        $password = getenv('MYSQL_PASSWORD') ?: 'secret';
        $port = (int) (getenv('MYSQL_PORT') ?: 3306);

        $this->pdo = new \PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $password);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create tables
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.mysql.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            $this->pdo->exec($stmt);
        }

        // Seed users
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Alice', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Bob', '2026-01-01 00:00:00')");

        $this->app = AppFactory::createMysql($host, $db, $user, $password, $port);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS activities');
            $this->pdo->exec('DROP TABLE IF EXISTS follows');
            $this->pdo->exec('DROP TABLE IF EXISTS users');
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

    public function testMysqlPostActivityAndFeed(): void
    {
        $res = $this->req('POST', '/users/1/activities', [
            'type' => 'post', 'summary' => 'MySQL post',
        ], ['X-User-Id' => '1']);
        $this->assertSame(201, $res->getStatusCode());

        $feed = $this->req('GET', '/feed', null, ['X-User-Id' => '1']);
        $body = $this->json($feed);
        $this->assertSame(200, $feed->getStatusCode());
        $this->assertCount(1, $body['items']);
        $this->assertSame('MySQL post', $body['items'][0]['summary']);
    }

    public function testMysqlFollowAndFeed(): void
    {
        $this->req('POST', '/users/2/follow', null, ['X-User-Id' => '1']);
        $this->req('POST', '/users/2/activities', [
            'type' => 'post', 'summary' => "Bob's MySQL post",
        ], ['X-User-Id' => '2']);

        $feed = $this->req('GET', '/feed', null, ['X-User-Id' => '1']);
        $body = $this->json($feed);
        $this->assertCount(1, $body['items']);
        $this->assertSame("Bob's MySQL post", $body['items'][0]['summary']);
    }

    public function testMysqlCursorPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->req('POST', '/users/1/activities', [
                'type' => 'post', 'summary' => "Post {$i}",
            ], ['X-User-Id' => '1']);
        }

        $res1 = $this->req('GET', '/feed?limit=3', null, ['X-User-Id' => '1']);
        $body1 = $this->json($res1);
        $this->assertCount(3, $body1['items']);
        $this->assertNotNull($body1['next_cursor']);

        $cursor = $body1['next_cursor'];
        $res2 = $this->req('GET', "/feed?limit=3&before_id={$cursor}", null, ['X-User-Id' => '1']);
        $body2 = $this->json($res2);
        $this->assertCount(2, $body2['items']);
    }

    public function testMysqlPrivateActivityNotVisibleToOthers(): void
    {
        $this->req('POST', '/users/1/activities', [
            'type' => 'post', 'summary' => 'Secret', 'is_public' => false,
        ], ['X-User-Id' => '1']);

        $res = $this->req('GET', '/users/1/activities', null, ['X-User-Id' => '2']);
        $body = $this->json($res);
        $this->assertSame([], $body['items']);
    }

    public function testMysqlUnfollowRemovesFromFeed(): void
    {
        $this->req('POST', '/users/2/follow', null, ['X-User-Id' => '1']);
        $this->req('POST', '/users/2/activities', [
            'type' => 'post', 'summary' => 'Will disappear',
        ], ['X-User-Id' => '2']);
        $this->req('DELETE', '/users/2/follow', null, ['X-User-Id' => '1']);

        // After unfollow, Bob's activity should not be in Alice's feed
        // (new activities after unfollow won't appear; existing ones won't either)
        $res = $this->req('GET', '/feed', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame([], $body['items']);
    }
}
