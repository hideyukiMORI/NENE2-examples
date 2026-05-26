<?php

declare(strict_types=1);

namespace Version\Tests;

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
use Version\Shared\NoteRepository;
use Version\V1\RouteRegistrar as V1Registrar;
use Version\V2\RouteRegistrar as V2Registrar;

final class ApiVersioningTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/versionlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig = new DatabaseConfig(
            url: null,
            environment: 'test',
            adapter: 'sqlite',
            host: '',
            port: 1,
            name: $this->dbFile,
            user: '',
            password: '',
            charset: '',
        );

        $factory  = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17    = new Psr17Factory();
        $json     = new JsonResponseFactory($psr17, $psr17);
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $notes    = new NoteRepository($executor);

        $v1 = new V1Registrar($notes, $json, $problems);
        $v2 = new V2Registrar($notes, $json, $problems);

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [
                static fn (Router $r) => $v1->register($r),
                static fn (Router $r) => $v2->register($r),
            ],
        ))->create();
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // --- helpers ---

    /** @param array<string, mixed>|null $body */
    private function post(string $path, ?array $body = null): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body ?? [], JSON_THROW_ON_ERROR));
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
    private function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- v1 response shape ---

    public function testV1CreateUsesContentFieldName(): void
    {
        $res  = $this->post('/v1/notes', ['title' => 'Hello', 'content' => 'World']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertArrayHasKey('content', $body);
        self::assertArrayNotHasKey('body', $body);
        self::assertArrayNotHasKey('tags', $body);
        self::assertArrayNotHasKey('updated_at', $body);
        self::assertSame('World', $body['content']);
    }

    public function testV1ListWrapsInNotesKey(): void
    {
        $this->post('/v1/notes', ['title' => 'A']);
        $body = $this->decode($this->get('/v1/notes'));

        self::assertArrayHasKey('notes', $body);
        self::assertArrayNotHasKey('data', $body);
        self::assertArrayNotHasKey('meta', $body);
    }

    // --- v1 deprecation headers ---

    public function testV1ResponseCarriesDeprecationHeader(): void
    {
        $res = $this->get('/v1/notes');

        self::assertSame('true', $res->getHeaderLine('Deprecation'));
    }

    public function testV1ResponseCarriesSunsetHeader(): void
    {
        $res = $this->get('/v1/notes');

        self::assertNotEmpty($res->getHeaderLine('Sunset'));
    }

    public function testV1ResponseCarriesLinkToSuccessor(): void
    {
        $res = $this->get('/v1/notes');

        self::assertStringContainsString('successor-version', $res->getHeaderLine('Link'));
        self::assertStringContainsString('/v2/notes', $res->getHeaderLine('Link'));
    }

    public function testV1CreateAlsoCarriesDeprecationHeaders(): void
    {
        $res = $this->post('/v1/notes', ['title' => 'Deprecated create']);

        self::assertSame('true', $res->getHeaderLine('Deprecation'));
        self::assertNotEmpty($res->getHeaderLine('Sunset'));
    }

    // --- v2 response shape ---

    public function testV2CreateUsesBodyFieldName(): void
    {
        $res  = $this->post('/v2/notes', ['title' => 'Hello', 'body' => 'World', 'tags' => ['php', 'api']]);
        $data = $this->decode($res)['data'];

        self::assertSame(201, $res->getStatusCode());
        self::assertArrayHasKey('body', $data);
        self::assertArrayNotHasKey('content', $data);
        self::assertSame('World', $data['body']);
        self::assertSame(['php', 'api'], $data['tags']);
        self::assertArrayHasKey('updated_at', $data);
    }

    public function testV2ListWrapsInDataWithMeta(): void
    {
        $this->post('/v2/notes', ['title' => 'B']);
        $body = $this->decode($this->get('/v2/notes'));

        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);
        self::assertArrayNotHasKey('notes', $body);
        self::assertArrayHasKey('limit', $body['meta']);
        self::assertArrayHasKey('offset', $body['meta']);
    }

    // --- v2 has no deprecation headers ---

    public function testV2ResponseHasNoDeprecationHeader(): void
    {
        $res = $this->get('/v2/notes');

        self::assertEmpty($res->getHeaderLine('Deprecation'));
        self::assertEmpty($res->getHeaderLine('Sunset'));
    }

    // --- shared storage: v1 and v2 read the same data ---

    public function testV1CreatedNoteIsAccessibleInV2(): void
    {
        $v1Res = $this->post('/v1/notes', ['title' => 'Cross-version', 'content' => 'Shared body']);
        $id    = $this->decode($v1Res)['id'];

        $v2Data = $this->decode($this->get('/v2/notes/' . $id))['data'];

        self::assertSame('Cross-version', $v2Data['title']);
        self::assertSame('Shared body', $v2Data['body']);
    }

    public function testV2CreatedNoteIsAccessibleInV1(): void
    {
        $v2Res = $this->post('/v2/notes', ['title' => 'From v2', 'body' => 'Content', 'tags' => ['x']]);
        $id    = $this->decode($v2Res)['data']['id'];

        $v1Body = $this->decode($this->get('/v1/notes/' . $id));

        self::assertSame('From v2', $v1Body['title']);
        self::assertSame('Content', $v1Body['content']);
        self::assertArrayNotHasKey('tags', $v1Body); // v1 doesn't expose tags
    }

    // --- validation ---

    public function testV1CreateWithoutTitleReturns422(): void
    {
        $res = $this->post('/v1/notes', ['content' => 'no title']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testV2CreateWithoutTitleReturns422(): void
    {
        $res = $this->post('/v2/notes', ['body' => 'no title']);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- unknown version returns 404 ---

    public function testV3ReturnsNotFound(): void
    {
        $res = $this->get('/v3/notes');
        self::assertSame(404, $res->getStatusCode());
    }
}
