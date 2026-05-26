<?php

declare(strict_types=1);

namespace PointLog\Tests\Point;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use PointLog\Point\PointRepository;
use PointLog\Point\RouteRegistrar;

class PointTest extends TestCase
{
    private \PDO $pdo;
    private Router $router;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));

        $now = date('c');
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Admin', 'admin', '$now')");
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Alice', 'user', '$now')");
        $this->pdo->exec("INSERT INTO users (name, role, created_at) VALUES ('Bob', 'user', '$now')");

        $this->psr17 = new Psr17Factory();
        $this->router = $this->buildRouter($this->pdo);
    }

    private function buildRouter(\PDO $pdo): Router
    {
        $factory = new class ($pdo) implements DatabaseConnectionFactoryInterface {
            public function __construct(private readonly \PDO $pdo)
            {
            }
            public function create(): \PDO
            {
                return $this->pdo;
            }
        };
        $executor = new PdoDatabaseQueryExecutor($factory);
        $repository = new PointRepository($executor);
        $psr17 = new Psr17Factory();
        $responseFactory = new JsonResponseFactory($psr17, $psr17);
        $router = new Router();
        $registrar = new RouteRegistrar($router, $repository, $responseFactory);
        $registrar->register();
        return $router;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function post(string $path, mixed $body = [], array $headers = []): array
    {
        $allHeaders = array_merge(['Content-Type' => 'application/json'], $headers);
        $request = new ServerRequest('POST', $path, $allHeaders);
        $json = (is_array($body) && empty($body)) ? '{}' : (json_encode($body) ?: '{}');
        $request = $request->withBody($this->psr17->createStream($json));
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function get(string $path, array $headers = []): array
    {
        $request = new ServerRequest('GET', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    public function testGetBalance_initial_returns0(): void
    {
        $result = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['body']['user_id']);
        $this->assertSame(0, $result['body']['balance']);
    }

    public function testGetBalance_noAuth_returns401(): void
    {
        $result = $this->get('/users/2/points');
        $this->assertSame(401, $result['status']);
    }

    public function testGetBalance_otherUser_returns403(): void
    {
        $result = $this->get('/users/2/points', ['X-User-Id' => '3']);
        $this->assertSame(403, $result['status']);
    }

    public function testGetBalance_adminCanSeeOthers(): void
    {
        $result = $this->get('/users/2/points', ['X-User-Id' => '1', 'X-User-Role' => 'admin']);
        $this->assertSame(200, $result['status']);
    }

    public function testEarnPoints_returns201(): void
    {
        $result = $this->post('/users/2/points/earn', [
            'amount' => 100,
            'description' => 'Purchase reward',
        ], ['X-User-Id' => '2']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('earn', $result['body']['type']);
        $this->assertSame(100, $result['body']['amount']);
        $this->assertSame(100, $result['body']['balance_after']);
    }

    public function testEarnPoints_updatesBalance(): void
    {
        $this->post('/users/2/points/earn', ['amount' => 100], ['X-User-Id' => '2']);
        $this->post('/users/2/points/earn', ['amount' => 50], ['X-User-Id' => '2']);

        $result = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(150, $result['body']['balance']);
    }

    public function testEarnPoints_noAuth_returns401(): void
    {
        $result = $this->post('/users/2/points/earn', ['amount' => 100]);
        $this->assertSame(401, $result['status']);
    }

    public function testEarnPoints_zeroAmount_returns422(): void
    {
        $result = $this->post('/users/2/points/earn', ['amount' => 0], ['X-User-Id' => '2']);
        $this->assertSame(422, $result['status']);
    }

    public function testEarnPoints_negativeAmount_returns422(): void
    {
        $result = $this->post('/users/2/points/earn', ['amount' => -100], ['X-User-Id' => '2']);
        $this->assertSame(422, $result['status']);
    }

    public function testEarnPoints_idempotentWithReferenceId(): void
    {
        $r1 = $this->post('/users/2/points/earn', ['amount' => 100, 'reference_id' => 'order-123'], ['X-User-Id' => '2']);
        $r2 = $this->post('/users/2/points/earn', ['amount' => 100, 'reference_id' => 'order-123'], ['X-User-Id' => '2']);

        $this->assertSame(201, $r1['status']);
        $this->assertSame(200, $r2['status']);
        $this->assertSame($r1['body']['id'], $r2['body']['id']);

        $balance = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(100, $balance['body']['balance'], 'Idempotent earn must not double-credit');
    }

    public function testSpendPoints_returns201(): void
    {
        $this->post('/users/2/points/earn', ['amount' => 500], ['X-User-Id' => '2']);
        $result = $this->post('/users/2/points/spend', [
            'amount' => 200,
            'description' => 'Discount applied',
        ], ['X-User-Id' => '2']);

        $this->assertSame(201, $result['status']);
        $this->assertSame('spend', $result['body']['type']);
        $this->assertSame(200, $result['body']['amount']);
        $this->assertSame(300, $result['body']['balance_after']);
    }

    public function testSpendPoints_insufficientBalance_returns422(): void
    {
        $this->post('/users/2/points/earn', ['amount' => 100], ['X-User-Id' => '2']);
        $result = $this->post('/users/2/points/spend', ['amount' => 200], ['X-User-Id' => '2']);

        $this->assertSame(422, $result['status']);
        $this->assertSame(100, $result['body']['balance']);
        $this->assertSame(200, $result['body']['required']);
    }

    public function testSpendPoints_exactBalance_succeeds(): void
    {
        $this->post('/users/2/points/earn', ['amount' => 100], ['X-User-Id' => '2']);
        $result = $this->post('/users/2/points/spend', ['amount' => 100], ['X-User-Id' => '2']);

        $this->assertSame(201, $result['status']);
        $this->assertSame(0, $result['body']['balance_after']);
    }

    public function testSpendPoints_idempotentWithReferenceId(): void
    {
        $this->post('/users/2/points/earn', ['amount' => 500], ['X-User-Id' => '2']);
        $r1 = $this->post('/users/2/points/spend', ['amount' => 100, 'reference_id' => 'order-456'], ['X-User-Id' => '2']);
        $r2 = $this->post('/users/2/points/spend', ['amount' => 100, 'reference_id' => 'order-456'], ['X-User-Id' => '2']);

        $this->assertSame(201, $r1['status']);
        $this->assertSame(200, $r2['status']);

        $balance = $this->get('/users/2/points', ['X-User-Id' => '2']);
        $this->assertSame(400, $balance['body']['balance'], 'Idempotent spend must not double-debit');
    }

    public function testAdminAdjust_add_returns201(): void
    {
        $result = $this->post('/users/2/points/adjust', [
            'amount' => 1000,
            'adjust_type' => 'add',
            'description' => 'Bonus points',
        ], ['X-User-Id' => '1', 'X-User-Role' => 'admin']);

        $this->assertSame(201, $result['status']);
        $this->assertSame('adjust', $result['body']['type']);
        $this->assertSame(1000, $result['body']['balance_after']);
    }

    public function testAdminAdjust_subtract_returns201(): void
    {
        $this->post('/users/2/points/earn', ['amount' => 500], ['X-User-Id' => '2']);
        $result = $this->post('/users/2/points/adjust', [
            'amount' => 200,
            'adjust_type' => 'subtract',
        ], ['X-User-Id' => '1', 'X-User-Role' => 'admin']);

        $this->assertSame(201, $result['status']);
        $this->assertSame(300, $result['body']['balance_after']);
    }

    public function testAdminAdjust_nonAdmin_returns403(): void
    {
        $result = $this->post('/users/2/points/adjust', ['amount' => 100], ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testGetHistory_returnsTransactions(): void
    {
        $this->post('/users/2/points/earn', ['amount' => 100, 'description' => 'Earn 1'], ['X-User-Id' => '2']);
        $this->post('/users/2/points/earn', ['amount' => 50, 'description' => 'Earn 2'], ['X-User-Id' => '2']);
        $this->post('/users/2/points/spend', ['amount' => 30, 'description' => 'Spend 1'], ['X-User-Id' => '2']);

        $result = $this->get('/users/2/points/history', ['X-User-Id' => '2']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(120, $result['body']['balance']);
        $this->assertCount(3, $result['body']['transactions']);
        $this->assertSame('spend', $result['body']['transactions'][0]['type']);
    }
}
