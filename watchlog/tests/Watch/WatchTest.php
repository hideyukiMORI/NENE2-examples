<?php

declare(strict_types=1);

namespace WatchLog\Tests\Watch;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use WatchLog\AppFactory;

class WatchTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $pdo->exec($schema);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, mixed> $query */
    private function req(string $method, string $path, mixed $body = null, array $query = []): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        if ($query !== []) {
            $request = $request->withQueryParams($query);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        assert(is_array($decoded));
        return $decoded;
    }

    private function add(string $title, string $type = 'movie', ?int $rating = null): int
    {
        $body = ['title' => $title, 'media_type' => $type];
        if ($rating !== null) {
            $body['rating'] = $rating;
        }
        $res = $this->req('POST', '/watch', $body);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    // ── enum validation ──────────────────────────────────────────────────────

    public function testCreateDefaultsToWantToWatch(): void
    {
        $id = $this->add('Inception');
        $data = $this->json($this->req('GET', '/watch/' . $id));
        $this->assertSame('want-to-watch', $data['status']);
        $this->assertSame('movie', $data['media_type']);
        $this->assertNull($data['rating']);
    }

    public function testInvalidMediaTypeRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/watch', ['title' => 'x', 'media_type' => 'podcast'])->getStatusCode());
    }

    public function testMissingMediaTypeRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/watch', ['title' => 'x'])->getStatusCode());
    }

    public function testInvalidStatusRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/watch', ['title' => 'x', 'media_type' => 'tv', 'status' => 'bingeing'])->getStatusCode());
    }

    // ── rating (nullable, 1-5, strict int) ────────────────────────────────────

    public function testRatingMustBeIntInRange(): void
    {
        $this->assertSame(422, $this->req('POST', '/watch', ['title' => 'x', 'media_type' => 'movie', 'rating' => 6])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/watch', ['title' => 'x', 'media_type' => 'movie', 'rating' => 4.0])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/watch', ['title' => 'x', 'media_type' => 'movie', 'rating' => '4'])->getStatusCode());
    }

    public function testPatchStatusCanClearRatingWithNull(): void
    {
        $id = $this->add('Movie', 'movie', 5);
        // explicit null clears the rating (array_key_exists distinguishes from absent)
        $res = $this->req('PATCH', '/watch/' . $id . '/status', ['status' => 'completed', 'rating' => null]);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('completed', $data['status']);
        $this->assertNull($data['rating']);
    }

    public function testPatchStatusKeepsRatingWhenAbsent(): void
    {
        $id = $this->add('Movie', 'movie', 5);
        $res = $this->req('PATCH', '/watch/' . $id . '/status', ['status' => 'watching']);
        $this->assertSame(5, $this->json($res)['rating']); // preserved
    }

    public function testPatchStatusRequiresStatus(): void
    {
        $id = $this->add('Movie');
        $this->assertSame(422, $this->req('PATCH', '/watch/' . $id . '/status', ['rating' => 3])->getStatusCode());
    }

    // ── archive / restore ──────────────────────────────────────────────────────

    public function testArchiveHidesFromDefaultList(): void
    {
        $id = $this->add('Old Movie');
        $this->req('POST', '/watch/' . $id . '/archive');

        // hidden by default
        $this->assertSame(0, $this->json($this->req('GET', '/watch'))['count']);
        // visible with include_archived
        $this->assertSame(1, $this->json($this->req('GET', '/watch', null, ['include_archived' => '1']))['count']);

        $data = $this->json($this->req('GET', '/watch/' . $id));
        $this->assertNotNull($data['archived_at']);
    }

    public function testRestoreBringsBack(): void
    {
        $id = $this->add('Movie');
        $this->req('POST', '/watch/' . $id . '/archive');
        $this->req('POST', '/watch/' . $id . '/restore');
        $this->assertSame(1, $this->json($this->req('GET', '/watch'))['count']);
        $this->assertNull($this->json($this->req('GET', '/watch/' . $id))['archived_at']);
    }

    // ── filters ────────────────────────────────────────────────────────────────

    public function testStatusAndTypeFilters(): void
    {
        $a = $this->add('Film', 'movie');
        $this->req('PATCH', '/watch/' . $a . '/status', ['status' => 'completed']);
        $this->add('Show', 'tv');

        $completed = $this->json($this->req('GET', '/watch', null, ['status' => 'completed']));
        $this->assertSame(1, $completed['count']);
        $this->assertSame('Film', $completed['items'][0]['title']);

        $tv = $this->json($this->req('GET', '/watch', null, ['media_type' => 'tv']));
        $this->assertSame(1, $tv['count']);
        $this->assertSame('Show', $tv['items'][0]['title']);
    }

    public function testInvalidFilterRejected(): void
    {
        $this->assertSame(422, $this->req('GET', '/watch', null, ['status' => 'nope'])->getStatusCode());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDelete(): void
    {
        $id = $this->add('Movie');
        $this->assertSame(204, $this->req('DELETE', '/watch/' . $id)->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/watch/' . $id)->getStatusCode());
    }
}
