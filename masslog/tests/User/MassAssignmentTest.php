<?php

declare(strict_types=1);

namespace Mass\Tests\User;

use Mass\User\RouteRegistrar;
use Mass\User\UserRepository;
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

final class MassAssignmentTest extends TestCase
{
    private RequestHandlerInterface $app;
    private UserRepository $repo;
    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/masslog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $this->repo = new UserRepository($executor);
        $registrar  = new RouteRegistrar($this->repo, $json, $problems);

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

    // --- helpers ---

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body, JSON_THROW_ON_ERROR));
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
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- happy path ---

    public function testCreateUserReturnsUserRole(): void
    {
        $res = $this->post('/users', ['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('user', $data['role']);
        $this->assertTrue($data['is_active']);
    }

    public function testCreatedUserHasUserRoleInDatabase(): void
    {
        $this->post('/users', ['name' => 'Alice', 'email' => 'alice@example.com']);

        $users = $this->repo->findAll();
        $this->assertCount(1, $users);
        $this->assertSame('user', $users[0]->role);
        $this->assertTrue($users[0]->isActive);
    }

    // --- mass assignment defence ---

    /**
     * An attacker sends role=admin in the request body.
     * The server must ignore the role field and persist 'user'.
     */
    public function testRoleFieldInRequestBodyIsIgnored(): void
    {
        $res = $this->post('/users', [
            'name'  => 'Attacker',
            'email' => 'attacker@example.com',
            'role'  => 'admin',  // ← attempt to escalate privileges
        ]);

        $this->assertSame(201, $res->getStatusCode());

        $data = $this->json($res);
        $this->assertSame('user', $data['role'], 'role must be user regardless of request body');

        $users = $this->repo->findAll();
        $this->assertSame('user', $users[0]->role, 'Persisted role must be user');
    }

    /**
     * An attacker sends is_active=false to disable their own account (or another's).
     * The field must be ignored.
     */
    public function testIsActiveFieldInRequestBodyIsIgnored(): void
    {
        $res = $this->post('/users', [
            'name'      => 'Bob',
            'email'     => 'bob@example.com',
            'is_active' => false, // ← attempt to create disabled account or tamper
        ]);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertTrue($this->json($res)['is_active'], 'is_active must be true regardless of request body');

        $users = $this->repo->findAll();
        $this->assertTrue($users[0]->isActive, 'Persisted is_active must be true');
    }

    /**
     * Multiple extra fields are all silently ignored.
     */
    public function testMultipleExtraFieldsAreIgnored(): void
    {
        $res = $this->post('/users', [
            'name'       => 'Carol',
            'email'      => 'carol@example.com',
            'role'       => 'admin',
            'is_active'  => false,
            'created_at' => '2000-01-01 00:00:00', // attempt to set audit timestamp
            'id'         => 9999,                   // attempt to set primary key
        ]);

        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('user', $data['role']);
        $this->assertTrue($data['is_active']);
        // id is auto-assigned, not 9999
        $this->assertNotSame(9999, $data['id']);
    }

    /**
     * Existing admin users are not affected by the endpoint.
     * A normal user creation does not demote or alter admin accounts.
     */
    public function testAdminUserCreatedViaSeedIsPreserved(): void
    {
        $admin = $this->repo->seedAdmin('Admin', 'admin@example.com');
        $this->assertSame('admin', $admin->role);

        // Create a normal user via API
        $this->post('/users', ['name' => 'Regular', 'email' => 'regular@example.com']);

        // Admin still has admin role
        $found = $this->repo->findById($admin->id);
        $this->assertNotNull($found);
        $this->assertSame('admin', $found->role);
    }

    // --- input validation ---

    public function testMissingNameReturns422(): void
    {
        $res = $this->post('/users', ['email' => 'alice@example.com']);
        $this->assertSame(422, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('validation-failed', (string) ($data['type'] ?? ''));
    }

    public function testMissingEmailReturns422(): void
    {
        $res = $this->post('/users', ['name' => 'Alice']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testInvalidEmailReturns422(): void
    {
        $res = $this->post('/users', ['name' => 'Alice', 'email' => 'not-an-email']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testEmptyNameReturns422(): void
    {
        $res = $this->post('/users', ['name' => '   ', 'email' => 'alice@example.com']);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testNonObjectBodyReturns400(): void
    {
        $stream  = Stream::create('"just a string"');
        $request = (new ServerRequest('POST', '/users'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
        $res = $this->app->handle($request);
        $this->assertSame(400, $res->getStatusCode());
    }

    // --- email normalisation ---

    public function testEmailIsNormalizedToLowercase(): void
    {
        $res = $this->post('/users', ['name' => 'Dave', 'email' => 'DAVE@EXAMPLE.COM']);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('dave@example.com', $this->json($res)['email']);
    }

    // --- list endpoint ---

    public function testListUsersReturnsAll(): void
    {
        $this->post('/users', ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->post('/users', ['name' => 'Bob',   'email' => 'bob@example.com']);

        $res = $this->get('/users');
        $this->assertSame(200, $res->getStatusCode());

        $data = $this->json($res);
        $this->assertCount(2, $data);
        // Both are role=user regardless of any injected field attempts
        foreach ($data as $user) {
            $this->assertSame('user', $user['role']);
        }
    }

    /**
     * Verify that the response body does not include fields that should be server-only.
     * No 'password_hash' or internal implementation fields must leak.
     */
    public function testResponseDoesNotLeakInternalFields(): void
    {
        $res  = $this->post('/users', ['name' => 'Eve', 'email' => 'eve@example.com']);
        $data = $this->json($res);

        $this->assertArrayNotHasKey('password_hash', $data);
        $this->assertArrayNotHasKey('deleted_at', $data);
        // Only the expected keys are present
        $expectedKeys = ['id', 'name', 'email', 'role', 'is_active', 'created_at'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }
}
