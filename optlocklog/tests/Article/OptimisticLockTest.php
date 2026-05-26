<?php

declare(strict_types=1);

namespace Opt\Tests\Article;

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
use Opt\Article\ArticleRepository;
use Opt\Article\RouteRegistrar;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OptimisticLockTest extends TestCase
{
    private RequestHandlerInterface $app;
    private ArticleRepository $repo;
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/optlocklog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
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

        $this->repo = new ArticleRepository($executor);
        $registrar  = new RouteRegistrar($this->repo, $json, $problems);

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

    /** @param array<string, mixed> $body */
    private function patch(string $path, array $body): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('PATCH', $path))
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

    // --- happy path ---

    public function testCreateArticleReturnsVersion1(): void
    {
        $res = $this->post('/articles', ['title' => 'Hello', 'body' => 'World']);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(1, $data['version']);
        $this->assertSame('Hello', $data['title']);
    }

    public function testSuccessfulUpdateIncrementsVersion(): void
    {
        $created = $this->json($this->post('/articles', ['title' => 'Hello', 'body' => 'World']));
        $id = $created['id'];

        $res = $this->patch("/articles/{$id}", [
            'title'   => 'Hello Updated',
            'body'    => 'New body',
            'version' => 1, // current version
        ]);

        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame(2, $data['version']); // version incremented
        $this->assertSame('Hello Updated', $data['title']);
    }

    public function testSuccessiveUpdatesIncrementVersionSequentially(): void
    {
        $created = $this->json($this->post('/articles', ['title' => 'v1', 'body' => 'body']));
        $id = $created['id'];

        $v2 = $this->json($this->patch("/articles/{$id}", ['title' => 'v2', 'body' => 'body', 'version' => 1]));
        $this->assertSame(2, $v2['version']);

        $v3 = $this->json($this->patch("/articles/{$id}", ['title' => 'v3', 'body' => 'body', 'version' => 2]));
        $this->assertSame(3, $v3['version']);
    }

    // --- optimistic lock conflict ---

    /**
     * Two concurrent readers both read version 1.
     * Writer A succeeds → version becomes 2.
     * Writer B tries to update with version 1 → 409 Conflict.
     * This is the classic lost-update prevention scenario.
     */
    public function testConcurrentWriterCauses409(): void
    {
        $created = $this->json($this->post('/articles', ['title' => 'Original', 'body' => 'body']));
        $id = $created['id'];

        // Writer A succeeds — version goes from 1 → 2
        $resA = $this->patch("/articles/{$id}", ['title' => 'Writer A', 'body' => 'body', 'version' => 1]);
        $this->assertSame(200, $resA->getStatusCode());

        // Writer B tries to update with stale version 1 → conflict
        $resB = $this->patch("/articles/{$id}", ['title' => 'Writer B', 'body' => 'body', 'version' => 1]);
        $this->assertSame(409, $resB->getStatusCode());

        $data = $this->json($resB);
        $this->assertStringContainsString('conflict', (string) ($data['type'] ?? ''));
    }

    /**
     * After a 409, the caller should re-fetch and retry with the current version.
     */
    public function testRetryAfterConflictSucceeds(): void
    {
        $created = $this->json($this->post('/articles', ['title' => 'Original', 'body' => 'body']));
        $id = $created['id'];

        // Writer A wins
        $this->patch("/articles/{$id}", ['title' => 'Writer A', 'body' => 'body', 'version' => 1]);

        // Writer B re-fetches and retries with current version 2
        $current = $this->json($this->get("/articles/{$id}"));
        $this->assertSame(2, $current['version']);

        $res = $this->patch("/articles/{$id}", ['title' => 'Writer B (retry)', 'body' => 'body', 'version' => 2]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(3, $this->json($res)['version']);
    }

    /**
     * The 409 response includes the current_version so the client can retry
     * without an extra GET request.
     */
    public function testConflictResponseIncludesCurrentVersion(): void
    {
        $created = $this->json($this->post('/articles', ['title' => 'Original', 'body' => 'body']));
        $id = $created['id'];

        // Advance to version 2
        $this->patch("/articles/{$id}", ['title' => 'v2', 'body' => 'body', 'version' => 1]);

        // Try stale version 1
        $res  = $this->patch("/articles/{$id}", ['title' => 'stale', 'body' => 'body', 'version' => 1]);
        $data = $this->json($res);

        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame(2, $data['current_version']); // client gets the current version
    }

    /**
     * After a conflict, the record must retain Writer A's content — not be
     * overwritten by Writer B's stale update.
     */
    public function testConflictDoesNotOverwriteWinnerContent(): void
    {
        $created = $this->json($this->post('/articles', ['title' => 'Original', 'body' => 'body']));
        $id = $created['id'];

        // Writer A wins with v1 → v2
        $this->patch("/articles/{$id}", ['title' => 'Writer A wins', 'body' => 'body', 'version' => 1]);

        // Writer B fails — title must NOT be written
        $this->patch("/articles/{$id}", ['title' => 'Writer B loses', 'body' => 'body', 'version' => 1]);

        $current = $this->json($this->get("/articles/{$id}"));
        $this->assertSame('Writer A wins', $current['title'], 'Winner content must be preserved');
    }

    // --- edge cases ---

    public function testUpdateNonExistentArticleReturns404(): void
    {
        $res = $this->patch('/articles/9999', ['title' => 'x', 'body' => 'x', 'version' => 1]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGetNonExistentArticleReturns404(): void
    {
        $res = $this->get('/articles/9999');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testMissingVersionFieldReturns400(): void
    {
        $created = $this->json($this->post('/articles', ['title' => 'x', 'body' => 'x']));
        $id = $created['id'];

        $res = $this->patch("/articles/{$id}", ['title' => 'x', 'body' => 'x']); // no version
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testVersionMustBeIntegerNotString(): void
    {
        $created = $this->json($this->post('/articles', ['title' => 'x', 'body' => 'x']));
        $id = $created['id'];

        $res = $this->patch("/articles/{$id}", ['title' => 'x', 'body' => 'x', 'version' => '1']); // string "1"
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testGetArticleReturnsCurrentState(): void
    {
        $created = $this->json($this->post('/articles', ['title' => 'Hello', 'body' => 'World']));
        $id = $created['id'];

        $res  = $this->get("/articles/{$id}");
        $data = $this->json($res);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Hello', $data['title']);
        $this->assertSame(1, $data['version']);
    }
}
