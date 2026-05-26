<?php

declare(strict_types=1);

namespace Group\Tests\Group;

use Group\AppFactory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class GroupTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/grouplog-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $this->app = AppFactory::createSqliteApp($this->dbFile);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
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
        $res = $this->request('POST', '/users', ['name' => $name]);
        $this->assertSame(201, $res->getStatusCode());

        return (int) $this->json($res)['id'];
    }

    private function createGroup(int $ownerId, string $name = 'Test Group'): int
    {
        $res = $this->request('POST', '/groups', ['name' => $name], actorId: (string) $ownerId);
        $this->assertSame(201, $res->getStatusCode());

        return (int) $this->json($res)['id'];
    }

    // --- Group creation ---

    public function testCreateGroup(): void
    {
        $alice   = $this->createUser('Alice');
        $res     = $this->request('POST', '/groups', ['name' => 'Dev Team'], actorId: (string) $alice);
        $body    = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('Dev Team', $body['name']);
        $this->assertSame($alice, $body['owner_id']);
    }

    public function testCreateGroupOwnerIsAutomaticallyMember(): void
    {
        $alice   = $this->createUser('Alice');
        $groupId = $this->createGroup($alice);

        $res   = $this->request('GET', "/groups/{$groupId}/members", actorId: (string) $alice);
        $body  = $this->json($res);
        $roles = array_column($body['items'], 'role');

        $this->assertSame(1, $body['count']);
        $this->assertContains('owner', $roles);
    }

    public function testCreateGroupUnknownActorReturns404(): void
    {
        $res = $this->request('POST', '/groups', ['name' => 'Test'], actorId: '9999');
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- List members ---

    public function testListMembersNonMemberReturns403(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $res = $this->request('GET', "/groups/{$groupId}/members", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testListMembersUnknownGroupReturns404(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('GET', '/groups/9999/members', actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Add member ---

    public function testOwnerCanAddMember(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $res = $this->request('POST', "/groups/{$groupId}/members", [
            'user_id' => $bob,
            'role'    => 'member',
        ], actorId: (string) $alice);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('member', $this->json($res)['role']);
    }

    public function testOwnerCanAddAdmin(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $res = $this->request('POST', "/groups/{$groupId}/members", [
            'user_id' => $bob,
            'role'    => 'admin',
        ], actorId: (string) $alice);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('admin', $this->json($res)['role']);
    }

    public function testAdminCanAddMember(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $carol   = $this->createUser('Carol');
        $groupId = $this->createGroup($alice);

        // Make Bob an admin
        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'admin'], actorId: (string) $alice);

        // Bob adds Carol
        $res = $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $carol, 'role' => 'member'], actorId: (string) $bob);
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testRegularMemberCannotAddMember(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $carol   = $this->createUser('Carol');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);

        $res = $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $carol, 'role' => 'member'], actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testAddDuplicateMemberReturns409(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);
        $res = $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);

        $this->assertSame(409, $res->getStatusCode());
    }

    public function testCannotAddOwnerRole(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $res = $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'owner'], actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode());
    }

    // --- Remove member ---

    public function testOwnerCanRemoveMember(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);
        $res = $this->request('DELETE', "/groups/{$groupId}/members/{$bob}", actorId: (string) $alice);

        $this->assertSame(204, $res->getStatusCode());
    }

    public function testMemberCanLeaveOwnGroup(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);
        $res = $this->request('DELETE', "/groups/{$groupId}/members/{$bob}", actorId: (string) $bob);

        $this->assertSame(204, $res->getStatusCode());
    }

    public function testRegularMemberCannotRemoveOtherMember(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $carol   = $this->createUser('Carol');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);
        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $carol, 'role' => 'member'], actorId: (string) $alice);

        $res = $this->request('DELETE', "/groups/{$groupId}/members/{$carol}", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testCannotRemoveOwner(): void
    {
        $alice   = $this->createUser('Alice');
        $groupId = $this->createGroup($alice);

        $res = $this->request('DELETE', "/groups/{$groupId}/members/{$alice}", actorId: (string) $alice);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testRemoveNonMemberReturns404(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $res = $this->request('DELETE', "/groups/{$groupId}/members/{$bob}", actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode());
    }

    // --- Change role ---

    public function testOwnerCanPromoteToAdmin(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);
        $res = $this->request('PUT', "/groups/{$groupId}/members/{$bob}/role", ['role' => 'admin'], actorId: (string) $alice);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('admin', $this->json($res)['role']);
    }

    public function testOwnerCanDemoteAdmin(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'admin'], actorId: (string) $alice);
        $res = $this->request('PUT', "/groups/{$groupId}/members/{$bob}/role", ['role' => 'member'], actorId: (string) $alice);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('member', $this->json($res)['role']);
    }

    public function testAdminCannotChangeRoles(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $carol   = $this->createUser('Carol');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'admin'], actorId: (string) $alice);
        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $carol, 'role' => 'member'], actorId: (string) $alice);

        $res = $this->request('PUT', "/groups/{$groupId}/members/{$carol}/role", ['role' => 'admin'], actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testCannotSetOwnerRole(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);
        $res = $this->request('PUT', "/groups/{$groupId}/members/{$bob}/role", ['role' => 'owner'], actorId: (string) $alice);

        $this->assertSame(422, $res->getStatusCode());
    }

    // --- Isolation ---

    public function testGroupsAreIsolated(): void
    {
        $alice    = $this->createUser('Alice');
        $bob      = $this->createUser('Bob');
        $group1Id = $this->createGroup($alice, 'Group 1');
        $group2Id = $this->createGroup($bob, 'Group 2');

        // Alice is not a member of Group 2
        $res = $this->request('GET', "/groups/{$group2Id}/members", actorId: (string) $alice);
        $this->assertSame(403, $res->getStatusCode());
    }
}
