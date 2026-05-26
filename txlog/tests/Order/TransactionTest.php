<?php

declare(strict_types=1);

namespace Tx\Tests\Order;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Database\PdoDatabaseTransactionManager;
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
use Tx\Order\InventoryRepository;
use Tx\Order\OrderRepository;
use Tx\Order\OrderService;
use Tx\Order\RouteRegistrar;

final class TransactionTest extends TestCase
{
    private RequestHandlerInterface $app;
    private InventoryRepository $inventory;
    private OrderRepository $orders;
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/txlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');
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
        $factory      = new PdoConnectionFactory($dbConfig);
        $executor     = new PdoDatabaseQueryExecutor($factory);
        $txManager    = new PdoDatabaseTransactionManager($factory);
        $psr17        = new Psr17Factory();
        $json         = new JsonResponseFactory($psr17, $psr17);
        $problems     = new ProblemDetailsResponseFactory($psr17, $psr17);
        $service      = new OrderService($txManager);
        $registrar    = new RouteRegistrar($service, $json, $problems);

        $this->inventory = new InventoryRepository($executor);
        $this->orders    = new OrderRepository($executor);

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

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('POST', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- happy path ---

    public function testSuccessfulOrderDecrementsInventory(): void
    {
        $this->inventory->seed(1, 'Widget', 10);
        $this->inventory->seed(2, 'Gadget', 5);

        $res = $this->post('/orders', ['items' => [
            ['product_id' => 1, 'quantity' => 3],
            ['product_id' => 2, 'quantity' => 2],
        ]]);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('placed', $this->json($res)['status']);

        // Inventory decremented atomically
        $this->assertSame(7, $this->inventory->getStock(1)); // 10 - 3
        $this->assertSame(3, $this->inventory->getStock(2)); // 5 - 2
        $this->assertSame(1, $this->orders->count());
    }

    public function testOrderWithSingleItem(): void
    {
        $this->inventory->seed(1, 'Widget', 1);
        $res = $this->post('/orders', ['items' => [['product_id' => 1, 'quantity' => 1]]]);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(0, $this->inventory->getStock(1));
    }

    // --- rollback correctness ---

    /**
     * When the LAST item in the list has insufficient stock, ALL prior inventory
     * decrements must be rolled back. This verifies the atomic guarantee.
     */
    public function testRollbackWhenLastItemOutOfStock(): void
    {
        $this->inventory->seed(1, 'Widget', 10);
        $this->inventory->seed(2, 'Gadget', 1); // only 1 in stock

        $res = $this->post('/orders', ['items' => [
            ['product_id' => 1, 'quantity' => 3],   // would succeed
            ['product_id' => 2, 'quantity' => 5],   // fails — insufficient stock
        ]]);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('insufficient-stock', (string) ($this->json($res)['type'] ?? ''));

        // CRITICAL: Widget stock must be unchanged — rollback worked
        $this->assertSame(10, $this->inventory->getStock(1), 'Rollback must restore Widget stock to 10');
        // No order created
        $this->assertSame(0, $this->orders->count(), 'No order should have been persisted after rollback');
    }

    /**
     * When the FIRST item already has insufficient stock, no changes are made.
     */
    public function testRollbackWhenFirstItemOutOfStock(): void
    {
        $this->inventory->seed(1, 'Widget', 0); // empty
        $this->inventory->seed(2, 'Gadget', 10);

        $res = $this->post('/orders', ['items' => [
            ['product_id' => 1, 'quantity' => 1],   // fails immediately
            ['product_id' => 2, 'quantity' => 5],   // never reached
        ]]);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(10, $this->inventory->getStock(2), 'Gadget stock must be unchanged');
        $this->assertSame(0, $this->orders->count());
    }

    /**
     * Successful order does not affect stock of products not in the order.
     */
    public function testUninvolvedProductStockUntouched(): void
    {
        $this->inventory->seed(1, 'Widget', 5);
        $this->inventory->seed(2, 'Gadget', 20); // not ordered

        $this->post('/orders', ['items' => [['product_id' => 1, 'quantity' => 2]]]);

        $this->assertSame(3, $this->inventory->getStock(1));
        $this->assertSame(20, $this->inventory->getStock(2), 'Uninvolved product must be untouched');
    }

    /**
     * Multiple successful orders accumulate correctly — transactions are independent.
     */
    public function testMultipleSuccessfulOrders(): void
    {
        $this->inventory->seed(1, 'Widget', 10);

        $this->post('/orders', ['items' => [['product_id' => 1, 'quantity' => 2]]]);
        $this->post('/orders', ['items' => [['product_id' => 1, 'quantity' => 3]]]);

        $this->assertSame(5, $this->inventory->getStock(1)); // 10 - 2 - 3
        $this->assertSame(2, $this->orders->count());
    }

    /**
     * Ordering exactly the remaining stock (boundary: stock reaches 0).
     */
    public function testOrderExactRemainingStock(): void
    {
        $this->inventory->seed(1, 'Widget', 3);
        $res = $this->post('/orders', ['items' => [['product_id' => 1, 'quantity' => 3]]]);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(0, $this->inventory->getStock(1));
    }

    /**
     * Ordering one more than remaining stock is rejected; stock stays at its previous value.
     */
    public function testOrderOneMoreThanStockIsRejected(): void
    {
        $this->inventory->seed(1, 'Widget', 3);
        $res = $this->post('/orders', ['items' => [['product_id' => 1, 'quantity' => 4]]]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(3, $this->inventory->getStock(1), 'Stock must not change after rejected order');
    }

    // --- input validation ---

    public function testMissingItemsReturns400(): void
    {
        $res = $this->post('/orders', []);
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testInvalidItemStructureReturns422(): void
    {
        $res = $this->post('/orders', ['items' => [['product_id' => 1]]]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testZeroQuantityReturns422(): void
    {
        $this->inventory->seed(1, 'Widget', 5);
        $res = $this->post('/orders', ['items' => [['product_id' => 1, 'quantity' => 0]]]);
        $this->assertSame(422, $res->getStatusCode());
    }
}
