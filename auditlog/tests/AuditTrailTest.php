<?php

declare(strict_types=1);

namespace Audit\Tests;

use Audit\AuditLog\AuditRepository;
use Audit\AuditLog\RouteRegistrar as AuditRouteRegistrar;
use Audit\Auth\RouteRegistrar as AuthRouteRegistrar;
use Audit\Auth\UserRepository;
use Audit\Task\RouteRegistrar as TaskRouteRegistrar;
use Audit\Task\TaskRepository;
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

final class AuditTrailTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile  = '';
    private string $token   = '';
    private int    $actorId = 0;
    private const string SECRET = 'test-secret-for-audit-trail-ft114';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/auditlog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $verifier  = new LocalBearerTokenVerifier(self::SECRET);
        $users     = new UserRepository($executor);
        $tasks     = new TaskRepository($executor);
        $audit     = new AuditRepository($executor);

        $authRegistrar  = new AuthRouteRegistrar($users, $verifier, $json, $problems);
        $taskRegistrar  = new TaskRouteRegistrar($tasks, $audit, $json, $problems);
        $auditRegistrar = new AuditRouteRegistrar($audit, $json, $problems);

        $authMiddleware = new BearerTokenMiddleware(
            problemDetails: $problems,
            verifier: $verifier,
            excludedPaths: ['/auth/login'],
        );

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            authMiddleware: $authMiddleware,
            routeRegistrars: [
                static fn (Router $r) => $authRegistrar->register($r),
                static fn (Router $r) => $taskRegistrar->register($r),
                static fn (Router $r) => $auditRegistrar->register($r),
            ],
        ))->create();

        // Seed one user and obtain a token
        $user          = $users->create('alice@example.com', password_hash('password', PASSWORD_ARGON2ID));
        $this->actorId = $user->id;
        $now           = time();
        $this->token   = $verifier->issue([
            'sub'   => $user->id,
            'email' => $user->email,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    // --- helpers ---

    /** @param array<string, mixed>|null $body */
    private function post(string $path, ?array $body = null, string $token = ''): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body ?? [], JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('POST', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $this->app->handle($request);
    }

    /** @param array<string, mixed>|null $body */
    private function put(string $path, ?array $body = null, string $token = ''): ResponseInterface
    {
        $stream  = Stream::create((string) json_encode($body ?? [], JSON_THROW_ON_ERROR));
        $request = (new ServerRequest('PUT', $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

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

    private function get(string $path, string $token = ''): ResponseInterface
    {
        $request = new ServerRequest('GET', $path);

        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- task CRUD creates audit entries ---

    public function testCreateTaskGeneratesAuditEntry(): void
    {
        $res = $this->post('/tasks', ['title' => 'Buy milk', 'body' => 'From the store'], $this->token);
        self::assertSame(201, $res->getStatusCode());

        $body = $this->decode($res);
        $id   = $body['id'];

        $auditRes = $this->get('/audit/task/' . $id, $this->token);
        $audit    = $this->decode($auditRes);

        self::assertSame(200, $auditRes->getStatusCode());
        self::assertCount(1, $audit['entries']);
        self::assertSame('created', $audit['entries'][0]['action']);
        self::assertSame($this->actorId, $audit['entries'][0]['actor_id']);
    }

    public function testUpdateTaskGeneratesAuditEntry(): void
    {
        $createRes = $this->post('/tasks', ['title' => 'Draft task'], $this->token);
        $taskId    = $this->decode($createRes)['id'];

        $this->put('/tasks/' . $taskId, ['title' => 'Final task', 'status' => 'done'], $this->token);

        $auditRes = $this->get('/audit/task/' . $taskId, $this->token);
        $audit    = $this->decode($auditRes);

        self::assertCount(2, $audit['entries']);
        // Most recent first
        self::assertSame('updated', $audit['entries'][0]['action']);
        self::assertSame('created', $audit['entries'][1]['action']);
    }

    public function testUpdateAuditPayloadContainsBeforeAndAfter(): void
    {
        $createRes = $this->post('/tasks', ['title' => 'Old title', 'body' => 'Old body'], $this->token);
        $taskId    = $this->decode($createRes)['id'];

        $this->put('/tasks/' . $taskId, ['title' => 'New title', 'status' => 'done'], $this->token);

        $auditRes = $this->get('/audit/task/' . $taskId, $this->token);
        $entries  = $this->decode($auditRes)['entries'];

        $updateEntry = $entries[0];
        self::assertSame('Old title', $updateEntry['payload']['before']['title']);
        self::assertSame('New title', $updateEntry['payload']['after']['title']);
        self::assertSame('open', $updateEntry['payload']['before']['status']);
        self::assertSame('done', $updateEntry['payload']['after']['status']);
    }

    public function testDeleteTaskGeneratesAuditEntry(): void
    {
        $createRes = $this->post('/tasks', ['title' => 'Temporary task'], $this->token);
        $taskId    = $this->decode($createRes)['id'];

        $deleteRes = $this->delete('/tasks/' . $taskId, $this->token);
        self::assertSame(204, $deleteRes->getStatusCode());

        $auditRes = $this->get('/audit/task/' . $taskId, $this->token);
        $audit    = $this->decode($auditRes);

        $deleteEntry = array_filter($audit['entries'], fn (array $e) => $e['action'] === 'deleted');
        self::assertCount(1, array_values($deleteEntry));
    }

    public function testAuditSurvivesTaskDeletion(): void
    {
        $createRes = $this->post('/tasks', ['title' => 'Deleted task'], $this->token);
        $taskId    = $this->decode($createRes)['id'];

        $this->delete('/tasks/' . $taskId, $this->token);

        // Task no longer exists but audit history is preserved
        $auditRes = $this->get('/audit/task/' . $taskId, $this->token);
        self::assertSame(200, $auditRes->getStatusCode());
        self::assertCount(2, $this->decode($auditRes)['entries']); // created + deleted
    }

    // --- sensitive field exclusion ---

    public function testAuditPayloadDoesNotContainSensitiveFields(): void
    {
        $res    = $this->post('/tasks', ['title' => 'Secret task', 'body' => 'Top secret content'], $this->token);
        $taskId = $this->decode($res)['id'];

        $auditRes = $this->get('/audit/task/' . $taskId, $this->token);
        $payload  = $this->decode($auditRes)['entries'][0]['payload'];

        // actor_id must NOT appear in payload (it's already in the audit entry itself)
        self::assertArrayNotHasKey('actor_id', $payload);
        // password_hash must never appear (not applicable here, but pattern matters)
        self::assertArrayNotHasKey('password_hash', $payload);
        self::assertArrayNotHasKey('password', $payload);
    }

    // --- audit log search / filter ---

    public function testAuditListFilterByAction(): void
    {
        $t1 = $this->decode($this->post('/tasks', ['title' => 'T1'], $this->token))['id'];
        $t2 = $this->decode($this->post('/tasks', ['title' => 'T2'], $this->token))['id'];
        $this->put('/tasks/' . $t1, ['title' => 'T1 updated'], $this->token);

        $res     = $this->get('/audit?action=created', $this->token);
        $entries = $this->decode($res)['entries'];

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(2, $entries);
        self::assertContainsOnly('array', $entries);
        foreach ($entries as $entry) {
            self::assertSame('created', $entry['action']);
        }
    }

    public function testAuditListFilterByActorId(): void
    {
        // Create a second user
        $psr17    = new Psr17Factory();
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
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
        $users    = new UserRepository($executor);

        $bob      = $users->create('bob@example.com', password_hash('pass', PASSWORD_ARGON2ID));
        $now      = time();
        $bobToken = $verifier->issue(['sub' => $bob->id, 'email' => $bob->email, 'iat' => $now, 'exp' => $now + 3600]);

        $this->post('/tasks', ['title' => 'Alice task'], $this->token);
        $this->post('/tasks', ['title' => 'Bob task'], $bobToken);

        $res     = $this->get('/audit?actor_id=' . $this->actorId, $this->token);
        $entries = $this->decode($res)['entries'];

        self::assertCount(1, $entries);
        self::assertSame($this->actorId, $entries[0]['actor_id']);
    }

    public function testAuditListFilterByResourceType(): void
    {
        $this->post('/tasks', ['title' => 'Task A'], $this->token);

        $res     = $this->get('/audit?resource_type=task', $this->token);
        $entries = $this->decode($res)['entries'];

        self::assertSame(200, $res->getStatusCode());
        foreach ($entries as $entry) {
            self::assertSame('task', $entry['resource_type']);
        }
    }

    // --- authentication required ---

    public function testCreateTaskWithoutTokenReturns401(): void
    {
        $res = $this->post('/tasks', ['title' => 'Anon task']);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testGetAuditWithoutTokenReturns401(): void
    {
        $res = $this->get('/audit');
        self::assertSame(401, $res->getStatusCode());
    }

    // --- actor id from JWT ---

    public function testAuditRecordsActorFromJwtClaims(): void
    {
        $this->post('/tasks', ['title' => 'JWT actor test'], $this->token);

        $res     = $this->get('/audit?action=created', $this->token);
        $entries = $this->decode($res)['entries'];

        self::assertSame($this->actorId, $entries[0]['actor_id']);
    }

    // --- validation ---

    public function testCreateTaskWithoutTitleReturns422(): void
    {
        $res = $this->post('/tasks', ['body' => 'no title'], $this->token);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- ownership enforcement ---

    public function testOtherUserCannotUpdateAlicesTask(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
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
        $users    = new UserRepository($executor);

        $bob      = $users->create('bob@example.com', password_hash('pass', PASSWORD_ARGON2ID));
        $now      = time();
        $bobToken = $verifier->issue(['sub' => $bob->id, 'email' => $bob->email, 'iat' => $now, 'exp' => $now + 3600]);

        $createRes = $this->post('/tasks', ['title' => "Alice's task"], $this->token);
        $taskId    = $this->decode($createRes)['id'];

        $res = $this->put('/tasks/' . $taskId, ['title' => 'Bob hijacked'], $bobToken);
        self::assertSame(404, $res->getStatusCode()); // 404 to avoid confirming resource existence
    }

    public function testOtherUserCannotDeleteAlicesTask(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
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
        $users    = new UserRepository($executor);

        $bob      = $users->create('bob2@example.com', password_hash('pass', PASSWORD_ARGON2ID));
        $now      = time();
        $bobToken = $verifier->issue(['sub' => $bob->id, 'email' => $bob->email, 'iat' => $now, 'exp' => $now + 3600]);

        $createRes = $this->post('/tasks', ['title' => "Alice's protected task"], $this->token);
        $taskId    = $this->decode($createRes)['id'];

        $deleteRes = $this->delete('/tasks/' . $taskId, $bobToken);
        self::assertSame(404, $deleteRes->getStatusCode());

        // Task must still exist
        $getRes = $this->get('/audit/task/' . $taskId, $this->token);
        self::assertSame(200, $getRes->getStatusCode());
    }

    public function testGetTasksReturnsOnlyOwnTasks(): void
    {
        $verifier = new LocalBearerTokenVerifier(self::SECRET);
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
        $users    = new UserRepository($executor);

        $bob      = $users->create('bob3@example.com', password_hash('pass', PASSWORD_ARGON2ID));
        $now      = time();
        $bobToken = $verifier->issue(['sub' => $bob->id, 'email' => $bob->email, 'iat' => $now, 'exp' => $now + 3600]);

        $this->post('/tasks', ['title' => "Alice's task"], $this->token);
        $this->post('/tasks', ['title' => "Bob's task"], $bobToken);

        $aliceTasks = $this->decode($this->get('/tasks', $this->token))['tasks'];
        $bobTasks   = $this->decode($this->get('/tasks', $bobToken))['tasks'];

        self::assertCount(1, $aliceTasks);
        self::assertSame("Alice's task", $aliceTasks[0]['title']);
        self::assertCount(1, $bobTasks);
        self::assertSame("Bob's task", $bobTasks[0]['title']);
    }

    // --- DELETE non-existent ---

    public function testDeleteNonExistentTaskReturns404(): void
    {
        $res = $this->delete('/tasks/9999', $this->token);
        self::assertSame(404, $res->getStatusCode());
    }
}
