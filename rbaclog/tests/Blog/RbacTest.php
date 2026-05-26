<?php

declare(strict_types=1);

namespace Rbac\Tests\Blog;

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
use Rbac\Blog\PostRepository;
use Rbac\Blog\Role;
use Rbac\Blog\RouteRegistrar;
use Rbac\Blog\UserRepository;

final class RbacTest extends TestCase
{
    private RequestHandlerInterface $app;
    private LocalBearerTokenVerifier $verifier;
    private string $dbFile = '';
    private const string SECRET = 'test-secret-for-rbac-field-trial';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/rbaclog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
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
        $posts          = new PostRepository($executor);
        $registrar      = new RouteRegistrar($users, $posts, $this->verifier, $this->verifier, $json, $problems);

        $authMiddleware = new BearerTokenMiddleware(
            problemDetails: $problems,
            verifier: $this->verifier,
            excludedPaths: ['/auth/login', '/posts'],
        );

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            authMiddleware: $authMiddleware,
            routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
        ))->create();

        // Seed test users
        $users->create('user@example.com', password_hash('password', PASSWORD_ARGON2ID), Role::User);
        $users->create('admin@example.com', password_hash('password', PASSWORD_ARGON2ID), Role::Admin);
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

    private function loginAs(string $email): string
    {
        $res  = $this->post('/auth/login', ['email' => $email, 'password' => 'password']);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());

        return (string) $data['token'];
    }

    // --- login: role in JWT claims ---

    public function testLoginTokenContainsRoleClaim(): void
    {
        $token  = $this->loginAs('user@example.com');
        $claims = $this->verifier->verify($token);

        $this->assertSame('user', $claims['role']);
    }

    public function testAdminLoginTokenContainsAdminRole(): void
    {
        $token  = $this->loginAs('admin@example.com');
        $claims = $this->verifier->verify($token);

        $this->assertSame('admin', $claims['role']);
    }

    // --- GET /posts: public ---

    public function testListPostsIsPublic(): void
    {
        $res = $this->get('/posts');
        $this->assertSame(200, $res->getStatusCode());
    }

    // --- POST /posts: any authenticated user ---

    public function testUserCanCreatePost(): void
    {
        $token = $this->loginAs('user@example.com');
        $res   = $this->post('/posts', ['title' => 'Hello', 'body' => 'World'], $token);

        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('Hello', $data['title']);
        $this->assertArrayHasKey('id', $data);
    }

    public function testAdminCanCreatePost(): void
    {
        $token = $this->loginAs('admin@example.com');
        $res   = $this->post('/posts', ['title' => 'Admin Post', 'body' => 'Content'], $token);

        $this->assertSame(201, $res->getStatusCode());
    }

    public function testUnauthenticatedCannotCreatePost(): void
    {
        $res = $this->post('/posts', ['title' => 'Hello', 'body' => 'World']);

        // 401: not authenticated (not 403: authenticated but wrong role)
        $this->assertSame(401, $res->getStatusCode());
    }

    // --- DELETE /posts/{id}: admin only ---

    public function testAdminCanDeletePost(): void
    {
        $userToken  = $this->loginAs('user@example.com');
        $adminToken = $this->loginAs('admin@example.com');

        $createRes = $this->post('/posts', ['title' => 'To Delete', 'body' => 'content'], $userToken);
        $postId    = $this->json($createRes)['id'];

        $res = $this->delete('/posts/' . $postId, $adminToken);
        $this->assertSame(204, $res->getStatusCode());
    }

    public function testUserCannotDeletePost(): void
    {
        $userToken = $this->loginAs('user@example.com');

        $createRes = $this->post('/posts', ['title' => 'My Post', 'body' => 'content'], $userToken);
        $postId    = $this->json($createRes)['id'];

        $res = $this->delete('/posts/' . $postId, $userToken);

        // 403 Forbidden: authenticated but insufficient role (NOT 401)
        $this->assertSame(403, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('forbidden', (string) $data['type']);
    }

    public function testUnauthenticatedCannotDeletePost(): void
    {
        $userToken = $this->loginAs('user@example.com');
        $createRes = $this->post('/posts', ['title' => 'Post', 'body' => 'body'], $userToken);
        $postId    = $this->json($createRes)['id'];

        $res = $this->delete('/posts/' . $postId);

        // 401: not authenticated (not 403)
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testDeleteNonExistentPostReturns404(): void
    {
        $adminToken = $this->loginAs('admin@example.com');
        $res        = $this->delete('/posts/9999', $adminToken);

        $this->assertSame(404, $res->getStatusCode());
    }

    public function testDeletePostWithExpiredTokenReturns401(): void
    {
        // Issue an already-expired admin token
        $expiredToken = $this->verifier->issue([
            'sub'   => 2,
            'email' => 'admin@example.com',
            'role'  => 'admin',
            'iat'   => time() - 7200,
            'exp'   => time() - 1,
        ]);

        $res = $this->delete('/posts/1', $expiredToken);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testCreatePostWithMissingTitleReturns400(): void
    {
        $token = $this->loginAs('user@example.com');
        $res   = $this->post('/posts', ['body' => 'No title'], $token);

        $this->assertSame(400, $res->getStatusCode());
    }

    // --- 401 vs 403 distinction ---

    public function test401MeansNotAuthenticated(): void
    {
        // No token at all → 401
        $res = $this->delete('/posts/1');
        $this->assertSame(401, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('unauthorized', (string) $data['type']);
    }

    public function test403MeansAuthenticatedButForbidden(): void
    {
        // Valid token but wrong role → 403
        $userToken = $this->loginAs('user@example.com');
        $res       = $this->delete('/posts/1', $userToken);
        $this->assertSame(403, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertStringContainsString('forbidden', (string) $data['type']);
    }
}
