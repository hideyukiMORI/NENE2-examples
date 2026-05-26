<?php

declare(strict_types=1);

namespace Csrf\Tests\Order;

use Csrf\Order\RouteRegistrar;
use Csrf\Order\SqliteOrderRepository;
use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CsrfIdempotencyTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/csrflog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig  = new DatabaseConfig(
            url:         null,
            environment: 'test',
            adapter:     'sqlite',
            host:        '',
            port:        1,
            name:        $this->dbFile,
            user:        '',
            password:    '',
            charset:     '',
        );
        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $repo      = new SqliteOrderRepository($executor);
        $registrar = new RouteRegistrar($repo, $json, $problems);

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
        ))->create();
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // --- helpers ---

    /** @param array<string, string> $headers */
    private function post(string $path, mixed $body, array $headers = []): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('POST', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($request);
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle(new ServerRequest('GET', $path));
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function jsonList(ResponseInterface $response): array
    {
        /** @var list<array<string, mixed>> */
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }

    // --- happy path ---

    public function testCreateOrderWithIdempotencyKey(): void
    {
        $key = $this->uuid();
        $res = $this->post('/orders', [
            'item'     => 'Coffee',
            'quantity' => 2,
            'price'    => 3.50,
        ], ['Idempotency-Key' => $key]);

        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('Coffee', $data['item']);
        $this->assertSame(7.0, $data['total_price']); // 2 × 3.50
        $this->assertSame($key, $data['idempotency_key']);
    }

    public function testListOrders(): void
    {
        $this->post('/orders', ['item' => 'Coffee', 'quantity' => 1, 'price' => 3.0], ['Idempotency-Key' => $this->uuid()]);
        $this->post('/orders', ['item' => 'Tea', 'quantity' => 1, 'price' => 2.0], ['Idempotency-Key' => $this->uuid()]);

        $res = $this->get('/orders');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $this->jsonList($res));
    }

    public function testGetOrder(): void
    {
        $res = $this->post('/orders', ['item' => 'Cake', 'quantity' => 1, 'price' => 5.0], ['Idempotency-Key' => $this->uuid()]);
        $id  = (int) $this->json($res)['id'];

        $get = $this->get("/orders/{$id}");
        $this->assertSame(200, $get->getStatusCode());
        $this->assertSame('Cake', $this->json($get)['item']);
    }

    // --- idempotency (double-submit prevention) ---

    /** Sending the same request twice returns the original order, not a duplicate */
    public function testIdempotentReplayReturnsSameOrder(): void
    {
        $key  = $this->uuid();
        $body = ['item' => 'Widget', 'quantity' => 1, 'price' => 9.99];

        $res1 = $this->post('/orders', $body, ['Idempotency-Key' => $key]);
        $res2 = $this->post('/orders', $body, ['Idempotency-Key' => $key]);

        $this->assertSame(201, $res1->getStatusCode());
        $this->assertSame(200, $res2->getStatusCode()); // replay returns 200

        // Same order — same ID
        $this->assertSame($this->json($res1)['id'], $this->json($res2)['id']);

        // Only one order in DB
        $this->assertCount(1, $this->jsonList($this->get('/orders')));
    }

    /** Same key with different body still returns the original order */
    public function testIdempotentReplayIgnoresBodyChanges(): void
    {
        $key = $this->uuid();

        $res1 = $this->post('/orders', ['item' => 'Widget', 'quantity' => 1, 'price' => 9.99], ['Idempotency-Key' => $key]);
        // Attacker/retry changes quantity — should not create new order
        $res2 = $this->post('/orders', ['item' => 'Widget', 'quantity' => 99, 'price' => 0.01], ['Idempotency-Key' => $key]);

        $this->assertSame(201, $res1->getStatusCode());
        $this->assertSame(200, $res2->getStatusCode());

        // Original quantity preserved
        $this->assertSame(1, (int) $this->json($res2)['quantity']);
        $this->assertCount(1, $this->jsonList($this->get('/orders')));
    }

    /** Different keys create separate orders */
    public function testDifferentKeysCreateSeparateOrders(): void
    {
        $body = ['item' => 'Coffee', 'quantity' => 1, 'price' => 3.0];

        $res1 = $this->post('/orders', $body, ['Idempotency-Key' => $this->uuid()]);
        $res2 = $this->post('/orders', $body, ['Idempotency-Key' => $this->uuid()]);

        $this->assertSame(201, $res1->getStatusCode());
        $this->assertSame(201, $res2->getStatusCode());
        $this->assertNotSame($this->json($res1)['id'], $this->json($res2)['id']);
        $this->assertCount(2, $this->jsonList($this->get('/orders')));
    }

    // --- missing Idempotency-Key ---

    public function testMissingIdempotencyKeyReturns422(): void
    {
        $res = $this->post('/orders', ['item' => 'Coffee', 'quantity' => 1, 'price' => 3.0]);
        $this->assertSame(422, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('missing-idempotency-key', (string) ($data['type'] ?? ''));
    }

    public function testEmptyIdempotencyKeyReturns422(): void
    {
        $res = $this->post('/orders', ['item' => 'Coffee', 'quantity' => 1, 'price' => 3.0], ['Idempotency-Key' => '   ']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- input validation ---

    public function testMissingItemReturns422(): void
    {
        $res = $this->post('/orders', ['quantity' => 1, 'price' => 3.0], ['Idempotency-Key' => $this->uuid()]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testNegativeQuantityReturns422(): void
    {
        $res = $this->post('/orders', ['item' => 'X', 'quantity' => -1, 'price' => 1.0], ['Idempotency-Key' => $this->uuid()]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testZeroQuantityReturns422(): void
    {
        $res = $this->post('/orders', ['item' => 'X', 'quantity' => 0, 'price' => 1.0], ['Idempotency-Key' => $this->uuid()]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- CSRF / Origin behavior ---

    /**
     * JSON APIs with Content-Type: application/json are not vulnerable to
     * classic HTML form CSRF because browsers send simple requests with
     * application/x-www-form-urlencoded, not JSON.
     *
     * A request without Origin header (e.g., from curl or server-to-server)
     * is NOT blocked — NENE2's CorsMiddleware only adds CORS headers when
     * Origin is present; it does not enforce origin allowlists for non-browser requests.
     */
    public function testRequestWithoutOriginHeaderIsAllowed(): void
    {
        // No Origin header — simulates curl or server-to-server call
        $res = $this->post('/orders', ['item' => 'Tool', 'quantity' => 1, 'price' => 10.0], ['Idempotency-Key' => $this->uuid()]);
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testRequestWithKnownOriginIsAllowed(): void
    {
        $res = $this->post('/orders', ['item' => 'Tool', 'quantity' => 1, 'price' => 10.0], [
            'Idempotency-Key' => $this->uuid(),
            'Origin'          => 'https://app.example.com',
        ]);
        // CorsMiddleware is configured with no allowedOrigins in test — passes through
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testRequestWithUnknownOriginIsNotBlockedByDefault(): void
    {
        // Without explicit CorsMiddleware allowlist configuration,
        // NENE2 does not block unknown origins — CORS headers just won't be set.
        // Application-level Origin enforcement requires custom middleware.
        $res = $this->post('/orders', ['item' => 'Hacked', 'quantity' => 1, 'price' => 0.01], [
            'Idempotency-Key' => $this->uuid(),
            'Origin'          => 'https://evil.example.com',
        ]);
        // No block — documents the "CORS ≠ CSRF protection" finding
        $this->assertSame(201, $res->getStatusCode());
    }

    // --- not found ---

    public function testGetNonExistentOrderReturns404(): void
    {
        $res = $this->get('/orders/99999');
        $this->assertSame(404, $res->getStatusCode());
    }
}
