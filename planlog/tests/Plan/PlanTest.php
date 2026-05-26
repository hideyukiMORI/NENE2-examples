<?php

declare(strict_types=1);

namespace Plan\Tests\Plan;

use Plan\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PlanTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/planlog-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $res = $this->request('POST', '/users', ['name' => $name]);
        $this->assertSame(201, $res->getStatusCode());

        return (int) $this->json($res)['id'];
    }

    private function subscribe(int $userId, string $plan = 'free'): void
    {
        $res = $this->request('POST', "/users/{$userId}/subscription", ['plan' => $plan], actorId: (string) $userId);
        $this->assertSame(201, $res->getStatusCode());
    }

    // --- Plans list ---

    public function testListPlans(): void
    {
        $res  = $this->request('GET', '/plans');
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(3, $body['count']);
        $slugs = array_column($body['items'], 'slug');
        $this->assertContains('free', $slugs);
        $this->assertContains('pro', $slugs);
        $this->assertContains('enterprise', $slugs);
    }

    public function testPlansOrderedByPrice(): void
    {
        $res   = $this->request('GET', '/plans');
        $items = $this->json($res)['items'];

        $this->assertSame('free', $items[0]['slug']);
        $this->assertSame('pro', $items[1]['slug']);
        $this->assertSame('enterprise', $items[2]['slug']);
    }

    // --- Subscribe ---

    public function testSubscribeToFree(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', "/users/{$alice}/subscription", ['plan' => 'free'], actorId: (string) $alice);
        $body  = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('free', $body['plan_slug']);
        $this->assertSame('active', $body['status']);
        $this->assertNull($body['cancelled_at']);
    }

    public function testSubscribeToPro(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', "/users/{$alice}/subscription", ['plan' => 'pro'], actorId: (string) $alice);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('pro', $this->json($res)['plan_slug']);
    }

    public function testSubscribeAlreadySubscribedReturns409(): void
    {
        $alice = $this->createUser('Alice');
        $this->subscribe($alice);

        $res = $this->request('POST', "/users/{$alice}/subscription", ['plan' => 'pro'], actorId: (string) $alice);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testSubscribeUnknownPlanReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('POST', "/users/{$alice}/subscription", ['plan' => 'platinum'], actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testSubscribeOtherUserReturns403(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');

        $res = $this->request('POST', "/users/{$alice}/subscription", ['plan' => 'free'], actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    // --- Get subscription ---

    public function testGetSubscription(): void
    {
        $alice = $this->createUser('Alice');
        $this->subscribe($alice, 'pro');

        $res  = $this->request('GET', "/users/{$alice}/subscription", actorId: (string) $alice);
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('pro', $body['plan_slug']);
        $this->assertSame('active', $body['status']);
    }

    public function testGetSubscriptionNoSubscriptionReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('GET', "/users/{$alice}/subscription", actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGetSubscriptionOtherUserReturns403(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $this->subscribe($alice);

        $res = $this->request('GET', "/users/{$alice}/subscription", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    // --- Change plan ---

    public function testChangePlan(): void
    {
        $alice = $this->createUser('Alice');
        $this->subscribe($alice, 'free');

        $res  = $this->request('PUT', "/users/{$alice}/subscription", ['plan' => 'pro'], actorId: (string) $alice);
        $body = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('pro', $body['plan_slug']);
        $this->assertSame('active', $body['status']);
    }

    public function testDowngradePlan(): void
    {
        $alice = $this->createUser('Alice');
        $this->subscribe($alice, 'enterprise');

        $res = $this->request('PUT', "/users/{$alice}/subscription", ['plan' => 'free'], actorId: (string) $alice);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('free', $this->json($res)['plan_slug']);
    }

    public function testChangePlanNoSubscriptionReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('PUT', "/users/{$alice}/subscription", ['plan' => 'pro'], actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testChangePlanOnCancelledReturns409(): void
    {
        $alice = $this->createUser('Alice');
        $this->subscribe($alice, 'free');
        $this->request('DELETE', "/users/{$alice}/subscription", actorId: (string) $alice);

        $res = $this->request('PUT', "/users/{$alice}/subscription", ['plan' => 'pro'], actorId: (string) $alice);
        $this->assertSame(409, $res->getStatusCode());
    }

    // --- Cancel ---

    public function testCancelSubscription(): void
    {
        $alice = $this->createUser('Alice');
        $this->subscribe($alice, 'pro');

        $res = $this->request('DELETE', "/users/{$alice}/subscription", actorId: (string) $alice);
        $this->assertSame(204, $res->getStatusCode());
    }

    public function testCancelledSubscriptionShowsCancelledStatus(): void
    {
        $alice = $this->createUser('Alice');
        $this->subscribe($alice, 'pro');
        $this->request('DELETE', "/users/{$alice}/subscription", actorId: (string) $alice);

        $sub = $this->json($this->request('GET', "/users/{$alice}/subscription", actorId: (string) $alice));
        $this->assertSame('cancelled', $sub['status']);
        $this->assertNotNull($sub['cancelled_at']);
    }

    public function testCancelAlreadyCancelledReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $this->subscribe($alice);
        $this->request('DELETE', "/users/{$alice}/subscription", actorId: (string) $alice);

        $res = $this->request('DELETE', "/users/{$alice}/subscription", actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testCancelNoSubscriptionReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('DELETE', "/users/{$alice}/subscription", actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Re-subscribe after cancel ---

    public function testReSubscribeAfterCancel(): void
    {
        $alice = $this->createUser('Alice');
        $this->subscribe($alice, 'pro');
        $this->request('DELETE', "/users/{$alice}/subscription", actorId: (string) $alice);

        $res = $this->request('POST', "/users/{$alice}/subscription", ['plan' => 'enterprise'], actorId: (string) $alice);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('enterprise', $this->json($res)['plan_slug']);
    }

    // --- Isolation ---

    public function testSubscriptionsArePerUser(): void
    {
        $alice = $this->createUser('Alice');
        $bob   = $this->createUser('Bob');
        $this->subscribe($alice, 'pro');

        $res = $this->request('GET', "/users/{$bob}/subscription", actorId: (string) $bob);
        $this->assertSame(404, $res->getStatusCode());
    }
}
