<?php

declare(strict_types=1);

namespace Notification\Tests\Notification;

use Notification\AppFactory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class NotificationTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/notificationlog-' . bin2hex(random_bytes(8)) . '.sqlite';
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
            $stream = \Nyholm\Psr7\Stream::create((string) json_encode($body));
            $req    = $req->withBody($stream)->withHeader('Content-Type', 'application/json');
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

    // --- User creation ---

    public function testCreateUser(): void
    {
        $res  = $this->request('POST', '/users', ['name' => 'Alice']);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Alice', $body['name']);
        $this->assertIsInt($body['id']);
    }

    public function testCreateUserEmptyNameReturns422(): void
    {
        $res = $this->request('POST', '/users', ['name' => '']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateUserMissingNameReturns422(): void
    {
        $res = $this->request('POST', '/users', ['other' => 'value']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- Notification creation ---

    public function testCreateNotification(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/notifications", [
            'title' => 'Welcome!',
            'body'  => 'Thanks for joining.',
        ]);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Welcome!', $body['title']);
        $this->assertSame('Thanks for joining.', $body['body']);
        $this->assertFalse($body['read']);
        $this->assertNull($body['read_at']);
        $this->assertSame($userId, $body['user_id']);
    }

    public function testCreateNotificationUnknownUserReturns404(): void
    {
        $res = $this->request('POST', '/users/9999/notifications', [
            'title' => 'Hi',
            'body'  => 'Hello',
        ]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testCreateNotificationMissingTitleReturns422(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/notifications", ['body' => 'text']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateNotificationMissingBodyReturns422(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('POST', "/users/{$userId}/notifications", ['title' => 'Hi']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- List notifications ---

    public function testListNotificationsEmpty(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('GET', "/users/{$userId}/notifications");
        $body   = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([], $body['items']);
        $this->assertSame(0, $body['unread_count']);
    }

    public function testListNotificationsReturnsMostRecentFirst(): void
    {
        $userId = $this->createUser();
        $this->request('POST', "/users/{$userId}/notifications", ['title' => 'First', 'body' => 'A']);
        $this->request('POST', "/users/{$userId}/notifications", ['title' => 'Second', 'body' => 'B']);

        $res   = $this->request('GET', "/users/{$userId}/notifications");
        $body  = $this->json($res);
        $items = $body['items'];

        $this->assertCount(2, $items);
        $this->assertSame('Second', $items[0]['title']);
        $this->assertSame('First', $items[1]['title']);
    }

    public function testListNotificationsFilterUnreadOnly(): void
    {
        $userId = $this->createUser();
        $n1     = $this->request('POST', "/users/{$userId}/notifications", ['title' => 'A', 'body' => 'x']);
        $id1    = (int) $this->json($n1)['id'];
        $this->request('POST', "/users/{$userId}/notifications", ['title' => 'B', 'body' => 'y']);

        // Mark first as read
        $this->request('PATCH', "/users/{$userId}/notifications/{$id1}/read");

        $res   = $this->request('GET', "/users/{$userId}/notifications", null, ['unread' => 'true']);
        $body  = $this->json($res);
        $items = $body['items'];

        $this->assertCount(1, $items);
        $this->assertSame('B', $items[0]['title']);
    }

    public function testListNotificationsUnknownUserReturns404(): void
    {
        $res = $this->request('GET', '/users/9999/notifications');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Unread count ---

    public function testUnreadCount(): void
    {
        $userId = $this->createUser();
        $this->request('POST', "/users/{$userId}/notifications", ['title' => 'A', 'body' => 'x']);
        $this->request('POST', "/users/{$userId}/notifications", ['title' => 'B', 'body' => 'y']);

        $res  = $this->request('GET', "/users/{$userId}/notifications/unread-count");
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(2, $body['unread_count']);
    }

    public function testUnreadCountUnknownUserReturns404(): void
    {
        $res = $this->request('GET', '/users/9999/notifications/unread-count');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Mark as read ---

    public function testMarkAsRead(): void
    {
        $userId = $this->createUser();
        $n      = $this->request('POST', "/users/{$userId}/notifications", ['title' => 'Hi', 'body' => 'msg']);
        $id     = (int) $this->json($n)['id'];

        $res  = $this->request('PATCH', "/users/{$userId}/notifications/{$id}/read");
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($body['read']);
        $this->assertNotNull($body['read_at']);
    }

    public function testMarkAsReadIdempotent(): void
    {
        $userId = $this->createUser();
        $n      = $this->request('POST', "/users/{$userId}/notifications", ['title' => 'Hi', 'body' => 'msg']);
        $id     = (int) $this->json($n)['id'];

        $res1 = $this->request('PATCH', "/users/{$userId}/notifications/{$id}/read");
        $res2 = $this->request('PATCH', "/users/{$userId}/notifications/{$id}/read");

        $this->assertSame(200, $res1->getStatusCode());
        $this->assertSame(200, $res2->getStatusCode());
        $this->assertSame($this->json($res1)['read_at'], $this->json($res2)['read_at']);
    }

    public function testMarkAsReadUnknownNotificationReturns404(): void
    {
        $userId = $this->createUser();
        $res    = $this->request('PATCH', "/users/{$userId}/notifications/9999/read");
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testMarkAsReadWrongUserReturns404(): void
    {
        $userId1 = $this->createUser('Alice');
        $userId2 = $this->createUser('Bob');
        $n       = $this->request('POST', "/users/{$userId1}/notifications", ['title' => 'Hi', 'body' => 'msg']);
        $id      = (int) $this->json($n)['id'];

        // Bob cannot mark Alice's notification as read
        $res = $this->request('PATCH', "/users/{$userId2}/notifications/{$id}/read");
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testMarkAsReadDecrementsUnreadCount(): void
    {
        $userId = $this->createUser();
        $n      = $this->request('POST', "/users/{$userId}/notifications", ['title' => 'A', 'body' => 'x']);
        $this->request('POST', "/users/{$userId}/notifications", ['title' => 'B', 'body' => 'y']);
        $id = (int) $this->json($n)['id'];

        $before = $this->json($this->request('GET', "/users/{$userId}/notifications/unread-count"))['unread_count'];
        $this->request('PATCH', "/users/{$userId}/notifications/{$id}/read");
        $after = $this->json($this->request('GET', "/users/{$userId}/notifications/unread-count"))['unread_count'];

        $this->assertSame(2, $before);
        $this->assertSame(1, $after);
    }

    // --- Mark all as read ---

    public function testMarkAllAsRead(): void
    {
        $userId = $this->createUser();
        $this->request('POST', "/users/{$userId}/notifications", ['title' => 'A', 'body' => 'x']);
        $this->request('POST', "/users/{$userId}/notifications", ['title' => 'B', 'body' => 'y']);

        $res  = $this->request('POST', "/users/{$userId}/notifications/read-all");
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, $body['unread_count']);
    }

    public function testMarkAllAsReadIsIdempotent(): void
    {
        $userId = $this->createUser();
        $this->request('POST', "/users/{$userId}/notifications", ['title' => 'A', 'body' => 'x']);

        $this->request('POST', "/users/{$userId}/notifications/read-all");
        $res  = $this->request('POST', "/users/{$userId}/notifications/read-all");
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, $body['unread_count']);
    }

    public function testMarkAllAsReadOnlyAffectsTargetUser(): void
    {
        $userId1 = $this->createUser('Alice');
        $userId2 = $this->createUser('Bob');
        $this->request('POST', "/users/{$userId1}/notifications", ['title' => 'A', 'body' => 'x']);
        $this->request('POST', "/users/{$userId2}/notifications", ['title' => 'B', 'body' => 'y']);

        $this->request('POST', "/users/{$userId1}/notifications/read-all");

        $count2 = $this->json($this->request('GET', "/users/{$userId2}/notifications/unread-count"))['unread_count'];
        $this->assertSame(1, $count2);
    }

    public function testMarkAllAsReadUnknownUserReturns404(): void
    {
        $res = $this->request('POST', '/users/9999/notifications/read-all');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Cross-user isolation ---

    public function testNotificationsAreIsolatedPerUser(): void
    {
        $userId1 = $this->createUser('Alice');
        $userId2 = $this->createUser('Bob');

        $this->request('POST', "/users/{$userId1}/notifications", ['title' => 'For Alice', 'body' => 'a']);
        $this->request('POST', "/users/{$userId2}/notifications", ['title' => 'For Bob', 'body' => 'b']);

        $res1  = $this->request('GET', "/users/{$userId1}/notifications");
        $res2  = $this->request('GET', "/users/{$userId2}/notifications");
        $items1 = $this->json($res1)['items'];
        $items2 = $this->json($res2)['items'];

        $this->assertCount(1, $items1);
        $this->assertCount(1, $items2);
        $this->assertSame('For Alice', $items1[0]['title']);
        $this->assertSame('For Bob', $items2[0]['title']);
    }
}
