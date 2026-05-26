<?php

declare(strict_types=1);

namespace SoftDelete\Tests\SoftDelete;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SoftDelete\RouteRegistrar;
use SoftDelete\SqliteNoteRepository;

final class SoftDeleteApiTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/softdelete-test-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $factory  = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17    = new Psr17Factory();
        $json     = new JsonResponseFactory($psr17, $psr17);
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $repo     = new SqliteNoteRepository($executor);

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

    private function req(string $method, string $uri, mixed $body = null): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')
                       ->withBody((new Psr17Factory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($req);
    }

    private function decode(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- POST /notes ---

    public function testCreateNote(): void
    {
        $res  = $this->req('POST', '/notes', ['title' => 'Hello', 'body' => 'World']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('Hello', $body['title']);
        self::assertNull($body['deleted_at']);
    }

    public function testCreateNoteMissingTitleReturns422(): void
    {
        $res = $this->req('POST', '/notes', (object) []);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- GET /notes ---

    public function testListActiveExcludesSoftDeleted(): void
    {
        $this->req('POST', '/notes', ['title' => 'Keep']);
        $this->req('POST', '/notes', ['title' => 'Delete me']);
        $this->req('DELETE', '/notes/2');

        $res  = $this->req('GET', '/notes');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(1, $body['items']);
        self::assertSame('Keep', $body['items'][0]['title']);
    }

    // --- GET /notes/trash ---

    public function testListTrashedOnlyShowsDeleted(): void
    {
        $this->req('POST', '/notes', ['title' => 'Keep']);
        $this->req('POST', '/notes', ['title' => 'Trash me']);
        $this->req('DELETE', '/notes/2');

        $res  = $this->req('GET', '/notes/trash');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(1, $body['items']);
        self::assertSame('Trash me', $body['items'][0]['title']);
        self::assertNotNull($body['items'][0]['deleted_at']);
    }

    // --- GET /notes/{id} ---

    public function testGetNote(): void
    {
        $this->req('POST', '/notes', ['title' => 'Note A']);
        $res  = $this->req('GET', '/notes/1');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('Note A', $body['title']);
    }

    public function testGetSoftDeletedNoteReturns404(): void
    {
        $this->req('POST', '/notes', ['title' => 'Note B']);
        $this->req('DELETE', '/notes/1');

        $res = $this->req('GET', '/notes/1');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testGetNotFoundReturns404(): void
    {
        $res = $this->req('GET', '/notes/999');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- DELETE /notes/{id} ---

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $this->req('POST', '/notes', ['title' => 'Note C']);
        $res  = $this->req('DELETE', '/notes/1');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertNotNull($body['deleted_at']);
    }

    public function testSoftDeleteAlreadyDeletedNoteReturns404(): void
    {
        $this->req('POST', '/notes', ['title' => 'Note D']);
        $this->req('DELETE', '/notes/1');
        $res = $this->req('DELETE', '/notes/1');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testSoftDeleteNotFoundReturns404(): void
    {
        $res = $this->req('DELETE', '/notes/999');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- POST /notes/{id}/restore ---

    public function testRestoreNote(): void
    {
        $this->req('POST', '/notes', ['title' => 'Note E']);
        $this->req('DELETE', '/notes/1');

        $res  = $this->req('POST', '/notes/1/restore');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertNull($body['deleted_at']);
    }

    public function testRestoreRestoredNoteIsVisibleInList(): void
    {
        $this->req('POST', '/notes', ['title' => 'Note F']);
        $this->req('DELETE', '/notes/1');
        $this->req('POST', '/notes/1/restore');

        $res  = $this->req('GET', '/notes');
        $body = $this->decode($res);

        self::assertCount(1, $body['items']);
    }

    public function testRestoreActiveNoteReturns404(): void
    {
        $this->req('POST', '/notes', ['title' => 'Note G']);
        $res = $this->req('POST', '/notes/1/restore');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testRestoreNotFoundReturns404(): void
    {
        $res = $this->req('POST', '/notes/999/restore');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- DELETE /notes/{id}/purge ---

    public function testPurgePermanentlyDeletesNote(): void
    {
        $this->req('POST', '/notes', ['title' => 'Note H']);
        $this->req('DELETE', '/notes/1');

        $res  = $this->req('DELETE', '/notes/1/purge');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($body['deleted']);
    }

    public function testPurgedNoteNotFoundAnywhere(): void
    {
        $this->req('POST', '/notes', ['title' => 'Note I']);
        $this->req('DELETE', '/notes/1');
        $this->req('DELETE', '/notes/1/purge');

        // Not in active list
        $active = $this->decode($this->req('GET', '/notes'));
        self::assertCount(0, $active['items']);

        // Not in trash
        $trash = $this->decode($this->req('GET', '/notes/trash'));
        self::assertCount(0, $trash['items']);
    }

    public function testPurgeActiveNoteReturns404(): void
    {
        $this->req('POST', '/notes', ['title' => 'Note J']);
        $res = $this->req('DELETE', '/notes/1/purge');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testPurgeNotFoundReturns404(): void
    {
        $res = $this->req('DELETE', '/notes/999/purge');
        self::assertSame(404, $res->getStatusCode());
    }
}
