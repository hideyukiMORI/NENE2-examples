<?php

declare(strict_types=1);

namespace SearchLog\Tests\Search;

use SearchLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class SearchTest extends TestCase
{
    private PDO $pdo;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $this->pdo->exec($schema);

        $now = '2026-01-01T00:00:00Z';
        $this->pdo->exec("INSERT INTO products (name, description, category, price_cents, created_at) VALUES
            ('Apple iPhone 15', 'Flagship smartphone by Apple', 'Electronics', 129900, '{$now}'),
            ('Apple Watch Series 9', 'Smartwatch with health tracking', 'Electronics', 49900, '{$now}'),
            ('Samsung Galaxy S24', 'Android flagship phone', 'Electronics', 119900, '{$now}'),
            ('Banana Bread Mix', 'Easy banana bread baking kit', 'Food', 599, '{$now}'),
            ('Green Apple Juice', 'Fresh cold-pressed juice', 'Beverages', 399, '{$now}'),
            ('Laptop Stand', 'Aluminum ergonomic stand', 'Accessories', 2999, '{$now}'),
            ('USB-C Cable', 'Fast charging cable', 'Accessories', 999, '{$now}')");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $headers */
    private function req(
        string $method,
        string $path,
        array $headers = [],
    ): ResponseInterface {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $uri = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        /** @var array<string, mixed> */
        return json_decode($body, true);
    }

    // ── Search ──────────────────────────────────────────────────────────────

    public function testSearchReturnsMatchingProducts(): void
    {
        $response = $this->req('GET', '/search?q=apple');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame('apple', $data['query']);
        $this->assertSame(3, $data['total']); // iPhone, Watch (Apple), Green Apple Juice
        $this->assertCount(3, $data['items']);
    }

    public function testSearchReturnsEmptyWhenNoMatch(): void
    {
        $response = $this->req('GET', '/search?q=zzzunknown');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(0, $data['total']);
        $this->assertSame([], $data['items']);
    }

    public function testSearchFiltersByCategory(): void
    {
        $response = $this->req('GET', '/search?q=apple&category=Beverages');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(1, $data['total']);
        $this->assertSame('Green Apple Juice', $data['items'][0]['name']);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $response = $this->req('GET', '/search?q=APPLE');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(3, $data['total']);
    }

    public function testSearchMatchesDescription(): void
    {
        $response = $this->req('GET', '/search?q=ergonomic');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(1, $data['total']);
        $this->assertSame('Laptop Stand', $data['items'][0]['name']);
    }

    public function testSearchMatchesCategory(): void
    {
        $response = $this->req('GET', '/search?q=accessories');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(2, $data['total']);
    }

    public function testSearchRespectsPagination(): void
    {
        // 3 total apple products; get 2 then 1
        $page1 = $this->json($this->req('GET', '/search?q=apple&limit=2&offset=0'));
        $page2 = $this->json($this->req('GET', '/search?q=apple&limit=2&offset=2'));

        $this->assertSame(3, $page1['total']);
        $this->assertCount(2, $page1['items']);
        $this->assertSame(3, $page2['total']);
        $this->assertCount(1, $page2['items']);
    }

    public function testSearchClampsLimitToMax(): void
    {
        $response = $this->req('GET', '/search?q=apple&limit=999');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(50, $data['limit']);
    }

    public function testSearchDefaultLimitIs10(): void
    {
        $response = $this->req('GET', '/search?q=apple');
        $data = $this->json($response);
        $this->assertSame(10, $data['limit']);
    }

    public function testSearchReturnsProductFields(): void
    {
        $response = $this->req('GET', '/search?q=iPhone');
        $data = $this->json($response);
        $item = $data['items'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('description', $item);
        $this->assertArrayHasKey('category', $item);
        $this->assertArrayHasKey('price_cents', $item);
        $this->assertArrayHasKey('created_at', $item);
    }

    public function testSearchSpecialCharsAreSafe(): void
    {
        // LIKE wildcards in query must be escaped — no SQL injection or wildcard explosion
        $response = $this->req('GET', '/search?q=ap%25le');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(0, $data['total']); // no product literally matches "ap%le"
    }

    public function testSearchUnderscoreEscaped(): void
    {
        $response = $this->req('GET', '/search?q=Apple_Watch');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(0, $data['total']); // underscore treated literally, not as LIKE wildcard
    }

    public function testSearchQMissingReturns422(): void
    {
        $response = $this->req('GET', '/search');
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testSearchQTooShortReturns422(): void
    {
        $response = $this->req('GET', '/search?q=a');
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testSearchQTooLongReturns422(): void
    {
        $response = $this->req('GET', '/search?q=' . str_repeat('a', 101));
        $this->assertSame(422, $response->getStatusCode());
    }

    // ── Autocomplete ─────────────────────────────────────────────────────────

    public function testAutocompleteReturnsPrefixMatches(): void
    {
        $response = $this->req('GET', '/autocomplete?q=Apple');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertContains('Apple iPhone 15', $data['suggestions']);
        $this->assertContains('Apple Watch Series 9', $data['suggestions']);
    }

    public function testAutocompleteDoesNotReturnContainsMatch(): void
    {
        // "Green Apple Juice" contains "Apple" but does not start with it
        $response = $this->req('GET', '/autocomplete?q=Apple');
        $data = $this->json($response);
        $this->assertNotContains('Green Apple Juice', $data['suggestions']);
    }

    public function testAutocompleteIsCaseInsensitive(): void
    {
        $response = $this->req('GET', '/autocomplete?q=apple');
        $data = $this->json($response);
        $this->assertNotEmpty($data['suggestions']);
    }

    public function testAutocompleteClampsLimitToMax(): void
    {
        $response = $this->req('GET', '/autocomplete?q=Apple&limit=999');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertLessThanOrEqual(10, count($data['suggestions']));
    }

    public function testAutocompleteReturnsEmptyForNoMatch(): void
    {
        $response = $this->req('GET', '/autocomplete?q=Zz');
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame([], $data['suggestions']);
    }

    public function testAutocompleteQMissingReturns422(): void
    {
        $response = $this->req('GET', '/autocomplete');
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testAutocompleteQTooShortReturns422(): void
    {
        $response = $this->req('GET', '/autocomplete?q=a');
        $this->assertSame(422, $response->getStatusCode());
    }
}
