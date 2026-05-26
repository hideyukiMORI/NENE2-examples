<?php

declare(strict_types=1);

namespace Comment\Tests\Comment;

use Comment\Comment\CommentRepository;
use Comment\Comment\RouteRegistrar;
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

final class CommentTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/commentlog-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $repo      = new CommentRepository($executor);
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
        $res  = $this->req('POST', '/posts', ['title' => 'Hello World']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('Hello World', $body['title']);
    }

    public function testCreatePostMissingTitleReturns422(): void
    {
        $res = $this->req('POST', '/posts', ['title' => '']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testGetPostReturns200(): void
    {
        $this->req('POST', '/posts', ['title' => 'Test Post']);
        $res = $this->req('GET', '/posts/1');
        self::assertSame(200, $res->getStatusCode());
    }

    public function testGetNonExistentPostReturns404(): void
    {
        $res = $this->req('GET', '/posts/999');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Top-level comments ---

    public function testAddCommentReturns201(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post A']);
        $res  = $this->req('POST', '/posts/1/comments', ['author_name' => 'Alice', 'body' => 'Great post!']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('Alice', $body['author_name']);
        self::assertSame(0, $body['depth']);
        self::assertNull($body['parent_id']);
        self::assertSame('published', $body['status']);
    }

    public function testAddCommentMissingBodyReturns422(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post B']);
        $res = $this->req('POST', '/posts/1/comments', ['author_name' => 'Bob', 'body' => '']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testAddCommentToNonExistentPostReturns404(): void
    {
        $res = $this->req('POST', '/posts/999/comments', ['author_name' => 'X', 'body' => 'hi']);
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Replies ---

    public function testAddReplyReturns201WithCorrectDepth(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post C']);
        $parent = $this->decode($this->req('POST', '/posts/1/comments', ['author_name' => 'Alice', 'body' => 'Level 0']));

        $res  = $this->req('POST', "/comments/{$parent['id']}/replies", ['author_name' => 'Bob', 'body' => 'Level 1']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(1, $body['depth']);
        self::assertSame($parent['id'], $body['parent_id']);
    }

    public function testReplyToReplyReturns201WithDepth2(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post D']);
        $c0 = $this->decode($this->req('POST', '/posts/1/comments', ['author_name' => 'Alice', 'body' => 'Level 0']));
        $c1 = $this->decode($this->req('POST', "/comments/{$c0['id']}/replies", ['author_name' => 'Bob', 'body' => 'Level 1']));
        $res  = $this->req('POST', "/comments/{$c1['id']}/replies", ['author_name' => 'Carol', 'body' => 'Level 2']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(2, $body['depth']);
    }

    public function testReplyAtMaxDepthReturns422(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post E']);
        $c0 = $this->decode($this->req('POST', '/posts/1/comments', ['author_name' => 'A', 'body' => 'L0']));
        $c1 = $this->decode($this->req('POST', "/comments/{$c0['id']}/replies", ['author_name' => 'B', 'body' => 'L1']));
        $c2 = $this->decode($this->req('POST', "/comments/{$c1['id']}/replies", ['author_name' => 'C', 'body' => 'L2']));
        $c3 = $this->decode($this->req('POST', "/comments/{$c2['id']}/replies", ['author_name' => 'D', 'body' => 'L3']));

        // c3 is at depth 3 (MAX_DEPTH); trying to reply should return 422
        $res = $this->req('POST', "/comments/{$c3['id']}/replies", ['author_name' => 'E', 'body' => 'Too deep']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testReplyToNonExistentCommentReturns404(): void
    {
        $res = $this->req('POST', '/comments/999/replies', ['author_name' => 'X', 'body' => 'hi']);
        self::assertSame(404, $res->getStatusCode());
    }

    // --- List comments (tree) ---

    public function testListCommentsReturnsTree(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post F']);
        $c0 = $this->decode($this->req('POST', '/posts/1/comments', ['author_name' => 'Alice', 'body' => 'Root']));
        $this->req('POST', "/comments/{$c0['id']}/replies", ['author_name' => 'Bob', 'body' => 'Child']);

        $res  = $this->req('GET', '/posts/1/comments');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(1, $body['comments']);
        self::assertCount(1, $body['comments'][0]['children']);
        self::assertSame('Bob', $body['comments'][0]['children'][0]['author_name']);
    }

    public function testListCommentsEmptyPostReturnsEmptyArray(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post G']);
        $res  = $this->req('GET', '/posts/1/comments');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([], $body['comments']);
    }

    public function testListCommentsNonExistentPostReturns404(): void
    {
        $res = $this->req('GET', '/posts/999/comments');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Soft delete ---

    public function testDeleteCommentReturns204(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post H']);
        $c = $this->decode($this->req('POST', '/posts/1/comments', ['author_name' => 'Alice', 'body' => 'Delete me']));

        $res = $this->req('DELETE', "/comments/{$c['id']}");
        self::assertSame(204, $res->getStatusCode());
    }

    public function testDeletedCommentBodyIsReplaced(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post I']);
        $c = $this->decode($this->req('POST', '/posts/1/comments', ['author_name' => 'Alice', 'body' => 'Secret text']));
        $this->req('DELETE', "/comments/{$c['id']}");

        $res  = $this->req('GET', '/posts/1/comments');
        $body = $this->decode($res);

        self::assertSame('[deleted]', $body['comments'][0]['body']);
        self::assertSame('deleted', $body['comments'][0]['status']);
    }

    public function testDeletedCommentChildrenRemain(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post J']);
        $c0 = $this->decode($this->req('POST', '/posts/1/comments', ['author_name' => 'Alice', 'body' => 'Parent']));
        $this->req('POST', "/comments/{$c0['id']}/replies", ['author_name' => 'Bob', 'body' => 'Child survives']);
        $this->req('DELETE', "/comments/{$c0['id']}");

        $res  = $this->req('GET', '/posts/1/comments');
        $body = $this->decode($res);

        // Parent is deleted but child still exists
        self::assertSame('[deleted]', $body['comments'][0]['body']);
        self::assertCount(1, $body['comments'][0]['children']);
        self::assertSame('Child survives', $body['comments'][0]['children'][0]['body']);
    }

    public function testDeleteAlreadyDeletedCommentReturns409(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post K']);
        $c = $this->decode($this->req('POST', '/posts/1/comments', ['author_name' => 'Alice', 'body' => 'Once']));
        $this->req('DELETE', "/comments/{$c['id']}");

        $res = $this->req('DELETE', "/comments/{$c['id']}");
        self::assertSame(409, $res->getStatusCode());
    }

    public function testDeleteNonExistentCommentReturns404(): void
    {
        $res = $this->req('DELETE', '/comments/999');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testReplyToDeletedCommentReturns409(): void
    {
        $this->req('POST', '/posts', ['title' => 'Post L']);
        $c = $this->decode($this->req('POST', '/posts/1/comments', ['author_name' => 'Alice', 'body' => 'Will be deleted']));
        $this->req('DELETE', "/comments/{$c['id']}");

        $res = $this->req('POST', "/comments/{$c['id']}/replies", ['author_name' => 'Bob', 'body' => 'reply to deleted']);
        self::assertSame(409, $res->getStatusCode());
    }
}
