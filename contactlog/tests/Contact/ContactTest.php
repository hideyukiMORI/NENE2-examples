<?php

declare(strict_types=1);

namespace ContactLog\Tests\Contact;

use ContactLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class ContactTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $pdo->exec($schema);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, mixed> $query */
    private function req(string $method, string $path, mixed $body = null, array $query = []): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        if ($query !== []) {
            $request = $request->withQueryParams($query);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        assert(is_array($decoded));
        return $decoded;
    }

    private function contact(string $owner, string $name, string $email = ''): int
    {
        $res = $this->req('POST', "/owners/{$owner}/contacts", ['name' => $name, 'email' => $email]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    private function group(string $owner, string $name): int
    {
        $res = $this->req('POST', "/owners/{$owner}/groups", ['name' => $name]);
        assert($res->getStatusCode() === 201);
        return (int) $this->json($res)['id'];
    }

    // ── CRUD + ownership ─────────────────────────────────────────────────────

    public function testCreateAndGet(): void
    {
        $id = $this->contact('alice', 'Bob', 'bob@x.test');
        $data = $this->json($this->req('GET', "/owners/alice/contacts/{$id}"));
        $this->assertSame('Bob', $data['name']);
        $this->assertSame([], $data['groups']);
    }

    public function testContactsAreOwnerScoped(): void
    {
        $id = $this->contact('alice', 'Bob');
        // bob cannot read alice's contact
        $this->assertSame(404, $this->req('GET', "/owners/bob/contacts/{$id}")->getStatusCode());
    }

    public function testUpdateContact(): void
    {
        $id = $this->contact('alice', 'Bob');
        $res = $this->req('PUT', "/owners/alice/contacts/{$id}", ['name' => 'Bobby', 'phone' => '123']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Bobby', $this->json($res)['name']);
        $this->assertSame('123', $this->json($res)['phone']);
    }

    public function testDelete(): void
    {
        $id = $this->contact('alice', 'Bob');
        $this->assertSame(204, $this->req('DELETE', "/owners/alice/contacts/{$id}")->getStatusCode());
        $this->assertSame(404, $this->req('GET', "/owners/alice/contacts/{$id}")->getStatusCode());
    }

    public function testCreateValidation(): void
    {
        $this->assertSame(422, $this->req('POST', '/owners/alice/contacts', ['name' => ''])->getStatusCode());
    }

    // ── search ─────────────────────────────────────────────────────────────────

    public function testSearchByName(): void
    {
        $this->contact('alice', 'Alice Smith');
        $this->contact('alice', 'Bob Jones');
        $data = $this->json($this->req('GET', '/owners/alice/contacts', null, ['q' => 'smith']));
        $this->assertSame(1, $data['count']);
        $this->assertSame('Alice Smith', $data['contacts'][0]['name']);
    }

    public function testSearchEscapesLikeWildcards(): void
    {
        $this->contact('alice', 'Real Name');
        // '%' must match literally, not as a wildcard → no results
        $data = $this->json($this->req('GET', '/owners/alice/contacts', null, ['q' => '%']));
        $this->assertSame(0, $data['count']);
    }

    public function testSearchIsOwnerScoped(): void
    {
        $this->contact('alice', 'Secret');
        $data = $this->json($this->req('GET', '/owners/bob/contacts', null, ['q' => 'Secret']));
        $this->assertSame(0, $data['count']);
    }

    // ── groups ───────────────────────────────────────────────────────────────

    public function testCreateGroupDuplicateConflicts(): void
    {
        $this->group('alice', 'Friends');
        $res = $this->req('POST', '/owners/alice/groups', ['name' => 'Friends']);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testSameGroupNameDifferentOwnersOk(): void
    {
        $this->group('alice', 'Friends');
        $res = $this->req('POST', '/owners/bob/groups', ['name' => 'Friends']);
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testAddToGroupIsIdempotent(): void
    {
        $c = $this->contact('alice', 'Bob');
        $g = $this->group('alice', 'Friends');
        $this->assertSame(204, $this->req('PUT', "/owners/alice/contacts/{$c}/groups/{$g}")->getStatusCode());
        // repeat — still 204, no error, no duplicate
        $this->assertSame(204, $this->req('PUT', "/owners/alice/contacts/{$c}/groups/{$g}")->getStatusCode());

        $data = $this->json($this->req('GET', "/owners/alice/contacts/{$c}"));
        $this->assertSame(['Friends'], array_map(static fn (array $g): string => $g['name'], $data['groups']));
    }

    public function testCannotAddCrossOwnerGroup(): void
    {
        $c = $this->contact('alice', 'Bob');
        $g = $this->group('bob', 'BobGroup');
        // alice's contact + bob's group → 404
        $this->assertSame(404, $this->req('PUT', "/owners/alice/contacts/{$c}/groups/{$g}")->getStatusCode());
    }

    public function testGroupFilter(): void
    {
        $c1 = $this->contact('alice', 'InGroup');
        $this->contact('alice', 'NotInGroup');
        $g = $this->group('alice', 'Team');
        $this->req('PUT', "/owners/alice/contacts/{$c1}/groups/{$g}");

        $data = $this->json($this->req('GET', '/owners/alice/contacts', null, ['group_id' => (string) $g]));
        $this->assertSame(1, $data['count']);
        $this->assertSame('InGroup', $data['contacts'][0]['name']);
    }

    public function testRemoveFromGroup(): void
    {
        $c = $this->contact('alice', 'Bob');
        $g = $this->group('alice', 'Friends');
        $this->req('PUT', "/owners/alice/contacts/{$c}/groups/{$g}");
        $this->assertSame(204, $this->req('DELETE', "/owners/alice/contacts/{$c}/groups/{$g}")->getStatusCode());
        // removing again → 404 (not a member)
        $this->assertSame(404, $this->req('DELETE', "/owners/alice/contacts/{$c}/groups/{$g}")->getStatusCode());
    }
}
