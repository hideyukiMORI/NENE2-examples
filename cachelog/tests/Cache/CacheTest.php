<?php

declare(strict_types=1);

namespace CacheLog\Tests\Cache;

use CacheLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CacheTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;
    private int $fakeTime;

    protected function setUp(): void
    {
        $this->dbFile   = sys_get_temp_dir() . '/cachelog_test_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));

        $this->fakeTime = time();
        $self = $this;
        $clock = function () use ($self): int {
            return $self->fakeTime;
        };
        $this->app = AppFactory::createSqlite($this->dbFile, $clock);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function req(
        string $method,
        string $path,
        mixed $body = null,
    ): ResponseInterface {
        $psr17 = new Psr17Factory();
        $uri = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true);
    }

    private function createProduct(string $name = 'Widget', float $price = 9.99, int $stock = 10): int
    {
        $res = $this->req('POST', '/products', ['name' => $name, 'price' => $price, 'stock' => $stock]);
        return (int) $this->json($res)['id'];
    }

    // =========================================================================
    // Cache-Aside: list

    public function testFirstListRequestIsCacheMiss(): void
    {
        $this->createProduct();
        $res  = $this->req('GET', '/products');
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($data['cached'], 'First request must be a cache miss');
        $this->assertCount(1, $data['products']);
    }

    public function testSecondListRequestIsCacheHit(): void
    {
        $this->createProduct();
        $this->req('GET', '/products'); // warm cache
        $res  = $this->req('GET', '/products');
        $data = $this->json($res);
        $this->assertTrue($data['cached'], 'Second request must be a cache hit');
    }

    public function testListCacheInvalidatedOnCreate(): void
    {
        $this->createProduct('Alpha');
        $this->req('GET', '/products'); // warm cache

        $this->createProduct('Beta'); // should bust list cache

        $res  = $this->req('GET', '/products');
        $data = $this->json($res);
        $this->assertFalse($data['cached'], 'Create must invalidate list cache');
        $this->assertCount(2, $data['products']);
    }

    public function testListCacheInvalidatedOnUpdate(): void
    {
        $id = $this->createProduct('Original');
        $this->req('GET', '/products'); // warm cache

        $this->req('PUT', "/products/{$id}", ['name' => 'Updated', 'price' => 19.99, 'stock' => 5]);

        $res  = $this->req('GET', '/products');
        $data = $this->json($res);
        $this->assertFalse($data['cached'], 'Update must invalidate list cache');
        $this->assertSame('Updated', $data['products'][0]['name']);
    }

    public function testListCacheInvalidatedOnDelete(): void
    {
        $id = $this->createProduct();
        $this->req('GET', '/products'); // warm cache

        $this->req('DELETE', "/products/{$id}");

        $res  = $this->req('GET', '/products');
        $data = $this->json($res);
        $this->assertFalse($data['cached'], 'Delete must invalidate list cache');
        $this->assertCount(0, $data['products']);
    }

    // =========================================================================
    // Cache-Aside: individual product

    public function testFirstGetProductIsCacheMiss(): void
    {
        $id  = $this->createProduct('Gadget');
        $res = $this->req('GET', "/products/{$id}");
        $data = $this->json($res);
        $this->assertFalse($data['cached']);
        $this->assertSame('Gadget', $data['name']);
    }

    public function testSecondGetProductIsCacheHit(): void
    {
        $id = $this->createProduct('Gadget');
        $this->req('GET', "/products/{$id}"); // warm cache
        $res  = $this->req('GET', "/products/{$id}");
        $data = $this->json($res);
        $this->assertTrue($data['cached']);
    }

    public function testGetNonexistentProductReturns404(): void
    {
        $res = $this->req('GET', '/products/9999');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testUpdateInvalidatesItemCache(): void
    {
        $id = $this->createProduct('OldName');
        $this->req('GET', "/products/{$id}"); // warm cache

        $this->req('PUT', "/products/{$id}", ['name' => 'NewName', 'price' => 5.0, 'stock' => 3]);

        $res  = $this->req('GET', "/products/{$id}");
        $data = $this->json($res);
        $this->assertFalse($data['cached'], 'Update must invalidate item cache');
        $this->assertSame('NewName', $data['name']);
    }

    public function testDeleteInvalidatesItemCache(): void
    {
        $id = $this->createProduct();
        $this->req('GET', "/products/{$id}"); // warm cache

        $this->req('DELETE', "/products/{$id}");

        $res = $this->req('GET', "/products/{$id}");
        $this->assertSame(404, $res->getStatusCode());
    }

    // =========================================================================
    // TTL expiry

    public function testCachedItemExpiresAfterTtl(): void
    {
        $id = $this->createProduct('Expiring');
        $this->req('GET', "/products/{$id}"); // warm cache (TTL=60s)

        $this->fakeTime += 61; // advance clock past TTL

        $res  = $this->req('GET', "/products/{$id}");
        $data = $this->json($res);
        $this->assertFalse($data['cached'], 'Cache entry must expire after TTL');
    }

    public function testCachedListExpiresAfterTtl(): void
    {
        $this->createProduct();
        $this->req('GET', '/products'); // warm cache

        $this->fakeTime += 61; // advance clock past TTL

        $res  = $this->req('GET', '/products');
        $data = $this->json($res);
        $this->assertFalse($data['cached'], 'List cache must expire after TTL');
    }

    // =========================================================================
    // Cache management

    public function testCacheClearFlushesAll(): void
    {
        $id = $this->createProduct();
        $this->req('GET', '/products');
        $this->req('GET', "/products/{$id}");

        $this->req('POST', '/cache/clear');

        $this->assertFalse($this->json($this->req('GET', '/products'))['cached']);
        $this->assertFalse($this->json($this->req('GET', "/products/{$id}"))['cached']);
    }

    public function testCacheStatsReflectHitsAndMisses(): void
    {
        $id = $this->createProduct();

        $this->req('GET', "/products/{$id}"); // miss
        $this->req('GET', "/products/{$id}"); // hit
        $this->req('GET', "/products/{$id}"); // hit

        $stats = $this->json($this->req('GET', '/cache/stats'));
        $this->assertSame(2, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(1, $stats['size']);
    }

    public function testCacheStatsReflectSizeAfterFlush(): void
    {
        $this->createProduct();
        $this->req('GET', '/products');

        $stats = $this->json($this->req('GET', '/cache/stats'));
        $this->assertSame(1, $stats['size']);

        $this->req('POST', '/cache/clear');

        $stats = $this->json($this->req('GET', '/cache/stats'));
        $this->assertSame(0, $stats['size']);
    }

    // =========================================================================
    // Validation

    public function testCreateWithoutNameReturns422(): void
    {
        $res = $this->req('POST', '/products', ['price' => 9.99]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateWithoutPriceReturns422(): void
    {
        $res = $this->req('POST', '/products', ['name' => 'NoPrice']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testUpdateNonexistentProductReturns404(): void
    {
        $res = $this->req('PUT', '/products/9999', ['name' => 'X', 'price' => 1.0, 'stock' => 0]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testDeleteNonexistentProductReturns404(): void
    {
        $res = $this->req('DELETE', '/products/9999');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testMultipleProductsCachedIndependently(): void
    {
        $id1 = $this->createProduct('A');
        $id2 = $this->createProduct('B');

        $this->req('GET', "/products/{$id1}"); // warm A
        // B not yet cached

        $resA = $this->json($this->req('GET', "/products/{$id1}"));
        $resB = $this->json($this->req('GET', "/products/{$id2}"));

        $this->assertTrue($resA['cached'], 'Product A should be cached');
        $this->assertFalse($resB['cached'], 'Product B should not be cached yet');
    }
}
