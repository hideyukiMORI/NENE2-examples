<?php

declare(strict_types=1);

namespace Bookmark\Tests\Bookmark;

use Bookmark\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BookmarkTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/bookmarklog-' . bin2hex(random_bytes(8)) . '.sqlite';
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

    // --- Add bookmark ---

    public function testAddBookmark(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $res  = $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId]);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame($userId, $body['user_id']);
        $this->assertSame($itemId, $body['item_id']);
        $this->assertSame('default', $body['collection']);
    }

    public function testAddBookmarkWithCollection(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $res  = $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId, 'collection' => 'reading']);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('reading', $body['collection']);
    }

    public function testAddBookmarkIdempotent(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $res1 = $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId]);
        $res2 = $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId]);

        $this->assertSame(201, $res1->getStatusCode());
        $this->assertSame(201, $res2->getStatusCode());
        $this->assertSame($this->json($res1)['id'], $this->json($res2)['id']);
    }

    public function testAddBookmarkUnknownUserReturns404(): void
    {
        $itemId = $this->createItem();
        $res    = $this->request('POST', '/users/9999/bookmarks', ['item_id' => $itemId]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testAddBookmarkUnknownItemReturns404(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => 9999]);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Remove bookmark ---

    public function testRemoveBookmark(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId]);
        $res = $this->request('DELETE', "/users/{$userId}/bookmarks/{$itemId}");

        $this->assertSame(204, $res->getStatusCode());
    }

    public function testRemoveBookmarkNotFoundReturns404(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $res = $this->request('DELETE', "/users/{$userId}/bookmarks/{$itemId}");
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testRemoveBookmarkThenReAdd(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId]);
        $this->request('DELETE', "/users/{$userId}/bookmarks/{$itemId}");
        $res = $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId]);

        $this->assertSame(201, $res->getStatusCode());
    }

    // --- List bookmarks ---

    public function testListBookmarksEmpty(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('GET', "/users/{$userId}/bookmarks");
        $body   = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([], $body['items']);
        $this->assertSame(0, $body['count']);
    }

    public function testListBookmarksNewestFirst(): void
    {
        $userId  = $this->createUser();
        $itemId1 = $this->createItem('First');
        $itemId2 = $this->createItem('Second');

        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId1]);
        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId2]);

        $res   = $this->request('GET', "/users/{$userId}/bookmarks");
        $items = $this->json($res)['items'];

        $this->assertCount(2, $items);
        $this->assertSame($itemId2, $items[0]['item_id']);
        $this->assertSame($itemId1, $items[1]['item_id']);
    }

    public function testListBookmarksByCollection(): void
    {
        $userId  = $this->createUser();
        $itemId1 = $this->createItem('A');
        $itemId2 = $this->createItem('B');

        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId1, 'collection' => 'reading']);
        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId2, 'collection' => 'favorites']);

        $res   = $this->request('GET', "/users/{$userId}/bookmarks", null, ['collection' => 'reading']);
        $items = $this->json($res)['items'];

        $this->assertCount(1, $items);
        $this->assertSame($itemId1, $items[0]['item_id']);
    }

    public function testListBookmarksUnknownUserReturns404(): void
    {
        $res = $this->request('GET', '/users/9999/bookmarks');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Count ---

    public function testCountBookmarks(): void
    {
        $userId  = $this->createUser();
        $itemId1 = $this->createItem('A');
        $itemId2 = $this->createItem('B');

        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId1]);
        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId2]);

        $res  = $this->request('GET', "/users/{$userId}/bookmarks/count");
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(2, $body['count']);
    }

    public function testCountDecreasesAfterRemove(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId]);
        $this->assertSame(1, $this->json($this->request('GET', "/users/{$userId}/bookmarks/count"))['count']);

        $this->request('DELETE', "/users/{$userId}/bookmarks/{$itemId}");
        $this->assertSame(0, $this->json($this->request('GET', "/users/{$userId}/bookmarks/count"))['count']);
    }

    // --- Get single bookmark ---

    public function testGetBookmark(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $this->request('POST', "/users/{$userId}/bookmarks", ['item_id' => $itemId, 'collection' => 'later']);
        $res  = $this->request('GET', "/users/{$userId}/bookmarks/{$itemId}");
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('later', $body['collection']);
    }

    public function testGetBookmarkNotFoundReturns404(): void
    {
        $userId = $this->createUser();
        $itemId = $this->createItem();

        $res = $this->request('GET', "/users/{$userId}/bookmarks/{$itemId}");
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- User isolation ---

    public function testBookmarksIsolatedPerUser(): void
    {
        $userId1 = $this->createUser('Alice');
        $userId2 = $this->createUser('Bob');
        $itemId  = $this->createItem();

        $this->request('POST', "/users/{$userId1}/bookmarks", ['item_id' => $itemId]);

        $count2 = $this->json($this->request('GET', "/users/{$userId2}/bookmarks/count"))['count'];
        $this->assertSame(0, $count2);
    }
}
