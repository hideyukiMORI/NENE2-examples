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
 * FT138 Vulnerability Assessment
 */
final class VulnTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/grouplog-vuln-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $req = new ServerRequest('POST', '/users');
        $req = $req->withBody(Stream::create((string) json_encode(['name' => $name])))
                   ->withHeader('Content-Type', 'application/json');

        return (int) $this->json($this->app->handle($req))['id'];
    }

    private function createGroup(int $ownerId, string $name = 'Group'): int
    {
        $res = $this->request('POST', '/groups', ['name' => $name], actorId: (string) $ownerId);

        return (int) $this->json($res)['id'];
    }

    // VULN-A: IDOR — non-member reads group members
    public function testVulnA_NonMemberCannotListMembers(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $res = $this->request('GET', "/groups/{$groupId}/members", actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-A: non-member must not see member list');
    }

    // VULN-B: IDOR — non-member tries to add someone to a group
    public function testVulnB_NonMemberCannotAddMember(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $carol   = $this->createUser('Carol');
        $groupId = $this->createGroup($alice);

        $res = $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $carol, 'role' => 'member'], actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-B: non-member must not add members');
    }

    // VULN-C: Role escalation — regular member tries to add a member
    public function testVulnC_RegularMemberCannotAddMember(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $carol   = $this->createUser('Carol');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);

        $res = $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $carol, 'role' => 'member'], actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-C: regular member must not add others');
    }

    // VULN-D: Role escalation — admin tries to promote to owner
    public function testVulnD_AdminCannotAssignOwnerRole(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $carol   = $this->createUser('Carol');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'admin'], actorId: (string) $alice);
        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $carol, 'role' => 'member'], actorId: (string) $alice);

        // Admin tries to change Carol to owner
        $res = $this->request('PUT', "/groups/{$groupId}/members/{$carol}/role", ['role' => 'owner'], actorId: (string) $bob);
        $this->assertNotSame(200, $res->getStatusCode(), 'VULN-D: admin must not promote to owner');
    }

    // VULN-E: Role escalation — regular member tries to promote self to admin
    public function testVulnE_MemberCannotPromoteSelf(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'member'], actorId: (string) $alice);

        $res = $this->request('PUT', "/groups/{$groupId}/members/{$bob}/role", ['role' => 'admin'], actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-E: member must not promote themselves');
    }

    // VULN-F: Owner removal attempt
    public function testVulnF_CannotRemoveOwner(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'admin'], actorId: (string) $alice);

        // Admin tries to remove the owner
        $res = $this->request('DELETE', "/groups/{$groupId}/members/{$alice}", actorId: (string) $bob);
        $this->assertSame(422, $res->getStatusCode(), 'VULN-F: owner must not be removable');
    }

    // VULN-G: Missing X-User-Id on create group
    public function testVulnG_MissingActorOnCreateGroup(): void
    {
        $res = $this->request('POST', '/groups', ['name' => 'Ghost Group']);
        $this->assertNotSame(201, $res->getStatusCode(), 'VULN-G: missing actor must not create group');
    }

    // VULN-H: Non-numeric X-User-Id
    public function testVulnH_NonNumericActor(): void
    {
        $alice   = $this->createUser('Alice');
        $groupId = $this->createGroup($alice);

        $res = $this->request('GET', "/groups/{$groupId}/members", actorId: 'admin');
        $this->assertNotSame(200, $res->getStatusCode(), 'VULN-H: non-numeric actor header must be rejected');
    }

    // VULN-I: SQL injection in group name
    public function testVulnI_SqlInjectionInGroupName(): void
    {
        $alice   = $this->createUser('Alice');
        $payload = "'; DROP TABLE groups; --";

        $res  = $this->request('POST', '/groups', ['name' => $payload], actorId: (string) $alice);
        $body = $this->json($res);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame($payload, $body['name'], 'VULN-I: SQL injection in name must be stored verbatim');

        // Groups table must still be intact
        $listRes = $this->request('GET', "/groups/{$body['id']}/members", actorId: (string) $alice);
        $this->assertSame(200, $listRes->getStatusCode());
    }

    // VULN-J: Cross-group member operation
    public function testVulnJ_CannotRemoveMemberFromDifferentGroup(): void
    {
        $alice    = $this->createUser('Alice');
        $bob      = $this->createUser('Bob');
        $carol    = $this->createUser('Carol');
        $group1Id = $this->createGroup($alice, 'Group 1');
        $group2Id = $this->createGroup($bob, 'Group 2');

        $this->request('POST', "/groups/{$group2Id}/members", ['user_id' => $carol, 'role' => 'member'], actorId: (string) $bob);

        // Alice (owner of group1, not group2) tries to remove Carol from group2
        $res = $this->request('DELETE', "/groups/{$group2Id}/members/{$carol}", actorId: (string) $alice);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-J: owner of different group must not remove members');
    }

    // VULN-K: Negative group ID
    public function testVulnK_NegativeGroupId(): void
    {
        $alice = $this->createUser('Alice');
        $res   = $this->request('GET', '/groups/-1/members', actorId: (string) $alice);
        $this->assertSame(404, $res->getStatusCode(), 'VULN-K: negative group ID must return 404');
    }

    // VULN-L: Admin cannot change roles (only owner can)
    public function testVulnL_AdminCannotChangeRoles(): void
    {
        $alice   = $this->createUser('Alice');
        $bob     = $this->createUser('Bob');
        $carol   = $this->createUser('Carol');
        $groupId = $this->createGroup($alice);

        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $bob, 'role' => 'admin'], actorId: (string) $alice);
        $this->request('POST', "/groups/{$groupId}/members", ['user_id' => $carol, 'role' => 'member'], actorId: (string) $alice);

        $res = $this->request('PUT', "/groups/{$groupId}/members/{$carol}/role", ['role' => 'admin'], actorId: (string) $bob);
        $this->assertSame(403, $res->getStatusCode(), 'VULN-L: admin must not change roles');
    }
}
