<?php

declare(strict_types=1);

namespace Emoji\Tests\Emoji;

use Emoji\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * FT143 MySQL integration tests.
 *
 * Skipped unless MYSQL_HOST env var is set.
 */
final class MysqlEmojiTest extends TestCase
{
    private ?\PDO $pdo = null;
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $hostEnv = getenv('MYSQL_HOST');

        if ($hostEnv === false || $hostEnv === '') {
            $this->markTestSkipped('MYSQL_HOST not set — skipping MySQL tests');
        }

        $host = $hostEnv;

        $port     = (int) (getenv('MYSQL_PORT') ?: 3306);
        $database = (string) (getenv('MYSQL_DATABASE') ?: 'ft_test');
        $user     = (string) (getenv('MYSQL_USER') ?: 'ft_user');
        $password = (string) (getenv('MYSQL_PASSWORD') ?: 'ft_pass');

        $this->pdo = new \PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $user,
            $password,
        );
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS reactions');
        $this->pdo->exec('DROP TABLE IF EXISTS posts');
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.mysql.sql'));

        $this->app = AppFactory::createMysqlApp($host, $port, $database, $user, $password);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->exec('DROP TABLE IF EXISTS reactions');
            $this->pdo->exec('DROP TABLE IF EXISTS posts');
            $this->pdo->exec('DROP TABLE IF EXISTS users');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $this->pdo = null;
        }
    }

    private function request(string $method, string $path, mixed $body = null, string $actorId = ''): ResponseInterface
    {
        $req = new ServerRequest($method, $path);

        if ($body !== null) {
            $req = $req
                ->withBody(Stream::create((string) json_encode($body)))
                ->withHeader('Content-Type', 'application/json');
        }

        if ($actorId !== '') {
            $req = $req->withHeader('X-User-Id', $actorId);
        }

        return $this->app->handle($req);
    }

    private function json(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function createUser(string $name): int
    {
        return (int) $this->json($this->request('POST', '/users', ['name' => $name]))['id'];
    }

    private function createPost(int $userId, string $content): int
    {
        return (int) $this->json($this->request('POST', '/posts', ['content' => $content], actorId: (string) $userId))['id'];
    }

    public function testMysqlAddReactionAndGetCounts(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $postId = $this->createPost($alice, 'Hello MySQL');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $bob);

        $data = $this->json($this->request('GET', "/posts/{$postId}/reactions"));
        $this->assertSame(2, $data['counts']['👍']);
        $this->assertSame(2, $data['total']);
    }

    public function testMysqlDuplicateReactionReturns409(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '❤️'], actorId: (string) $alice);
        $res = $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '❤️'], actorId: (string) $alice);

        $this->assertSame(409, $res->getStatusCode());
    }

    public function testMysqlRemoveReaction(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $res = $this->request('DELETE', "/posts/{$postId}/reactions/👍", actorId: (string) $alice);
        $this->assertSame(204, $res->getStatusCode());

        $data = $this->json($this->request('GET', "/posts/{$postId}/reactions"));
        $this->assertSame(0, $data['total']);
    }

    public function testMysqlUserReactions(): void
    {
        $alice  = $this->createUser('Alice');
        $postId = $this->createPost($alice, 'Hello');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '😊'], actorId: (string) $alice);

        $data = $this->json($this->request('GET', "/posts/{$postId}/reactions", actorId: (string) $alice));
        $this->assertContains('👍', $data['user_reactions']);
        $this->assertContains('😊', $data['user_reactions']);
    }

    public function testMysqlMultipleEmojisOnSamePost(): void
    {
        $alice  = $this->createUser('Alice');
        $bob    = $this->createUser('Bob');
        $postId = $this->createPost($alice, 'Hello');

        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $alice);
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '❤️'], actorId: (string) $alice);
        $this->request('POST', "/posts/{$postId}/reactions", ['emoji' => '👍'], actorId: (string) $bob);

        $data = $this->json($this->request('GET', "/posts/{$postId}/reactions"));
        $this->assertSame(2, $data['counts']['👍']);
        $this->assertSame(1, $data['counts']['❤️']);
        $this->assertSame(3, $data['total']);
    }
}
