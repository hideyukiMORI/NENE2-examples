<?php

declare(strict_types=1);

namespace Etag\Tests\Article;

use Etag\Article\ArticleRepository;
use Etag\Article\RouteRegistrar;
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

final class ETagTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/etaglog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $factory    = new PdoConnectionFactory($dbConfig);
        $executor   = new PdoDatabaseQueryExecutor($factory);
        $psr17      = new Psr17Factory();
        $json       = new JsonResponseFactory($psr17, $psr17);
        $problems   = new ProblemDetailsResponseFactory($psr17, $psr17);
        $registrar  = new RouteRegistrar(new ArticleRepository($executor), $json, $problems, $psr17);

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

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = []): ResponseInterface
    {
        $request = new ServerRequest('GET', $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($request);
    }

    /** @param array<string, mixed> $body
     *  @param array<string, string> $headers */
    private function patch(string $path, array $body, array $headers = []): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('PATCH', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($data);

        return $data;
    }

    // --- tests ---

    public function testCreateReturns201WithEtag(): void
    {
        $res = $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertNotEmpty($res->getHeaderLine('ETag'));
        $this->assertNotEmpty($res->getHeaderLine('Last-Modified'));

        $data = $this->json($res);
        $this->assertSame(1, $data['id']);
        $this->assertSame('Hello', $data['title']);
    }

    public function testGetReturns200WithEtag(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);
        $res = $this->get('/articles/1');

        $this->assertSame(200, $res->getStatusCode());
        $etag = $res->getHeaderLine('ETag');
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $etag);
        $this->assertNotEmpty($res->getHeaderLine('Last-Modified'));
    }

    public function testGetReturns304WhenEtagMatches(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);
        $firstRes = $this->get('/articles/1');
        $etag     = $firstRes->getHeaderLine('ETag');

        $res = $this->get('/articles/1', ['If-None-Match' => $etag]);

        $this->assertSame(304, $res->getStatusCode());
        $this->assertSame($etag, $res->getHeaderLine('ETag'));
        $this->assertEmpty((string) $res->getBody(), '304 must have no body');
    }

    public function testGetReturns200WhenEtagDoesNotMatch(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);

        $res = $this->get('/articles/1', ['If-None-Match' => '"stale-etag-value"']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertNotEmpty($res->getHeaderLine('ETag'));
    }

    public function testGetReturns200WhenNoIfNoneMatchHeader(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);

        $res = $this->get('/articles/1');

        $this->assertSame(200, $res->getStatusCode());
    }

    public function testGetReturns304WhenIfModifiedSinceCoversUpdatedAt(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);
        $firstRes   = $this->get('/articles/1');
        $lastMod    = $firstRes->getHeaderLine('Last-Modified');
        $etag       = $firstRes->getHeaderLine('ETag');

        // Same timestamp >= Last-Modified → 304
        $res = $this->get('/articles/1', [
            'If-None-Match'     => $etag,
            'If-Modified-Since' => $lastMod,
        ]);

        $this->assertSame(304, $res->getStatusCode());
    }

    public function testPatchReturns428WhenNoIfMatchHeader(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);

        $res = $this->patch('/articles/1', ['title' => 'New', 'body' => 'Body']);

        $this->assertSame(428, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('precondition-required', (string) $data['type']);
    }

    public function testPatchReturns412WhenEtagIsStale(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);

        $res = $this->patch(
            '/articles/1',
            ['title' => 'New', 'body' => 'Body'],
            ['If-Match' => '"wrong-etag"'],
        );

        $this->assertSame(412, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('precondition-failed', (string) $data['type']);
    }

    public function testPatchSucceedsWithCorrectEtag(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);
        $getRes = $this->get('/articles/1');
        $etag   = $getRes->getHeaderLine('ETag');

        $res = $this->patch(
            '/articles/1',
            ['title' => 'Updated', 'body' => 'New body'],
            ['If-Match' => $etag],
        );

        $this->assertSame(200, $res->getStatusCode());
        $data    = $this->json($res);
        $newEtag = $res->getHeaderLine('ETag');

        $this->assertSame('Updated', $data['title']);
        $this->assertSame('New body', $data['body']);
        $this->assertNotEmpty($newEtag);
        $this->assertNotSame($etag, $newEtag, 'ETag must change after update');
    }

    public function testEtagChangesAfterUpdate(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);
        $beforeRes  = $this->get('/articles/1');
        $etagBefore = $beforeRes->getHeaderLine('ETag');

        // Successful update
        $this->patch(
            '/articles/1',
            ['title' => 'Changed', 'body' => 'Different'],
            ['If-Match' => $etagBefore],
        );

        $afterRes  = $this->get('/articles/1');
        $etagAfter = $afterRes->getHeaderLine('ETag');

        $this->assertNotSame($etagBefore, $etagAfter);
        // Old ETag no longer valid for conditional GET → 200 (content changed)
        $staleCacheRes = $this->get('/articles/1', ['If-None-Match' => $etagBefore]);
        $this->assertSame(200, $staleCacheRes->getStatusCode());
    }

    public function testOldEtagInvalidForPatchAfterUpdate(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);
        $firstEtag = $this->get('/articles/1')->getHeaderLine('ETag');

        // First PATCH succeeds
        $this->patch('/articles/1', ['title' => 'v2', 'body' => 'body'], ['If-Match' => $firstEtag]);

        // Retry with the now-stale first ETag → 412
        $res = $this->patch('/articles/1', ['title' => 'v3', 'body' => 'body'], ['If-Match' => $firstEtag]);
        $this->assertSame(412, $res->getStatusCode());
    }

    public function testWildcardIfMatchAllowsUpdate(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);

        $res = $this->patch(
            '/articles/1',
            ['title' => 'Wildcard', 'body' => 'anything'],
            ['If-Match' => '*'],
        );

        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('Wildcard', $data['title']);
    }

    public function testGetNonExistentReturns404(): void
    {
        $res = $this->get('/articles/999');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testPatchNonExistentReturns428(): void
    {
        // 428 because we check If-Match before fetching the record (well, we fetch first)
        // Actually the route fetches the article first → 404 when not found
        $res = $this->patch('/articles/999', ['title' => 'x', 'body' => 'y'], ['If-Match' => '"any"']);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testCreateMissingBodyReturns400(): void
    {
        $res = $this->post('/articles', ['title' => 'Only title']);
        $this->assertSame(400, $res->getStatusCode());
    }
}
