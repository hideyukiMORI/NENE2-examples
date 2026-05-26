<?php

declare(strict_types=1);

namespace Nested\Tests\Order;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nested\Order\OrderValidator;
use Nested\Order\RouteRegistrar;
use Nested\Order\SqliteOrderRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class NestedValidationTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/nestedlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $repo      = new SqliteOrderRepository($executor);
        $validator = new OrderValidator();
        $registrar = new RouteRegistrar($repo, $validator, $json, $problems);

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

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle(new ServerRequest('GET', $path));
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, string>> */
    private function errors(ResponseInterface $response): array
    {
        $data = $this->json($response);
        /** @var list<array<string, string>> */
        return (array) ($data['errors'] ?? []);
    }

    private function errorFields(ResponseInterface $response): string
    {
        return implode(',', array_column($this->errors($response), 'field'));
    }

    /** @return array<string, mixed> */
    private function validOrder(): array
    {
        return [
            'customer' => 'Alice',
            'items'    => [
                ['product_id' => 1, 'quantity' => 2, 'unit_price' => 9.99],
                ['product_id' => 2, 'quantity' => 1, 'unit_price' => 4.50],
            ],
        ];
    }

    // --- happy path ---

    public function testCreateOrderWithMultipleItems(): void
    {
        $res  = $this->post('/orders', $this->validOrder());
        $data = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Alice', $data['customer']);
        $this->assertCount(2, $data['items']);
        $this->assertSame(24.48, $data['total']); // 2×9.99 + 1×4.50
    }

    public function testGetOrder(): void
    {
        $res = $this->post('/orders', $this->validOrder());
        $id  = (int) $this->json($res)['id'];

        $get = $this->get("/orders/{$id}");
        $this->assertSame(200, $get->getStatusCode());
        $this->assertCount(2, $this->json($get)['items']);
    }

    public function testListOrders(): void
    {
        $this->post('/orders', $this->validOrder());
        $this->post('/orders', array_merge($this->validOrder(), ['customer' => 'Bob']));

        $res  = $this->get('/orders');
        $list = (array) json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $list);
    }

    public function testGetNonExistentOrderReturns404(): void
    {
        $res = $this->get('/orders/99999');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- top-level validation ---

    public function testMissingCustomerReturns422(): void
    {
        $body = $this->validOrder();
        unset($body['customer']);
        $res = $this->post('/orders', $body);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('customer', $this->errorFields($res));
    }

    public function testEmptyCustomerReturns422(): void
    {
        $res = $this->post('/orders', array_merge($this->validOrder(), ['customer' => '  ']));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('customer', $this->errorFields($res));
    }

    public function testCustomerTooLongReturns422(): void
    {
        $res = $this->post('/orders', array_merge($this->validOrder(), ['customer' => str_repeat('x', 201)]));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('customer', $this->errorFields($res));
    }

    public function testMissingItemsReturns422(): void
    {
        $body = $this->validOrder();
        unset($body['items']);
        $res = $this->post('/orders', $body);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('items', $this->errorFields($res));
    }

    public function testEmptyItemsReturns422(): void
    {
        $res = $this->post('/orders', array_merge($this->validOrder(), ['items' => []]));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('items', $this->errorFields($res));
    }

    // --- nested item validation: error paths ---

    public function testInvalidProductIdAtIndex0(): void
    {
        $body           = $this->validOrder();
        $body['items'][0]['product_id'] = 'not-an-int';
        $res = $this->post('/orders', $body);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('items.0.product_id', $this->errorFields($res));
    }

    public function testNegativeProductIdAtIndex1(): void
    {
        $body           = $this->validOrder();
        $body['items'][1]['product_id'] = -1;
        $res = $this->post('/orders', $body);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('items.1.product_id', $this->errorFields($res));
    }

    public function testZeroQuantityAtIndex0(): void
    {
        $body           = $this->validOrder();
        $body['items'][0]['quantity'] = 0;
        $res = $this->post('/orders', $body);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('items.0.quantity', $this->errorFields($res));
    }

    public function testNegativePriceAtIndex0(): void
    {
        $body           = $this->validOrder();
        $body['items'][0]['unit_price'] = -1.0;
        $res = $this->post('/orders', $body);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('items.0.unit_price', $this->errorFields($res));
    }

    public function testMissingQuantityAtIndex0(): void
    {
        $body = $this->validOrder();
        unset($body['items'][0]['quantity']);
        $res = $this->post('/orders', $body);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('items.0.quantity', $this->errorFields($res));
    }

    public function testMissingPriceAtIndex1(): void
    {
        $body = $this->validOrder();
        unset($body['items'][1]['unit_price']);
        $res = $this->post('/orders', $body);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('items.1.unit_price', $this->errorFields($res));
    }

    /** Multiple errors across multiple items are all returned in one response */
    public function testMultipleItemErrorsReturnedTogether(): void
    {
        $body = [
            'customer' => 'Alice',
            'items'    => [
                ['product_id' => 0, 'quantity' => -1, 'unit_price' => 9.99], // 2 errors: product_id + quantity
                ['product_id' => 1, 'quantity' => 1, 'unit_price' => -5.0],  // 1 error: unit_price
            ],
        ];

        $res    = $this->post('/orders', $body);
        $errors = $this->errors($res);

        $this->assertSame(422, $res->getStatusCode());
        $fields = array_column($errors, 'field');
        $this->assertContains('items.0.product_id', $fields);
        $this->assertContains('items.0.quantity', $fields);
        $this->assertContains('items.1.unit_price', $fields);
        $this->assertGreaterThanOrEqual(3, count($errors));
    }

    /** Top-level error + nested item errors are all returned together */
    public function testTopLevelAndItemErrorsTogetherReturnAll(): void
    {
        $body = [
            'customer' => '',          // top-level error
            'items'    => [
                ['product_id' => 1, 'quantity' => 0, 'unit_price' => 1.0], // nested error
            ],
        ];

        $res    = $this->post('/orders', $body);
        $fields = array_column($this->errors($res), 'field');

        $this->assertSame(422, $res->getStatusCode());
        $this->assertContains('customer', $fields);
        $this->assertContains('items.0.quantity', $fields);
    }

    /** Verify error codes are present alongside field paths */
    public function testErrorCodesArePresent(): void
    {
        $body = $this->validOrder();
        $body['items'][0]['quantity'] = 0;

        $res    = $this->post('/orders', $body);
        $errors = $this->errors($res);

        $quantityError = array_values(array_filter($errors, static fn (array $e) => $e['field'] === 'items.0.quantity'));
        $this->assertNotEmpty($quantityError);
        $this->assertSame('min-value', $quantityError[0]['code']);
    }

    /** unit_price accepts both int (0) and float from JSON */
    public function testZeroPriceIsRejected(): void
    {
        $body = $this->validOrder();
        $body['items'][0]['unit_price'] = 0;
        $res = $this->post('/orders', $body);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('items.0.unit_price', $this->errorFields($res));
    }
}
