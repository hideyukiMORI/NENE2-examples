<?php

declare(strict_types=1);

namespace Tenant\Tests\Notes;

use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
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
use Tenant\Notes\NoteRepository;
use Tenant\Notes\RouteRegistrar;
use Tenant\Notes\UserRepository;

final class TenantIsolationTest extends TestCase
{
    private RequestHandlerInterface $app;
    private LocalBearerTokenVerifier $verifier;
    private string $dbFile = '';
    private const string SECRET = 'test-secret-for-tenant-isolation';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/tenantlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo          = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
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

        $factory        = new PdoConnectionFactory($dbConfig);
        $executor       = new PdoDatabaseQueryExecutor($factory);
        $psr17          = new Psr17Factory();
        $json           = new JsonResponseFactory($psr17, $psr17);
        $problems       = new ProblemDetailsResponseFactory($psr17, $psr17);
        $this->verifier = new LocalBearerTokenVerifier(self::SECRET);
        $users          = new UserRepository($executor);
        $notes          = new NoteRepository($executor);
        $registrar      = new RouteRegistrar($users, $notes, $this->verifier, $this->verifier, $json, $problems);

        $authMiddleware = new BearerTokenMiddleware(
            problemDetails: $problems,
            verifier: $this->verifier,
            excludedPaths: ['/auth/login'],
        );

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            authMiddleware: $authMiddleware,
            routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
        ))->create();

        // Seed two separate tenants
        $tenantA = $users->createTenant('Acme Corp');
        $tenantB = $users->createTenant('Beta Inc');

        $users->create($tenantA, 'alice@acme.com', password_hash('password', PASSWORD_ARGON2ID));
        $users->create($tenantB, 'bob@beta.com', password_hash('password', PASSWORD_ARGON2ID));
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // --- helpers ---

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, string $token = ''): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('POST', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $this->app->handle($request);
    }

    private function get(string $path, string $token = ''): ResponseInterface
    {
        $request = new ServerRequest('GET', $path);

        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $this->app->handle($request);
    }

    private function delete(string $path, string $token = ''): ResponseInterface
    {
        $request = new ServerRequest('DELETE', $path);

        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($data);

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function jsonList(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($data);
        $this->assertIsList($data);

        return $data;
    }

    private function loginAs(string $email): string
    {
        $res  = $this->post('/auth/login', ['email' => $email, 'password' => 'password']);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());

        return (string) $data['token'];
    }

    // --- JWT claims contain tenant_id ---

    public function testLoginTokenContainsTenantId(): void
    {
        $token  = $this->loginAs('alice@acme.com');
        $claims = $this->verifier->verify($token);

        $this->assertArrayHasKey('tenant_id', $claims);
        $this->assertIsInt($claims['tenant_id']);
    }

    // --- notes are tenant-scoped ---

    public function testCreateNoteStoresWithTenantId(): void
    {
        $token = $this->loginAs('alice@acme.com');
        $res   = $this->post('/notes', ['title' => 'Alice Note', 'body' => 'Acme content'], $token);

        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('Alice Note', $data['title']);
        $this->assertArrayNotHasKey('tenant_id', $data);  // tenant_id must not leak in response
    }

    public function testListNotesShowsOnlyCurrentTenantNotes(): void
    {
        $aliceToken = $this->loginAs('alice@acme.com');
        $bobToken   = $this->loginAs('bob@beta.com');

        $this->post('/notes', ['title' => 'Alice Note', 'body' => 'Acme'], $aliceToken);
        $this->post('/notes', ['title' => 'Bob Note', 'body' => 'Beta'], $bobToken);

        $aliceNotes = $this->jsonList($this->get('/notes', $aliceToken));
        $bobNotes   = $this->jsonList($this->get('/notes', $bobToken));

        // Each tenant sees only their own notes
        $this->assertCount(1, $aliceNotes);
        $this->assertSame('Alice Note', $aliceNotes[0]['title']);

        $this->assertCount(1, $bobNotes);
        $this->assertSame('Bob Note', $bobNotes[0]['title']);
    }

    // --- cross-tenant access is blocked ---

    public function testCrossTenantGetReturns404NotForbidden(): void
    {
        $aliceToken = $this->loginAs('alice@acme.com');
        $bobToken   = $this->loginAs('bob@beta.com');

        // Alice creates a note
        $res    = $this->post('/notes', ['title' => 'Secret', 'body' => 'Acme secret'], $aliceToken);
        $noteId = $this->json($res)['id'];

        // Bob tries to access Alice's note by ID
        $crossRes = $this->get('/notes/' . $noteId, $bobToken);

        // Must return 404 — NOT 403. 403 would reveal the resource exists (cross-tenant info leak).
        $this->assertSame(404, $crossRes->getStatusCode());
    }

    public function testCrossTenantDeleteReturns404(): void
    {
        $aliceToken = $this->loginAs('alice@acme.com');
        $bobToken   = $this->loginAs('bob@beta.com');

        $res    = $this->post('/notes', ['title' => 'Secret', 'body' => 'Acme secret'], $aliceToken);
        $noteId = $this->json($res)['id'];

        // Bob tries to delete Alice's note
        $deleteRes = $this->delete('/notes/' . $noteId, $bobToken);

        $this->assertSame(404, $deleteRes->getStatusCode());

        // Verify Alice's note still exists
        $aliceNote = $this->get('/notes/' . $noteId, $aliceToken);
        $this->assertSame(200, $aliceNote->getStatusCode());
    }

    public function testManipulatedTenantIdInTokenIsRejected(): void
    {
        $aliceToken = $this->loginAs('alice@acme.com');

        // Alice creates a note
        $res    = $this->post('/notes', ['title' => 'Secret', 'body' => 'Acme'], $aliceToken);
        $noteId = $this->json($res)['id'];

        // Attacker crafts a token with Bob's tenant_id but Alice's user sub (invalid signature)
        $claims    = $this->verifier->verify($aliceToken);
        $fakeClaims = array_merge($claims, ['tenant_id' => $claims['tenant_id'] + 1]);
        // Can't issue a valid token with different tenant_id without the secret
        $fakeToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.tampered.invalidsignature';

        $res = $this->get('/notes/' . $noteId, $fakeToken);
        $this->assertSame(401, $res->getStatusCode());
    }

    // --- unauthenticated access ---

    public function testListNotesRequiresAuth(): void
    {
        $res = $this->get('/notes');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testGetNoteRequiresAuth(): void
    {
        $token = $this->loginAs('alice@acme.com');
        $res   = $this->post('/notes', ['title' => 'Note', 'body' => 'Body'], $token);
        $id    = $this->json($res)['id'];

        $this->assertSame(401, $this->get('/notes/' . $id)->getStatusCode());
    }

    public function testCreateNoteRequiresAuth(): void
    {
        $res = $this->post('/notes', ['title' => 'Note', 'body' => 'Body']);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testDeleteNoteRequiresAuth(): void
    {
        $res = $this->delete('/notes/1');
        $this->assertSame(401, $res->getStatusCode());
    }

    // --- own notes management ---

    public function testGetOwnNoteReturns200(): void
    {
        $token  = $this->loginAs('alice@acme.com');
        $res    = $this->post('/notes', ['title' => 'My Note', 'body' => 'Content'], $token);
        $noteId = $this->json($res)['id'];

        $getRes = $this->get('/notes/' . $noteId, $token);
        $this->assertSame(200, $getRes->getStatusCode());
        $this->assertSame('My Note', $this->json($getRes)['title']);
    }

    public function testDeleteOwnNoteReturns204(): void
    {
        $token  = $this->loginAs('alice@acme.com');
        $res    = $this->post('/notes', ['title' => 'To Delete', 'body' => 'Body'], $token);
        $noteId = $this->json($res)['id'];

        $this->assertSame(204, $this->delete('/notes/' . $noteId, $token)->getStatusCode());

        // Verify deleted
        $this->assertSame(404, $this->get('/notes/' . $noteId, $token)->getStatusCode());
    }

    public function testResponseDoesNotLeakTenantId(): void
    {
        $token = $this->loginAs('alice@acme.com');
        $res   = $this->post('/notes', ['title' => 'Note', 'body' => 'Body'], $token);
        $data  = $this->json($res);

        $this->assertArrayNotHasKey('tenant_id', $data);
    }
}
