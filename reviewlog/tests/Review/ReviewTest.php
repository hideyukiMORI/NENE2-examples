<?php

declare(strict_types=1);

namespace ReviewLog\Tests\Review;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReviewLog\AppFactory;

final class ReviewTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/reviewlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $pdo->exec("INSERT INTO users (name, created_at) VALUES ('Alice', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO users (name, created_at) VALUES ('Bob', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO products (name, created_at) VALUES ('Widget Pro', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO products (name, created_at) VALUES ('Gadget Plus', '2026-01-01 00:00:00')");
        unset($pdo);

        $this->app = AppFactory::createSqlite($this->dbFile);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $headers */
    private function req(string $method, string $uri, mixed $body = null, array $headers = []): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')
                       ->withBody((new Psr17Factory())->createStream(
                           empty($body) ? '{}' : (json_encode($body) ?: '{}')
                       ));
        }
        return $this->app->handle($req);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        return (array) json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function postReview(int $userId, int $productId = 1, int $rating = 5, string $body = 'Great!'): ResponseInterface
    {
        return $this->req('POST', "/products/{$productId}/reviews", [
            'rating' => $rating,
            'body' => $body,
        ], ['X-User-Id' => (string) $userId]);
    }

    // --- auth ---

    public function testCreateWithoutAuthReturns401(): void
    {
        $res = $this->req('POST', '/products/1/reviews', ['rating' => 5]);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testListWithoutAuthReturns401(): void
    {
        $res = $this->req('GET', '/products/1/reviews');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testSummaryWithoutAuthReturns401(): void
    {
        $res = $this->req('GET', '/products/1/reviews/summary');
        $this->assertSame(401, $res->getStatusCode());
    }

    // --- create ---

    public function testCreateReturns201(): void
    {
        $res = $this->postReview(1, 1, 5, 'Excellent product!');
        $body = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(1, $body['user_id']);
        $this->assertSame('Alice', $body['user_name']);
        $this->assertSame(5, $body['rating']);
        $this->assertSame('Excellent product!', $body['body']);
        $this->assertArrayHasKey('id', $body);
    }

    public function testCreateWithNullBodySucceeds(): void
    {
        $res = $this->req('POST', '/products/1/reviews', ['rating' => 4], ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertNull($body['body']);
    }

    public function testCreateForNonExistentProductReturns404(): void
    {
        $res = $this->postReview(1, 999);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testDuplicateReviewReturns409(): void
    {
        $this->postReview(1);
        $res = $this->postReview(1);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testInvalidRatingTooLowReturns422(): void
    {
        $res = $this->req('POST', '/products/1/reviews', ['rating' => 0], ['X-User-Id' => '1']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testInvalidRatingTooHighReturns422(): void
    {
        $res = $this->req('POST', '/products/1/reviews', ['rating' => 6], ['X-User-Id' => '1']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testMissingRatingReturns422(): void
    {
        $res = $this->req('POST', '/products/1/reviews', ['body' => 'No rating'], ['X-User-Id' => '1']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testFloatRatingReturns422(): void
    {
        $res = $this->req('POST', '/products/1/reviews', ['rating' => 4.5], ['X-User-Id' => '1']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- list ---

    public function testListEmpty(): void
    {
        $res = $this->req('GET', '/products/1/reviews', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([], $body['items']);
        $this->assertNull($body['next_cursor']);
    }

    public function testListReturnsReviews(): void
    {
        $this->postReview(1, 1, 5, 'Excellent');
        $this->postReview(2, 1, 3, 'Average');

        $res = $this->req('GET', '/products/1/reviews', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(2, $body['items']);
    }

    public function testListOnlyShowsReviewsForRequestedProduct(): void
    {
        $this->postReview(1, 1, 5, 'For product 1');
        $this->postReview(1, 2, 4, 'For product 2');

        $res = $this->req('GET', '/products/1/reviews', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertCount(1, $body['items']);
        $this->assertSame(1, $body['items'][0]['product_id']);
    }

    public function testListCursorPagination(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        for ($i = 3; $i <= 5; $i++) {
            $pdo->exec("INSERT INTO users (name, created_at) VALUES ('User{$i}', '2026-01-01 00:00:00')");
        }
        for ($i = 1; $i <= 5; $i++) {
            $pdo->exec("INSERT INTO reviews (product_id, user_id, rating, body, created_at, updated_at) VALUES (1, {$i}, " . min($i, 5) . ", 'review {$i}', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        }
        unset($pdo);

        $res1 = $this->req('GET', '/products/1/reviews?limit=3', null, ['X-User-Id' => '1']);
        $body1 = $this->json($res1);
        $this->assertCount(3, $body1['items']);
        $this->assertNotNull($body1['next_cursor']);

        $cursor = $body1['next_cursor'];
        $res2 = $this->req('GET', "/products/1/reviews?limit=3&before_id={$cursor}", null, ['X-User-Id' => '1']);
        $body2 = $this->json($res2);
        $this->assertCount(2, $body2['items']);
        $this->assertNull($body2['next_cursor']);
    }

    public function testListNonExistentProductReturns404(): void
    {
        $res = $this->req('GET', '/products/999/reviews', null, ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- summary ---

    public function testSummaryEmpty(): void
    {
        $res = $this->req('GET', '/products/1/reviews/summary', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, $body['total']);
        $this->assertNull($body['avg_rating']);
    }

    public function testSummaryCalculatesCorrectly(): void
    {
        $this->postReview(1, 1, 5, 'Five stars');
        $this->postReview(2, 1, 3, 'Three stars');

        $res = $this->req('GET', '/products/1/reviews/summary', null, ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(2, $body['total']);
        $this->assertSame(4.0, $body['avg_rating']);
        $this->assertSame(1, $body['distribution'][5]);
        $this->assertSame(1, $body['distribution'][3]);
        $this->assertSame(0, $body['distribution'][1]);
    }

    public function testSummaryNonExistentProductReturns404(): void
    {
        $res = $this->req('GET', '/products/999/reviews/summary', null, ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- update ---

    public function testUpdateReturns200(): void
    {
        $created = $this->json($this->postReview(1, 1, 5, 'Original'));
        $res = $this->req('PUT', "/products/1/reviews/{$created['id']}", [
            'rating' => 3, 'body' => 'Changed my mind',
        ], ['X-User-Id' => '1']);
        $body = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(3, $body['rating']);
        $this->assertSame('Changed my mind', $body['body']);
    }

    public function testUpdateOtherUserReturns403(): void
    {
        $created = $this->json($this->postReview(1, 1, 5));
        $res = $this->req('PUT', "/products/1/reviews/{$created['id']}", [
            'rating' => 1,
        ], ['X-User-Id' => '2']);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testUpdateNonExistentReviewReturns404(): void
    {
        $res = $this->req('PUT', '/products/1/reviews/99999', ['rating' => 3], ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testUpdateWithWrongProductIdReturns404(): void
    {
        $created = $this->json($this->postReview(1, 1, 5));
        // Review belongs to product 1, but we use product 2 in the URL
        $res = $this->req('PUT', "/products/2/reviews/{$created['id']}", ['rating' => 3], ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- delete ---

    public function testDeleteReturns204(): void
    {
        $created = $this->json($this->postReview(1, 1, 5));
        $res = $this->req('DELETE', "/products/1/reviews/{$created['id']}", null, ['X-User-Id' => '1']);
        $this->assertSame(204, $res->getStatusCode());
    }

    public function testDeleteOtherUserReturns403(): void
    {
        $created = $this->json($this->postReview(1, 1, 5));
        $res = $this->req('DELETE', "/products/1/reviews/{$created['id']}", null, ['X-User-Id' => '2']);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testDeleteNonExistentReturns404(): void
    {
        $res = $this->req('DELETE', '/products/1/reviews/99999', null, ['X-User-Id' => '1']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testAfterDeleteCanReviewAgain(): void
    {
        $created = $this->json($this->postReview(1, 1, 5, 'First'));
        $this->req('DELETE', "/products/1/reviews/{$created['id']}", null, ['X-User-Id' => '1']);

        $res = $this->postReview(1, 1, 4, 'Second try');
        $this->assertSame(201, $res->getStatusCode());
    }

    // --- infrastructure ---

    public function testSecurityHeadersPresent(): void
    {
        $res = $this->req('GET', '/products/1/reviews', null, ['X-User-Id' => '1']);
        $this->assertNotEmpty($res->getHeaderLine('Content-Security-Policy'));
        $this->assertNotEmpty($res->getHeaderLine('X-Request-Id'));
    }

    public function testUnknownRouteReturns404(): void
    {
        $res = $this->req('GET', '/nonexistent');
        $this->assertSame(404, $res->getStatusCode());
    }
}
