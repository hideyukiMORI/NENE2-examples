<?php

declare(strict_types=1);

namespace PinLog\Tests\Pin;

use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use PinLog\AppFactory;

final class PinTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Alice', '2026-01-01T00:00:00+00:00')");
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Bob', '2026-01-01T00:00:00+00:00')");
        for ($i = 1; $i <= 12; $i++) {
            $this->pdo->exec("INSERT INTO articles (title, author_id, created_at) VALUES ('Article $i', 1, '2026-01-01T00:00:00+00:00')");
        }

        $this->router = AppFactory::createSqliteApp($this->pdo);
        $this->psr17 = new Psr17Factory();
    }

    private function post(string $path, array $body = [], array $headers = []): array
    {
        $request = new ServerRequest('POST', $path, array_merge(['Content-Type' => 'application/json'], $headers));
        $json = empty($body) ? '{}' : (json_encode($body) ?: '{}');
        $request = $request->withBody($this->psr17->createStream($json));
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    private function put(string $path, array $body, array $headers = []): array
    {
        $request = new ServerRequest('PUT', $path, array_merge(['Content-Type' => 'application/json'], $headers));
        $json = empty($body) ? '{}' : (json_encode($body) ?: '{}');
        $request = $request->withBody($this->psr17->createStream($json));
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    private function delete(string $path, array $headers = []): array
    {
        $request = new ServerRequest('DELETE', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    private function get(string $path, array $headers = []): array
    {
        $request = new ServerRequest('GET', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    // --- POST /pins ---

    public function testPinArticleReturns201(): void
    {
        $result = $this->post('/pins', ['article_id' => 1], ['X-User-Id' => '1']);
        $this->assertSame(201, $result['status']);
        $this->assertSame(1, $result['body']['article_id']);
        $this->assertSame(1, $result['body']['position']);
    }

    public function testPinSameArticleTwiceReturns200(): void
    {
        $this->post('/pins', ['article_id' => 1], ['X-User-Id' => '1']);
        $result = $this->post('/pins', ['article_id' => 1], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
    }

    public function testPinPositionsAreSequential(): void
    {
        $this->post('/pins', ['article_id' => 1], ['X-User-Id' => '1']);
        $this->post('/pins', ['article_id' => 2], ['X-User-Id' => '1']);
        $this->post('/pins', ['article_id' => 3], ['X-User-Id' => '1']);

        $list = $this->get('/pins', ['X-User-Id' => '1']);
        $positions = array_column($list['body']['pins'], 'position');
        $this->assertSame([1, 2, 3], $positions);
    }

    public function testPinLimitEnforced(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->post('/pins', ['article_id' => $i], ['X-User-Id' => '1']);
        }
        $result = $this->post('/pins', ['article_id' => 11], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);
        $this->assertArrayHasKey('max', $result['body']);
    }

    public function testPinWithoutUserHeaderReturns401(): void
    {
        $result = $this->post('/pins', ['article_id' => 1]);
        $this->assertSame(401, $result['status']);
    }

    public function testPinMissingArticleIdReturns422(): void
    {
        $result = $this->post('/pins', [], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);
    }

    public function testPinNonExistentArticleReturns404(): void
    {
        $result = $this->post('/pins', ['article_id' => 999], ['X-User-Id' => '1']);
        $this->assertSame(404, $result['status']);
    }

    // --- DELETE /pins/{articleId} ---

    public function testUnpinReturns204(): void
    {
        $this->post('/pins', ['article_id' => 1], ['X-User-Id' => '1']);
        $result = $this->delete('/pins/1', ['X-User-Id' => '1']);
        $this->assertSame(204, $result['status']);
    }

    public function testUnpinNonExistentReturns404(): void
    {
        $result = $this->delete('/pins/1', ['X-User-Id' => '1']);
        $this->assertSame(404, $result['status']);
    }

    public function testUnpinCompactsPositions(): void
    {
        $this->post('/pins', ['article_id' => 1], ['X-User-Id' => '1']);
        $this->post('/pins', ['article_id' => 2], ['X-User-Id' => '1']);
        $this->post('/pins', ['article_id' => 3], ['X-User-Id' => '1']);

        $this->delete('/pins/2', ['X-User-Id' => '1']);

        $list = $this->get('/pins', ['X-User-Id' => '1']);
        $positions = array_column($list['body']['pins'], 'position');
        $this->assertSame([1, 2], $positions); // no gap
        $articleIds = array_column($list['body']['pins'], 'article_id');
        $this->assertSame([1, 3], $articleIds);
    }

    public function testUnpinWithoutUserHeaderReturns401(): void
    {
        $result = $this->delete('/pins/1');
        $this->assertSame(401, $result['status']);
    }

    // --- GET /pins ---

    public function testListPinsReturnsEmpty(): void
    {
        $result = $this->get('/pins', ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['body']['pins']);
        $this->assertSame(0, $result['body']['count']);
    }

    public function testListPinsReturnsOrderedByPosition(): void
    {
        $this->post('/pins', ['article_id' => 3], ['X-User-Id' => '1']);
        $this->post('/pins', ['article_id' => 1], ['X-User-Id' => '1']);
        $this->post('/pins', ['article_id' => 2], ['X-User-Id' => '1']);

        $result = $this->get('/pins', ['X-User-Id' => '1']);
        $articleIds = array_column($result['body']['pins'], 'article_id');
        $this->assertSame([3, 1, 2], $articleIds); // in pin order, not article_id order
    }

    public function testListPinsIsolatedBetweenUsers(): void
    {
        $this->post('/pins', ['article_id' => 1], ['X-User-Id' => '1']);
        $result = $this->get('/pins', ['X-User-Id' => '2']);
        $this->assertSame(0, $result['body']['count']);
    }

    // --- PUT /pins/order ---

    public function testReorderPins(): void
    {
        $this->post('/pins', ['article_id' => 1], ['X-User-Id' => '1']);
        $this->post('/pins', ['article_id' => 2], ['X-User-Id' => '1']);
        $this->post('/pins', ['article_id' => 3], ['X-User-Id' => '1']);

        $result = $this->put('/pins/order', ['article_ids' => [3, 1, 2]], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $articleIds = array_column($result['body']['pins'], 'article_id');
        $this->assertSame([3, 1, 2], $articleIds);
    }

    public function testReorderWithMissingArticleReturns422(): void
    {
        $this->post('/pins', ['article_id' => 1], ['X-User-Id' => '1']);
        $this->post('/pins', ['article_id' => 2], ['X-User-Id' => '1']);

        $result = $this->put('/pins/order', ['article_ids' => [1, 3]], ['X-User-Id' => '1']); // 3 not pinned
        $this->assertSame(422, $result['status']);
    }

    public function testReorderWithoutUserHeaderReturns401(): void
    {
        $result = $this->put('/pins/order', ['article_ids' => [1]]);
        $this->assertSame(401, $result['status']);
    }

    public function testReorderMissingBodyReturns422(): void
    {
        $result = $this->put('/pins/order', [], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);
    }

    public function testPinCountAfterUnpin(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->post('/pins', ['article_id' => $i], ['X-User-Id' => '1']);
        }
        $this->delete('/pins/1', ['X-User-Id' => '1']);
        // Now can pin again
        $result = $this->post('/pins', ['article_id' => 11], ['X-User-Id' => '1']);
        $this->assertSame(201, $result['status']);
    }
}
