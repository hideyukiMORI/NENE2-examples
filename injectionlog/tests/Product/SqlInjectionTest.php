<?php

declare(strict_types=1);

namespace Injection\Tests\Product;

use Injection\Product\RouteRegistrar;
use Injection\Product\SqliteProductRepository;
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

final class SqlInjectionTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/injectionlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig = new DatabaseConfig(
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
        $repo      = new SqliteProductRepository($executor);
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

    private function post(string $path, mixed $body): ResponseInterface
    {
        $json    = json_encode($body, JSON_THROW_ON_ERROR);
        $stream  = Stream::create($json);
        $request = (new ServerRequest('POST', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        return $this->app->handle($request);
    }

    private function get(string $path, string $query = ''): ResponseInterface
    {
        $uri = $query !== '' ? $path . '?' . $query : $path;
        return $this->app->handle(new ServerRequest('GET', $uri));
    }

    private function delete(string $path): ResponseInterface
    {
        return $this->app->handle(new ServerRequest('DELETE', $path));
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

    private function seed(): void
    {
        $this->post('/products', ['name' => 'Apple', 'category' => 'fruit', 'price' => 1.50, 'description' => 'A red apple']);
        $this->post('/products', ['name' => 'Banana', 'category' => 'fruit', 'price' => 0.80, 'description' => 'A yellow banana']);
        $this->post('/products', ['name' => 'Carrot', 'category' => 'vegetable', 'price' => 0.60, 'description' => 'An orange carrot']);
        $this->post('/products', ['name' => 'Durian', 'category' => 'fruit', 'price' => 15.00, 'description' => 'A spiky fruit']);
    }

    // --- happy-path ---

    public function testCreateAndGetProduct(): void
    {
        $res = $this->post('/products', [
            'name'        => 'Apple',
            'category'    => 'fruit',
            'price'       => 1.50,
            'description' => 'A fresh apple',
        ]);
        $this->assertSame(201, $res->getStatusCode());

        $data = $this->json($res);
        $this->assertSame('Apple', $data['name']);
        $this->assertSame(1.50, $data['price']);

        $id  = (int) $data['id'];
        $res = $this->get("/products/{$id}");
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Apple', $this->json($res)['name']);
    }

    public function testListProducts(): void
    {
        $this->seed();
        $res = $this->get('/products');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(4, $this->jsonList($res));
    }

    // --- SQL injection in q (LIKE search) ---

    /** Classic tautology injection — should return only matched rows, not all rows */
    public function testLikeQueryWithTautologyInjection(): void
    {
        $this->seed();
        // ' OR '1'='1 — if injected into raw SQL, returns all rows
        $res  = $this->get('/products', "q=" . urlencode("' OR '1'='1"));
        $this->assertSame(200, $res->getStatusCode());
        // Parameterized LIKE treats the whole string as literal — 0 matches expected
        $this->assertCount(0, $this->jsonList($res));
    }

    /** DROP TABLE injection — should be treated as literal search string */
    public function testLikeQueryWithDropTableInjection(): void
    {
        $this->seed();
        $res = $this->get('/products', "q=" . urlencode("'; DROP TABLE products; --"));
        $this->assertSame(200, $res->getStatusCode());
        // Table still exists — DROP wasn't executed
        $this->assertCount(0, $this->jsonList($res));

        // Confirm table still works
        $afterRes = $this->get('/products');
        $this->assertSame(200, $afterRes->getStatusCode());
        $this->assertCount(4, $this->jsonList($afterRes));
    }

    /** UNION-based injection in search */
    public function testLikeQueryWithUnionInjection(): void
    {
        $this->seed();
        $payload = "Apple' UNION SELECT 1,'injected','evil',99.99,'hacked' WHERE '1'='1";
        $res     = $this->get('/products', "q=" . urlencode($payload));
        $this->assertSame(200, $res->getStatusCode());
        // Parameterized query: no UNION injection — 0 or 1 matches (Apple matches literal)
        $items = $this->jsonList($res);
        foreach ($items as $item) {
            // injected row would have category='evil' — should not appear
            $this->assertNotSame('evil', $item['category']);
        }
    }

    /** Normal LIKE search still works correctly */
    public function testLikeSearchWorksForLegitimateInput(): void
    {
        $this->seed();
        $res = $this->get('/products', "q=an");
        $this->assertSame(200, $res->getStatusCode());
        // "Banana" contains "an", "Durian" does not contain "an" in name but "A yellow banana" description doesn't have "an" in that form
        // Apple=no, Banana=yes (name), Carrot=no, Durian=no
        $items = $this->jsonList($res);
        $names = array_column($items, 'name');
        $this->assertContains('Banana', $names);
    }

    // --- SQL injection in id (path parameter) ---

    /** Integer path parameter coercion prevents injection */
    public function testIdPathParameterIsCoercedToInt(): void
    {
        $this->seed();
        // PHP's (int)"1 OR 1=1" = 1, so this just fetches ID 1
        $res = $this->get('/products/1 OR 1=1');
        // Router likely returns 404 (no matching route for non-numeric)
        // or 200 for product 1 — either way, injection doesn't dump all rows
        $status = $res->getStatusCode();
        $this->assertContains($status, [200, 400, 404]);

        if ($status === 200) {
            // Only one product returned, not all
            $data = $this->json($res);
            $this->assertArrayHasKey('id', $data);
            $this->assertSame(1, $data['id']);
        }
    }

    /** Zero and negative IDs are rejected */
    public function testZeroIdReturns404(): void
    {
        $res = $this->get('/products/0');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- ORDER BY injection (the classic footgun) ---

    /** Whitelisted sort fields work correctly */
    public function testValidSortFields(): void
    {
        $this->seed();
        foreach (['id', 'name', 'category', 'price'] as $field) {
            $res = $this->get('/products', "sort={$field}&order=asc");
            $this->assertSame(200, $res->getStatusCode(), "Sort by '{$field}' should succeed");
        }
    }

    /** Invalid sort field is rejected with 400 */
    public function testInvalidSortFieldReturns400(): void
    {
        $res = $this->get('/products', "sort=id;DROP+TABLE+products;--&order=asc");
        $this->assertSame(400, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('invalid-sort-field', (string) ($data['type'] ?? ''));
    }

    /** SQL injection attempt via sort parameter */
    public function testSortParameterInjectionAttempt(): void
    {
        $this->seed();
        // Attacker tries to inject: CASE WHEN (SELECT 1=1) THEN name ELSE price END
        $injection = "name, (SELECT name FROM products WHERE 1=1 LIMIT 1)";
        $res       = $this->get('/products', "sort=" . urlencode($injection));
        $this->assertSame(400, $res->getStatusCode()); // whitelist rejects it
    }

    /** ORDER direction is normalized — only ASC or DESC accepted */
    public function testOrderDirectionIsNormalized(): void
    {
        $this->seed();
        // Attempt to inject into ORDER direction
        $res = $this->get('/products', "sort=name&order=" . urlencode("ASC; DROP TABLE products; --"));
        $this->assertSame(200, $res->getStatusCode()); // order is normalized to ASC
        $this->assertCount(4, $this->jsonList($res)); // table still intact
    }

    // --- CREATE with injection in body ---

    /** JSON body injection in name field — stored safely */
    public function testCreateWithSqlInjectionInName(): void
    {
        $injectedName = "Robert'); DROP TABLE products; --";
        $res          = $this->post('/products', [
            'name'        => $injectedName,
            'category'    => 'test',
            'price'       => 1.00,
            'description' => '',
        ]);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        // Name is stored as-is (not executed as SQL)
        $this->assertSame($injectedName, $data['name']);

        // Confirm table still works after the "injection"
        $listRes = $this->get('/products');
        $this->assertSame(200, $listRes->getStatusCode());
        $this->assertCount(1, $this->jsonList($listRes));
    }

    /** SQL injection in description is stored safely */
    public function testCreateWithSqlInjectionInDescription(): void
    {
        $res = $this->post('/products', [
            'name'        => 'Test',
            'category'    => 'test',
            'price'       => 0.0,
            'description' => "' OR '1'='1'; DELETE FROM products; --",
        ]);
        $this->assertSame(201, $res->getStatusCode());
        $id  = (int) $this->json($res)['id'];

        // Row is retrievable and description is literal
        $getRes = $this->get("/products/{$id}");
        $this->assertSame(200, $getRes->getStatusCode());
        $this->assertStringContainsString("' OR '1'='1", (string) $this->json($getRes)['description']);
    }

    // --- validation ---

    public function testCreateWithMissingNameReturns422(): void
    {
        $res = $this->post('/products', ['category' => 'test', 'price' => 1.0]);
        $this->assertSame(422, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('validation-failed', (string) ($data['type'] ?? ''));
    }

    public function testCreateWithNegativePriceReturns422(): void
    {
        $res = $this->post('/products', ['name' => 'X', 'category' => 'test', 'price' => -5.0]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- search returning results ---

    public function testSearchByCategory(): void
    {
        $this->seed();
        $res   = $this->get('/products', "q=fruit");
        $items = $this->jsonList($res);
        // Matches description "A red apple" etc — "fruit" appears in category but not body text
        // Actually "A spiky fruit" in Durian's description
        $this->assertSame(200, $res->getStatusCode());
        $names = array_column($items, 'name');
        $this->assertContains('Durian', $names); // "A spiky fruit" matches
    }

    // --- delete ---

    public function testDeleteProduct(): void
    {
        $res = $this->post('/products', ['name' => 'Temp', 'category' => 'test', 'price' => 0.0, 'description' => '']);
        $id  = (int) $this->json($res)['id'];

        $del = $this->delete("/products/{$id}");
        $this->assertSame(200, $del->getStatusCode());
        $this->assertTrue((bool) $this->json($del)['deleted']);

        $get = $this->get("/products/{$id}");
        $this->assertSame(404, $get->getStatusCode());
    }

    public function testDeleteNonExistentProductReturns404(): void
    {
        $res = $this->delete('/products/99999');
        $this->assertSame(404, $res->getStatusCode());
    }
}
