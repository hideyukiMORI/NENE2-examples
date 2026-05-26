<?php

declare(strict_types=1);

namespace Tag\Tests\Tag;

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
use Tag\Tag\RouteRegistrar;
use Tag\Tag\TagRepository;

final class TaggingTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/taglog-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $repo      = new TagRepository($executor);
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

    // --- Posts ---

    public function testCreatePostReturns201(): void
    {
        $res  = $this->req('POST', '/posts', ['title' => 'Hello World', 'body' => 'First post.']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('Hello World', $body['title']);
        self::assertSame([], $body['tags']);
    }

    public function testCreatePostMissingTitleReturns422(): void
    {
        $res = $this->req('POST', '/posts', ['body' => 'No title']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testGetPostReturns200WithTags(): void
    {
        $this->req('POST', '/posts', ['title' => 'Tagged Post', 'body' => '']);
        $this->req('POST', '/tags', ['name' => 'php']);
        $this->req('PUT', '/posts/1/tags', ['tags' => ['php']]);

        $res  = $this->req('GET', '/posts/1');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(1, $body['tags']);
        self::assertSame('php', $body['tags'][0]['name']);
    }

    public function testGetNonExistentPostReturns404(): void
    {
        $res = $this->req('GET', '/posts/999');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Tags ---

    public function testCreateTagReturns201(): void
    {
        $res  = $this->req('POST', '/tags', ['name' => 'php']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('php', $body['name']);
    }

    public function testCreateDuplicateTagReturns409(): void
    {
        $this->req('POST', '/tags', ['name' => 'php']);
        $res = $this->req('POST', '/tags', ['name' => 'php']);
        self::assertSame(409, $res->getStatusCode());
    }

    public function testCreateTagMissingNameReturns422(): void
    {
        $res = $this->req('POST', '/tags', ['name' => '']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testListTagsReturnsAll(): void
    {
        $this->req('POST', '/tags', ['name' => 'php']);
        $this->req('POST', '/tags', ['name' => 'api']);
        $res  = $this->req('GET', '/tags');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(2, $body['tags']);
        // Tags are alphabetically sorted
        self::assertSame('api', $body['tags'][0]['name']);
        self::assertSame('php', $body['tags'][1]['name']);
    }

    public function testListTagsEmptyReturnsEmptyArray(): void
    {
        $res  = $this->req('GET', '/tags');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([], $body['tags']);
    }

    // --- Set post tags ---

    public function testSetPostTagsAssignsTags(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post A', 'body' => '']);
        $this->req('POST', '/tags', ['name' => 'php']);
        $this->req('POST', '/tags', ['name' => 'api']);

        $res  = $this->req('PUT', '/posts/1/tags', ['tags' => ['php', 'api']]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        $names = array_column($body['tags'], 'name');
        self::assertContains('php', $names);
        self::assertContains('api', $names);
    }

    public function testSetPostTagsReplacesExistingTags(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post B', 'body' => '']);
        $this->req('POST', '/tags', ['name' => 'old']);
        $this->req('POST', '/tags', ['name' => 'new']);
        $this->req('PUT', '/posts/1/tags', ['tags' => ['old']]);

        $res  = $this->req('PUT', '/posts/1/tags', ['tags' => ['new']]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        $names = array_column($body['tags'], 'name');
        self::assertContains('new', $names);
        self::assertNotContains('old', $names);
    }

    public function testSetPostTagsClearsAllWhenEmptyArray(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post C', 'body' => '']);
        $this->req('POST', '/tags', ['name' => 'php']);
        $this->req('PUT', '/posts/1/tags', ['tags' => ['php']]);

        $res  = $this->req('PUT', '/posts/1/tags', ['tags' => []]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([], $body['tags']);
    }

    public function testSetPostTagsIgnoresNonExistentTags(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post D', 'body' => '']);
        $this->req('POST', '/tags', ['name' => 'real']);

        $res  = $this->req('PUT', '/posts/1/tags', ['tags' => ['real', 'ghost']]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        $names = array_column($body['tags'], 'name');
        self::assertContains('real', $names);
        self::assertNotContains('ghost', $names);
    }

    public function testSetTagsForNonExistentPostReturns404(): void
    {
        $res = $this->req('PUT', '/posts/999/tags', ['tags' => []]);
        self::assertSame(404, $res->getStatusCode());
    }

    public function testDuplicateTagNamesInBodyAreDeduped(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post E', 'body' => '']);
        $this->req('POST', '/tags', ['name' => 'php']);

        $res  = $this->req('PUT', '/posts/1/tags', ['tags' => ['php', 'php', 'php']]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(1, $body['tags']);
    }

    // --- List posts by tag ---

    public function testListPostsByTagReturnsTaggedPosts(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post 1', 'body' => '']);
        $this->req('POST', '/posts', ['title' => 'Post 2', 'body' => '']);
        $this->req('POST', '/tags', ['name' => 'php']);
        $this->req('PUT', '/posts/1/tags', ['tags' => ['php']]);
        $this->req('PUT', '/posts/2/tags', ['tags' => ['php']]);

        $res  = $this->req('GET', '/tags/php/posts');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(2, $body['posts']);
    }

    public function testListPostsByTagExcludesUntaggedPosts(): void
    {
        $this->req('POST', '/posts', ['title' => 'Tagged', 'body' => '']);
        $this->req('POST', '/posts', ['title' => 'Untagged', 'body' => '']);
        $this->req('POST', '/tags', ['name' => 'php']);
        $this->req('PUT', '/posts/1/tags', ['tags' => ['php']]);

        $res  = $this->req('GET', '/tags/php/posts');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(1, $body['posts']);
        self::assertSame('Tagged', $body['posts'][0]['title']);
    }

    public function testListPostsByTagReturnsTagsOnEachPost(): void
    {
        $this->req('POST', '/posts', ['title' => 'Multi-tagged', 'body' => '']);
        $this->req('POST', '/tags', ['name' => 'php']);
        $this->req('POST', '/tags', ['name' => 'api']);
        $this->req('PUT', '/posts/1/tags', ['tags' => ['php', 'api']]);

        $res  = $this->req('GET', '/tags/php/posts');
        $body = $this->decode($res);

        $names = array_column($body['posts'][0]['tags'], 'name');
        self::assertContains('php', $names);
        self::assertContains('api', $names);
    }

    public function testListPostsByNonExistentTagReturns404(): void
    {
        $res = $this->req('GET', '/tags/ghost/posts');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testListPostsByTagEmptyResultIsArray(): void
    {
        $this->req('POST', '/tags', ['name' => 'empty']);

        $res  = $this->req('GET', '/tags/empty/posts');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([], $body['posts']);
    }
}
