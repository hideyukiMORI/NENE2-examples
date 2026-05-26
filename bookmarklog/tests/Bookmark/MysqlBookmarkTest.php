<?php

declare(strict_types=1);

namespace Bookmark\Tests\Bookmark;

use Bookmark\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * MySQL integration tests for FT133.
 * Skipped automatically when MYSQL_HOST is not set.
 */
final class MysqlBookmarkTest extends TestCase
{
    private bool $mysqlEnabled = false;
    private ?\PDO $pdo         = null;
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $host = (string) (getenv('MYSQL_HOST') ?: '');

        if ($host === '') {
            self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
        }

        $this->mysqlEnabled = true;
        $port               = (int) (getenv('MYSQL_PORT') ?: 3306);
        $database           = (string) (getenv('MYSQL_DATABASE') ?: 'ft_test');
        $user               = (string) (getenv('MYSQL_USER') ?: 'ft_user');
        $password           = (string) (getenv('MYSQL_PASSWORD') ?: 'ft_pass');

        $dsn       = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $this->pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        // Recreate tables for isolation (ft_user lacks CREATE DATABASE privilege)
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
        $this->pdo->exec('DROP TABLE IF EXISTS items');
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.mysql.sql');
        $this->pdo->exec($schema);

        $this->app = AppFactory::createMysqlApp($host, $port, $database, $user, $password);
    }

    protected function tearDown(): void
    {
        if ($this->mysqlEnabled && $this->pdo !== null) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
            $this->pdo->exec('DROP TABLE IF EXISTS items');
            $this->pdo->exec('DROP TABLE IF EXISTS users');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
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

    public function testMysqlAddAndListBookmarks(): void
    {
        $userId  = $this->createUser();
        $itemId1 = $this->createItem('Article 1');
        $itemId2 = $this->createItem('Article 2');

        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId1, 'collection' => 'reading']);
        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId2, 'collection' => 'reading']);

        $res   = $this->request('GET', "/users/{$userId}/bookmarks");
        $body  = $this->json($res);
        $items = $body['items'];

        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $items);
        $this->assertSame(2, $body['count']);
    }

    public function testMysqlBookmarkIdempotent(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $res1 = $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId]);
        $res2 = $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId]);

        $this->assertSame(201, $res1->getStatusCode());
        $this->assertSame(201, $res2->getStatusCode());
        $this->assertSame($this->json($res1)['id'], $this->json($res2)['id']);
    }

    public function testMysqlRemoveBookmark(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId]);
        $del = $this->request('DELETE', "/users/{$userId}/bookmarks/{$itemId}");

        $this->assertSame(204, $del->getStatusCode());
        $this->assertSame(0, $this->json($this->request('GET', "/users/{$userId}/bookmarks/count"))['count']);
    }

    public function testMysqlCollectionFilter(): void
    {
        $userId  = $this->createUser();
        $itemId1 = $this->createItem('A');
        $itemId2 = $this->createItem('B');

        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId1, 'collection' => 'favorites']);
        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId2, 'collection' => 'reading']);

        $res   = $this->request('GET', "/users/{$userId}/bookmarks", null, ['collection' => 'favorites']);
        $items = $this->json($res)['items'];

        $this->assertCount(1, $items);
        $this->assertSame('favorites', $items[0]['collection']);
    }

    public function testMysqlUserIsolation(): void
    {
        $userId1 = $this->createUser('Alice');
        $userId2 = $this->createUser('Bob');
        $itemId  = $this->createItem();

        $this->request('POST', "/users/{$userId1}/bookmarks", ['item_id' => $itemId]);

        $count2 = $this->json($this->request('GET', "/users/{$userId2}/bookmarks/count"))['count'];
        $this->assertSame(0, $count2);
    }
}
