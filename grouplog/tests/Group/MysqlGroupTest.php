<?php

declare(strict_types=1);

namespace Group\Tests\Group;

use Group\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * FT138 MySQL integration tests.
 *
 * Skipped unless MYSQL_HOST env var is set.
 */
final class MysqlGroupTest extends TestCase
{
    private ?\PDO $pdo    = null;
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

        // Clean and recreate schema
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS memberships');
        $this->pdo->exec('DROP TABLE IF EXISTS user_groups');
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.mysql.sql'));

        $this->app = AppFactory::createMysqlApp($host, $port, $database, $user, $password);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->exec('DROP TABLE IF EXISTS memberships');
            $this->pdo->exec('DROP TABLE IF EXISTS user_groups');
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
        $req = new ServerRequest('POST', '/users');
        $req = $req->withBody(Stream::create((string) json_encode(['name' => $name])))
                   ->withHeader('Content-Type', 'application/json');

        return (int) $this->json($this->app->handle($req))['id'];
    }

    public function testMysqlCreateGroupAndListMembers(): void
    {
        $alice   = $this->createUser('Alice');
        $res     = $this->request('POST', '/groups', ['name' => 'Dev Team'], actorId: (string) $alice);

        $this->assertSame(201, $res->getStatusCode());
        $groupId = (int) $this->json($res)['id'];

        $list = $this->json($this->request('GET', "/groups/{$groupId}/members", actorId: (string) $alice));
        $this->assertSame(1, $list['count']);
        $this->assertSame('owner', $list['items'][0]['role']);
    }

    public function testMysqlAddAndRemoveMember(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = (int) $this->json($this->request('POST', '/groups', ['name' => 'Team'], actorId: (string) $alice))['id'];

        $add = $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);
        $this->assertSame(201, $add->getStatusCode());

        $remove = $this->request('DELETE', "/groups/{$groupId}/members/{$bob}", actorId: (string) $alice);
        $this->assertSame(204, $remove->getStatusCode());

        $list = $this->json($this->request('GET', "/groups/{$groupId}/members", actorId: (string) $alice));
        $this->assertSame(1, $list['count']); // only owner remains
    }

    public function testMysqlDuplicateMemberReturns409(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = (int) $this->json($this->request('POST', '/groups', ['name' => 'Team'], actorId: (string) $alice))['id'];

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);
        $res = $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);

        $this->assertSame(409, $res->getStatusCode());
    }

    public function testMysqlRoleChange(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = (int) $this->json($this->request('POST', '/groups', ['name' => 'Team'], actorId: (string) $alice))['id'];

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);
        $res = $this->request('PUT', "/groups/{$groupId}/members/{$bob}/role", ['role' => 'admin'], actorId: (string) $alice);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('admin', $this->json($res)['role']);
    }

    public function testMysqlNonMemberCannotViewMembers(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = (int) $this->json($this->request('POST', '/groups', ['name' => 'Private'], actorId: (string) $alice))['id'];

        $res = $this->request('GET', "/groups/{$groupId}/members", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }
}
